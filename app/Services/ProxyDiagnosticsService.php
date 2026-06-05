<?php

namespace App\Services;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Support\IngestHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Агрегирует «три светофора» здоровья прокси: HTTP (ipify), WebSocket (ingest), Payout PO.
 */
class ProxyDiagnosticsService
{
    public const INGEST_SILENT_MINUTES = IngestHealth::INGEST_SILENT_MINUTES;

    public const WS_RECENT_FAIL_MINUTES = IngestHealth::WS_RECENT_FAIL_MINUTES;

    /**
     * @return array{
     *   filter: string,
     *   summary: array{total:int,shown:int,false_dead:int,http_untested:int},
     *   rows: list<array<string, mixed>>
     * }
     */
    public function buildPoolHealth(string $filter = 'all', int $payoutStaleMinutes = 5): array
    {
        $filter = $this->normalizeFilter($filter);
        $payoutStaleMinutes = max(1, min(1440, $payoutStaleMinutes));
        $now = now();

        $proxies = ProxyEndpoint::query()
            ->withCount([
                'instruments as active_instruments_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->orderBy('status')
            ->orderByDesc('failure_count')
            ->orderBy('id')
            ->get();

        $instrumentsByProxy = PocketInstrument::query()
            ->active()
            ->with('proxyEndpoint')
            ->whereNotNull('proxy_endpoint_id')
            ->orderBy('instrument')
            ->get()
            ->groupBy('proxy_endpoint_id');

        $rows = [];
        foreach ($proxies as $proxy) {
            /** @var Collection<int, PocketInstrument> $instruments */
            $instruments = $instrumentsByProxy->get($proxy->id, collect());
            $row = $this->buildProxyRow($proxy, $instruments, $payoutStaleMinutes, $now);
            if ($this->matchesFilter($row, $filter)) {
                $rows[] = $row;
            }
        }

        return [
            'filter' => $filter,
            'summary' => [
                'total' => $proxies->count(),
                'shown' => count($rows),
                'false_dead' => collect($rows)->where('is_false_dead', true)->count(),
                'http_untested' => collect($rows)->where('http.layer', 'unknown')->count(),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Сводка для dashboard: stale payout, silent ingest, dead proxy, ёмкость пула.
     *
     * @return array{
     *   payout_stale_count: int,
     *   ingest_silent_count: int,
     *   on_dead_proxy_count: int,
     *   active_instruments: int,
     *   pool_capacity: array<string, mixed>
     * }
     */
    public function getGlobalIngestHealthSummary(int $payoutStaleMinutes, ProxyRotationService $proxyRotation): array
    {
        $payoutStaleMinutes = max(1, min(1440, $payoutStaleMinutes));
        $now = now();

        $active = PocketInstrument::query()->active()->get();
        $payoutStale = 0;
        $ingestSilent = 0;

        foreach ($active as $instrument) {
            if (IngestHealth::isPayoutEventStale($instrument, $payoutStaleMinutes, $now)) {
                $payoutStale++;
            }
            if (IngestHealth::isIngestSilent($instrument, $now)) {
                $ingestSilent++;
            }
        }

        $onDead = PocketInstrument::query()
            ->active()
            ->whereHas('proxyEndpoint', fn ($q) => $q->where('status', ProxyEndpoint::STATUS_DEAD))
            ->count();

        return [
            'payout_stale_count' => $payoutStale,
            'ingest_silent_count' => $ingestSilent,
            'on_dead_proxy_count' => $onDead,
            'active_instruments' => $active->count(),
            'pool_capacity' => $proxyRotation->getPoolCapacitySummary(),
        ];
    }

    /**
     * Одна строка «Здоровье пула» для AJAX после HTTP-теста.
     *
     * @return array<string, mixed>|null
     */
    public function buildProxyRowById(int $proxyId, int $payoutStaleMinutes = 5): ?array
    {
        $proxy = ProxyEndpoint::query()
            ->withCount([
                'instruments as active_instruments_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->find($proxyId);

        if (! $proxy) {
            return null;
        }

        $instruments = PocketInstrument::query()
            ->active()
            ->where('proxy_endpoint_id', $proxy->id)
            ->orderBy('instrument')
            ->get();

        return $this->buildProxyRow($proxy, $instruments, max(1, min(1440, $payoutStaleMinutes)), now());
    }

    /**
     * @param  Collection<int, PocketInstrument>  $instruments
     * @return array<string, mixed>
     */
    private function buildProxyRow(
        ProxyEndpoint $proxy,
        Collection $instruments,
        int $payoutStaleMinutes,
        Carbon $now,
    ): array {
        $instrumentRows = [];
        $payoutStaleCount = 0;
        $ingestSilentCount = 0;

        foreach ($instruments as $instrument) {
            $ir = $this->buildInstrumentRow($instrument, $payoutStaleMinutes, $now);
            $instrumentRows[] = $ir;
            if ($ir['payout']['is_stale']) {
                $payoutStaleCount++;
            }
            if ($ir['ingest']['is_silent']) {
                $ingestSilentCount++;
            }
        }

        $http = $this->layerHttp($proxy);
        $ws = $this->layerWebSocket($proxy, $instruments, $ingestSilentCount, $now);
        $payout = $this->layerPayoutAggregate($payoutStaleCount, $ingestSilentCount, $instruments->count());

        $isFalseDead = $proxy->status === ProxyEndpoint::STATUS_DEAD
            && $http['layer'] === 'ok';

        return [
            'proxy' => $proxy,
            'proxy_id' => $proxy->id,
            'url' => $proxy->url,
            'status' => $proxy->status,
            'failure_count' => (int) $proxy->failure_count,
            'last_error' => $proxy->last_error,
            'active_instruments_count' => (int) ($proxy->active_instruments_count ?? 0),
            'http' => $http,
            'ws' => $ws,
            'payout' => $payout,
            'is_false_dead' => $isFalseDead,
            'recommendation' => $this->recommendation($http, $ws, $payout, $isFalseDead),
            'instruments' => $instrumentRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInstrumentRow(PocketInstrument $instrument, int $payoutStaleMinutes, Carbon $now): array
    {
        $eventAt = $instrument->last_payout_event_at ?? $instrument->last_payout_at;
        $payoutStale = IngestHealth::isPayoutEventStale($instrument, $payoutStaleMinutes, $now);
        $ingestSilent = IngestHealth::isIngestSilent($instrument, $now);
        $wsState = $this->instrumentWsState($instrument, $now);

        return [
            'instrument' => $instrument->instrument,
            'current_payout' => $instrument->current_payout,
            'payout_source' => $instrument->payout_source,
            'payout' => [
                'is_stale' => $payoutStale,
                'event_at' => $eventAt,
                'is_stale_flag' => (bool) $instrument->payout_is_stale,
            ],
            'ingest' => [
                'is_silent' => $ingestSilent,
                'posted_at' => $instrument->last_payout_at,
            ],
            'ws' => [
                'state' => $wsState,
                'connected_at' => $instrument->last_ws_connected_at,
                'error' => $instrument->last_ws_error,
                'error_at' => $instrument->last_ws_error_at,
            ],
        ];
    }

    /**
     * @return 'ok'|'fail'|'silent'|'unknown'
     */
    private function instrumentWsState(PocketInstrument $instrument, Carbon $now): string
    {
        $connectedAt = $instrument->last_ws_connected_at;
        $failedAt = $instrument->last_ws_error_at;
        $recentConnected = $connectedAt
            && $connectedAt->gte($now->copy()->subMinutes(IngestHealth::WS_RECENT_CONNECTED_MINUTES));
        $recentFailed = $failedAt
            && $failedAt->gte($now->copy()->subMinutes(IngestHealth::WS_RECENT_FAIL_MINUTES));

        if ($recentConnected && (! $recentFailed || $connectedAt->gte($failedAt))) {
            return 'ok';
        }

        if ($recentFailed) {
            return 'fail';
        }

        if (IngestHealth::isIngestSilent($instrument, $now)) {
            return 'silent';
        }

        return 'unknown';
    }

    /**
     * @return array{layer: string, label: string, detail: string}
     */
    private function layerHttp(ProxyEndpoint $proxy): array
    {
        $result = $proxy->last_http_check_result;
        if (! $result || ! $proxy->last_http_check_at) {
            return [
                'layer' => 'unknown',
                'label' => 'не тестировали',
                'detail' => 'Нажмите «Быстрый тест» — результат сохранится в БД.',
            ];
        }

        $latency = $proxy->last_http_latency_ms;
        $code = $proxy->last_http_status;
        $mode = $proxy->last_http_check_mode ?? 'quick';
        $checked = $proxy->last_http_check_at->timezone(config('app.timezone'))->format('d.m H:i');

        return [
            'layer' => $result,
            'label' => match ($result) {
                'ok' => 'HTTP ok',
                'warn' => 'HTTP медленно',
                default => 'HTTP fail',
            },
            'detail' => sprintf(
                '%s; code=%s; %sms; проверен %s (%s)',
                $proxy->last_http_check_result,
                $code ?? '—',
                $latency ?? '—',
                $checked,
                $mode
            ),
        ];
    }

    /**
     * @param  Collection<int, PocketInstrument>  $instruments
     * @return array{layer: string, label: string, detail: string}
     */
    private function layerWebSocket(
        ProxyEndpoint $proxy,
        Collection $instruments,
        int $ingestSilentCount,
        Carbon $now,
    ): array {
        if ($instruments->isEmpty()) {
            return [
                'layer' => 'unknown',
                'label' => 'нет инструментов',
                'detail' => 'На endpoint не назначены активные инструменты.',
            ];
        }

        $total = $instruments->count();
        $wsOk = 0;
        $wsFail = 0;
        $wsSilent = 0;

        foreach ($instruments as $instrument) {
            match ($this->instrumentWsState($instrument, $now)) {
                'ok' => $wsOk++,
                'fail' => $wsFail++,
                'silent' => $wsSilent++,
                default => null,
            };
        }

        if ($wsFail === $total) {
            return [
                'layer' => 'fail',
                'label' => 'WS fail',
                'detail' => "Все {$total} инстр.: недавний connect_failed / not_connected (ingest-proxy-health).",
            ];
        }

        if ($wsSilent === $total || ($wsSilent + $wsFail === $total && $wsOk === 0)) {
            return [
                'layer' => 'fail',
                'label' => 'ingest молчит',
                'detail' => sprintf(
                    'Нет свежего WS connected и POST ingest > %d мин (проверьте воркер).',
                    self::INGEST_SILENT_MINUTES
                ),
            ];
        }

        if ($wsOk === $total) {
            return [
                'layer' => 'ok',
                'label' => 'WS ok',
                'detail' => "Все {$total} инстр.: недавний connected к Pocket Options через прокси.",
            ];
        }

        if ($wsFail > 0 || $wsSilent > 0) {
            return [
                'layer' => 'warn',
                'label' => 'WS частично',
                'detail' => sprintf(
                    'WS ok=%d, fail=%d, silent/нет данных=%d; раскройте инструменты.',
                    $wsOk,
                    $wsFail,
                    $total - $wsOk - $wsFail
                ),
            ];
        }

        return [
            'layer' => 'warn',
            'label' => 'WS ?',
            'detail' => 'Нет недавних событий ingest-proxy-health — перезапустите ingest или дождитесь connect.',
        ];
    }

    /**
     * @return array{layer: string, label: string, detail: string}
     */
    private function layerPayoutAggregate(int $staleCount, int $ingestSilentCount, int $total): array
    {
        if ($total === 0) {
            return [
                'layer' => 'unknown',
                'label' => '—',
                'detail' => 'Нет активных инструментов.',
            ];
        }

        if ($staleCount === $total) {
            return [
                'layer' => 'fail',
                'label' => 'PO stale',
                'detail' => "Все {$total} инструмент(ов): нет свежего события payout от PO.",
            ];
        }

        if ($staleCount > 0 || $ingestSilentCount > 0) {
            return [
                'layer' => 'warn',
                'label' => 'PO / ingest',
                'detail' => "stale PO: {$staleCount}/{$total}; ingest silent: {$ingestSilentCount}/{$total}.",
            ];
        }

        return [
            'layer' => 'ok',
            'label' => 'PO ok',
            'detail' => "События payout и POST ingest свежие ({$total} инструмент(ов)).",
        ];
    }

    /**
     * @param  array{layer: string}  $http
     * @param  array{layer: string}  $ws
     * @param  array{layer: string}  $payout
     */
    private function recommendation(array $http, array $ws, array $payout, bool $isFalseDead): string
    {
        if ($isFalseDead) {
            return 'Ложный dead: HTTP ok — рассмотрите «Реанимировать» и round-robin, не списывайте только из‑за payout.';
        }

        if ($http['layer'] === 'fail') {
            return 'HTTP не проходит — обновить прокси из Webshare или заменить endpoint (407/auth).';
        }

        if ($http['layer'] === 'ok' && $ws['layer'] === 'fail') {
            return 'HTTP ok, WebSocket fail — ротация имеет смысл; dead только по payout — ошибка.';
        }

        if ($http['layer'] === 'ok' && $ws['layer'] === 'ok' && $payout['layer'] === 'fail') {
            return 'Связь жива, payout от PO stale — проверьте symbol/session, не обязательно менять прокси.';
        }

        if ($ws['layer'] === 'warn' || $payout['layer'] === 'warn') {
            return 'Частичная деградация — раскройте инструменты ниже, смотрите колонки в «Инструменты».';
        }

        if ($http['layer'] === 'unknown') {
            return 'Сначала быстрый HTTP-тест, затем решение по WS/payout.';
        }

        return 'Прокси выглядит здоровым по доступным метрикам.';
    }

    private function normalizeFilter(string $filter): string
    {
        return match ($filter) {
            'all', 'active', 'problems', 'false_dead' => $filter,
            default => 'all',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesFilter(array $row, string $filter): bool
    {
        return match ($filter) {
            'all' => true,
            'active' => in_array($row['status'], [ProxyEndpoint::STATUS_ACTIVE, ProxyEndpoint::STATUS_COOLDOWN], true),
            'false_dead' => (bool) ($row['is_false_dead'] ?? false),
            'problems' => $this->hasProblem($row),
            default => $this->hasProblem($row),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasProblem(array $row): bool
    {
        if ($row['is_false_dead'] ?? false) {
            return true;
        }

        foreach (['http', 'ws', 'payout'] as $key) {
            $layer = $row[$key]['layer'] ?? 'unknown';
            if (in_array($layer, ['fail', 'warn'], true)) {
                return true;
            }
        }

        return ($row['active_instruments_count'] ?? 0) > 0
            && in_array($row['status'], [ProxyEndpoint::STATUS_DEAD, ProxyEndpoint::STATUS_COOLDOWN], true);
    }

    public function persistHttpCheckResult(
        ProxyEndpoint $proxy,
        string $result,
        ?int $httpStatus,
        ?int $latencyMs,
        string $mode,
    ): void {
        $proxy->update([
            'last_http_check_at' => now(),
            'last_http_status' => $httpStatus,
            'last_http_latency_ms' => $latencyMs,
            'last_http_check_result' => $result,
            'last_http_check_mode' => $mode,
        ]);
    }
}
