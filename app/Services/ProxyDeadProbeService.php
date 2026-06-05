<?php

namespace App\Services;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Support\IngestHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Авто-проверка dead-прокси через временное назначение контрольного инструмента.
 *
 * Ingest читает proxy_url из /api/proxy/instruments; при смене proxy_endpoint_id воркер
 * перезапускается после следующего fetch (до INSTRUMENTS_REFRESH_SEC). Поэтому на время
 * пробы блокируем авто-ротацию для этого инструмента и ждём окно probe_window_sec.
 */
class ProxyDeadProbeService
{
    public const SETTING_AUTO_TEST_ENABLED = 'proxy_auto_test_enabled';

    /** Тест по кругу всех endpoint (active/cooldown/dead), взаимоисключимо с dead-only. */
    public const SETTING_AUTO_TEST_ALL_ENABLED = 'proxy_auto_test_all_enabled';

    public const SETTING_PROBE_INSTRUMENT = 'proxy_reactivation_probe_instrument';

    public const SETTING_PROBE_WINDOW_SEC = 'proxy_probe_window_sec';

    public const SETTING_CURRENT_PROBE = 'proxy_auto_test_current';

    public const SETTING_PROBE_LOCK = 'proxy_auto_probe_lock';

    public const SETTING_LAST_PROXY_ID = 'proxy_probe_last_proxy_id';

    public const DEFAULT_PROBE_WINDOW_SEC = 15;

    /** Запас к окну ожидания: если lock старше — принудительно снимаем (падение воркера). */
    public const STALE_LOCK_EXTRA_SEC = 120;

    public function isAllProxiesAutoTestEnabled(): bool
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_AUTO_TEST_ALL_ENABLED)
            ->value('value');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /** Режим «только dead»; выключен, если включён тест всех прокси. */
    public function isDeadOnlyAutoTestEnabled(): bool
    {
        if ($this->isAllProxiesAutoTestEnabled()) {
            return false;
        }

        $value = DB::table('app_settings')
            ->where('key', self::SETTING_AUTO_TEST_ENABLED)
            ->value('value');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    public function isEnabled(): bool
    {
        return $this->isAllProxiesAutoTestEnabled() || $this->isDeadOnlyAutoTestEnabled();
    }

    public function getProbeScope(): string
    {
        return $this->isAllProxiesAutoTestEnabled() ? 'all' : 'dead_only';
    }

    public function getProbeInstrumentName(): ?string
    {
        $name = trim((string) DB::table('app_settings')
            ->where('key', self::SETTING_PROBE_INSTRUMENT)
            ->value('value'));

        return $name !== '' ? $name : null;
    }

    public function getProbeWindowSec(): int
    {
        $raw = DB::table('app_settings')
            ->where('key', self::SETTING_PROBE_WINDOW_SEC)
            ->value('value');

        $sec = (int) ($raw ?? self::DEFAULT_PROBE_WINDOW_SEC);

        return max(5, min(120, $sec));
    }

    public function getPayoutStaleMinutes(): int
    {
        $raw = DB::table('app_settings')
            ->where('key', 'payout_stale_minutes_threshold')
            ->value('value');

        return max(1, min(1440, (int) ($raw ?? 5)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveLock(): ?array
    {
        $raw = DB::table('app_settings')
            ->where('key', self::SETTING_PROBE_LOCK)
            ->value('value');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function isInstrumentProbeLocked(PocketInstrument $instrument): bool
    {
        $lock = $this->getActiveLock();

        return $lock !== null
            && (int) ($lock['instrument_id'] ?? 0) === (int) $instrument->id;
    }

    /**
     * Во время пробы не трогаем статус dead-прокси, на котором идёт тест (ложные failure от WS).
     */
    public function isProxyUnderProbe(int $proxyId): bool
    {
        $lock = $this->getActiveLock();

        return $lock !== null
            && (int) ($lock['testing_proxy_id'] ?? 0) === $proxyId;
    }

    /**
     * Следующий прокси по кругу (курсор proxy_probe_last_proxy_id).
     * В режиме dead_only — только status=dead; в режиме all — весь пул.
     */
    public function pickNextProbeProxy(): ?ProxyEndpoint
    {
        $lastId = (int) (DB::table('app_settings')
            ->where('key', self::SETTING_LAST_PROXY_ID)
            ->value('value') ?? 0);

        $next = $this->probeCandidateQuery()
            ->when($lastId > 0, fn ($q) => $q->where('id', '>', $lastId))
            ->orderBy('id')
            ->first();

        if (! $next) {
            $next = $this->probeCandidateQuery()->orderBy('id')->first();
        }

        return $next;
    }

    /** @deprecated Используйте pickNextProbeProxy() */
    public function pickNextDeadProxy(): ?ProxyEndpoint
    {
        return $this->pickNextProbeProxy();
    }

    private function probeCandidateQuery()
    {
        $query = ProxyEndpoint::query();
        if (! $this->isAllProxiesAutoTestEnabled()) {
            $query->where('status', ProxyEndpoint::STATUS_DEAD);
        }

        return $query;
    }

    public function advanceCursor(int $proxyId): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_LAST_PROXY_ID],
            [
                'value' => (string) $proxyId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Одна полная проба dead-прокси: временно вешаем контрольный инструмент, ждём PO/WS, восстанавливаем.
     *
     * @return array{status: string, reason: string, instrument?: string, meta?: array<string, bool>}
     */
    public function runSingleProbe(ProxyEndpoint $targetProxy): array
    {
        $probeInstrumentName = $this->getProbeInstrumentName();
        if ($probeInstrumentName === null) {
            return ['status' => 'skip', 'reason' => 'probe_instrument_not_configured'];
        }

        if (! $this->isEnabled()) {
            return ['status' => 'skip', 'reason' => 'auto_test_disabled'];
        }

        $instrument = PocketInstrument::query()
            ->where('instrument', $probeInstrumentName)
            ->where('is_active', true)
            ->first();

        if (! $instrument) {
            $this->saveAutoTestResult($targetProxy->id, [
                'state' => 'done',
                'status' => 'skip',
                'instrument' => $probeInstrumentName,
                'reason' => 'probe_instrument_not_found_or_inactive',
            ]);

            return ['status' => 'skip', 'reason' => 'probe_instrument_not_found_or_inactive', 'instrument' => $probeInstrumentName];
        }

        $payoutStaleMinutes = $this->getPayoutStaleMinutes();
        $originalProxyId = $instrument->proxy_endpoint_id;
        $probeScope = $this->getProbeScope();
        $now = Carbon::now();
        $isAlreadyOnTarget = (int) $originalProxyId === (int) $targetProxy->id;

        // Контрольная пара уже на этом прокси и жива — не сбрасываем метрики (ingest не перезапустит WS).
        if ($isAlreadyOnTarget && $this->isHealthyForReactivation($instrument, $payoutStaleMinutes, $now)) {
            $this->advanceCursor($targetProxy->id);

            return $this->completeProbeSuccess(
                $targetProxy,
                $probeInstrumentName,
                $probeScope,
                $payoutStaleMinutes,
                $instrument,
                'probe_already_healthy_on_proxy',
            );
        }

        // На прокси уже есть другие живые пары — прокси рабочий, не отрываем контрольную пару зря.
        if ($this->targetHasOtherHealthyInstruments($targetProxy, $probeInstrumentName, $payoutStaleMinutes, $now)) {
            $this->advanceCursor($targetProxy->id);

            return $this->completeProbeSuccess(
                $targetProxy,
                $probeInstrumentName,
                $probeScope,
                $payoutStaleMinutes,
                $instrument,
                'proxy_instruments_already_healthy',
            );
        }

        $windowSec = $this->getProbeWindowSec();
        if (! $isAlreadyOnTarget) {
            // Ingest подхватывает смену proxy_url раз в INSTRUMENTS_REFRESH_SEC + время WS.
            $windowSec = max($windowSec, 45);
        }

        $this->setCurrentProbeRunning($targetProxy, $probeInstrumentName, $probeScope);
        $this->saveAutoTestResult($targetProxy->id, [
            'state' => 'running',
            'status' => 'running',
            'instrument' => $probeInstrumentName,
            'reason' => 'temporary_assign_started',
            'meta' => [
                'original_proxy_id' => $originalProxyId,
                'window_sec' => $windowSec,
                'probe_scope' => $probeScope,
            ],
        ]);

        try {
            $this->acquireLock($instrument, $targetProxy->id, $originalProxyId);
            $probeStartedAt = Carbon::now();
            $metricsWereReset = $this->assignInstrumentToProxyForProbe($instrument, $targetProxy->id);

            $healthy = $this->waitForProbeHealth(
                $instrument,
                $windowSec,
                $payoutStaleMinutes,
                $probeStartedAt,
                $metricsWereReset,
            );
            $instrument->refresh();

            if ($healthy) {
                return $this->completeProbeSuccess(
                    $targetProxy,
                    $probeInstrumentName,
                    $probeScope,
                    $payoutStaleMinutes,
                    $instrument,
                    null,
                );
            }

            $failNow = Carbon::now();
            $meta = array_merge(
                $this->healthMeta($instrument, $payoutStaleMinutes, $failNow),
                ['probe_scope' => $probeScope, 'metrics_were_reset' => $metricsWereReset],
            );
            $this->saveAutoTestResult($targetProxy->id, [
                'state' => 'done',
                'status' => 'fail',
                'instrument' => $probeInstrumentName,
                'reason' => 'po_not_healthy',
                'meta' => $meta,
            ]);

            return ['status' => 'fail', 'reason' => 'po_not_healthy', 'instrument' => $probeInstrumentName, 'meta' => $meta];
        } finally {
            $this->restoreInstrumentProxy($instrument, $originalProxyId);
            $this->releaseLock();
            $this->setCurrentProbeIdle();
            $this->advanceCursor($targetProxy->id);
        }
    }

    /**
     * Успешная проба: dead → active; остальные статусы только фиксируют last_success_at.
     */
    private function applyProbeSuccess(ProxyEndpoint $proxy, Carbon $now): string
    {
        if ($proxy->status === ProxyEndpoint::STATUS_DEAD) {
            ProxyEndpoint::query()->whereKey($proxy->id)->update([
                'status' => ProxyEndpoint::STATUS_ACTIVE,
                'failure_count' => 0,
                'last_error' => null,
                'last_failed_at' => null,
                'cooldown_until' => null,
                'last_success_at' => $now,
                'updated_at' => $now,
            ]);

            return 'reactivated_by_auto_probe';
        }

        ProxyEndpoint::query()->whereKey($proxy->id)->update([
            'last_success_at' => $now,
            'updated_at' => $now,
        ]);

        return 'probe_po_ws_ok';
    }

    public function releaseStaleLock(): void
    {
        $lock = $this->getActiveLock();
        if ($lock === null) {
            return;
        }

        $startedAt = isset($lock['started_at']) ? Carbon::parse($lock['started_at']) : null;
        $maxAge = $this->getProbeWindowSec() + self::STALE_LOCK_EXTRA_SEC;

        if ($startedAt === null || $startedAt->lt(now()->subSeconds($maxAge))) {
            $this->forceReleaseLock();
        }
    }

    /** Аварийное снятие lock (ошибка воркера / зависший процесс). */
    public function forceReleaseLock(): void
    {
        $lock = $this->getActiveLock();
        if ($lock === null) {
            return;
        }

        $instrumentId = (int) ($lock['instrument_id'] ?? 0);
        $originalId = $lock['original_proxy_endpoint_id'] ?? null;
        if ($instrumentId > 0) {
            $instrument = PocketInstrument::query()->find($instrumentId);
            if ($instrument) {
                $this->restoreInstrumentProxy(
                    $instrument,
                    $originalId !== null ? (int) $originalId : null
                );
            }
        }
        $this->releaseLock();
        $this->setCurrentProbeIdle();
    }

    public function setCurrentProbeIdle(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_CURRENT_PROBE],
            [
                'value' => json_encode(['state' => 'idle', 'updated_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveAutoTestResult(int $proxyId, array $payload): void
    {
        ProxyEndpoint::query()->whereKey($proxyId)->update([
            'last_auto_test' => array_merge([
                'checked_at' => now()->toIso8601String(),
            ], $payload),
        ]);
    }

    private function setCurrentProbeRunning(ProxyEndpoint $targetProxy, string $instrumentName, string $probeScope): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_CURRENT_PROBE],
            [
                'value' => json_encode([
                    'proxy_id' => $targetProxy->id,
                    'proxy_url' => $targetProxy->url,
                    'proxy_status' => $targetProxy->status,
                    'instrument' => $instrumentName,
                    'probe_scope' => $probeScope,
                    'started_at' => now()->toIso8601String(),
                    'state' => 'running',
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function acquireLock(PocketInstrument $instrument, int $testingProxyId, ?int $originalProxyId): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_PROBE_LOCK],
            [
                'value' => json_encode([
                    'instrument_id' => $instrument->id,
                    'instrument' => $instrument->instrument,
                    'original_proxy_endpoint_id' => $originalProxyId,
                    'testing_proxy_id' => $testingProxyId,
                    'started_at' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function releaseLock(): void
    {
        DB::table('app_settings')->where('key', self::SETTING_PROBE_LOCK)->delete();
    }

    /**
     * Назначает контрольную пару на тестируемый прокси.
     *
     * @return bool true, если метрики сброшены (смена прокси); false — уже на целевом endpoint, метрики не трогаем.
     */
    private function assignInstrumentToProxyForProbe(PocketInstrument $instrument, int $targetProxyId): bool
    {
        if ((int) $instrument->proxy_endpoint_id === $targetProxyId) {
            return false;
        }

        // Сброс только при смене proxy_url: иначе ingest не перезапустит WS, а старые метрики уже недействительны.
        // Не ставим payout_is_stale=true: иначе isPayoutEventStale всегда fail до нового POST,
        // даже когда ingest уже шлёт payout (флаг stale ≠ «прокси мёртв»).
        $instrument->update([
            'proxy_endpoint_id' => $targetProxyId,
            'last_payout_at' => null,
            'last_payout_event_at' => null,
            'payout_is_stale' => false,
            'last_ws_connected_at' => null,
            'last_ws_error' => null,
            'last_ws_error_at' => null,
        ]);

        return true;
    }

    private function targetHasOtherHealthyInstruments(
        ProxyEndpoint $targetProxy,
        string $probeInstrumentName,
        int $payoutStaleMinutes,
        Carbon $now,
    ): bool {
        $others = PocketInstrument::query()
            ->active()
            ->where('proxy_endpoint_id', $targetProxy->id)
            ->where('instrument', '!=', $probeInstrumentName)
            ->get();

        foreach ($others as $other) {
            if ($this->isHealthyForReactivation($other, $payoutStaleMinutes, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status: string, reason: string, instrument: string}
     */
    private function completeProbeSuccess(
        ProxyEndpoint $targetProxy,
        string $probeInstrumentName,
        string $probeScope,
        int $payoutStaleMinutes,
        PocketInstrument $instrument,
        ?string $fixedReason,
    ): array {
        $now = Carbon::now();
        $reason = $fixedReason ?? $this->applyProbeSuccess($targetProxy, $now);
        $meta = array_merge(
            $this->healthMeta($instrument, $payoutStaleMinutes, $now),
            ['probe_scope' => $probeScope],
        );
        $this->saveAutoTestResult($targetProxy->id, [
            'state' => 'done',
            'status' => 'ok',
            'instrument' => $probeInstrumentName,
            'reason' => $reason,
            'meta' => $meta,
        ]);

        return ['status' => 'ok', 'reason' => $reason, 'instrument' => $probeInstrumentName];
    }

    private function restoreInstrumentProxy(PocketInstrument $instrument, ?int $originalProxyId): void
    {
        $instrument->update([
            'proxy_endpoint_id' => $originalProxyId,
        ]);
    }

    private function waitForProbeHealth(
        PocketInstrument $instrument,
        int $windowSec,
        int $payoutStaleMinutes,
        Carbon $probeStartedAt,
        bool $requireFreshSignals,
    ): bool {
        $deadline = microtime(true) + $windowSec;

        while (microtime(true) < $deadline) {
            $instrument->refresh();
            $now = Carbon::now();

            if ($requireFreshSignals) {
                if ($this->isHealthyWithFreshSignalsSince($instrument, $probeStartedAt, $payoutStaleMinutes, $now)) {
                    return true;
                }
            } elseif ($this->isHealthyForReactivation($instrument, $payoutStaleMinutes, $now)) {
                return true;
            }

            usleep(1_000_000);
        }

        $instrument->refresh();
        $now = Carbon::now();

        return $requireFreshSignals
            ? $this->isHealthyWithFreshSignalsSince($instrument, $probeStartedAt, $payoutStaleMinutes, $now)
            : $this->isHealthyForReactivation($instrument, $payoutStaleMinutes, $now);
    }

    /**
     * После сброса метрик засчитываем только свежее событие payout PO после старта пробы.
     */
    private function isHealthyWithFreshSignalsSince(
        PocketInstrument $instrument,
        Carbon $probeStartedAt,
        int $payoutStaleMinutes,
        Carbon $now,
    ): bool {
        if (! $this->isHealthyForReactivation($instrument, $payoutStaleMinutes, $now)) {
            return false;
        }

        $notBefore = $probeStartedAt->copy()->subSeconds(3);
        $poAt = $instrument->last_payout_event_at ?? $instrument->last_payout_at;

        return $poAt !== null && $poAt->gte($notBefore);
    }

    /**
     * PO ok для авто-пробы: свежий timestamp события payout от PO.
     * Игнорируем payout_is_stale — ingest часто держит stale=1 при живом потоке.
     */
    private function isHealthyForReactivation(
        PocketInstrument $instrument,
        int $payoutStaleMinutes,
        Carbon $now,
    ): bool {
        return $this->isPoOkForProbe($instrument, $payoutStaleMinutes, $now);
    }

    private function isPoOkForProbe(
        PocketInstrument $instrument,
        int $payoutStaleMinutes,
        Carbon $now,
    ): bool {
        $eventAt = $instrument->last_payout_event_at ?? $instrument->last_payout_at;

        if ($eventAt === null) {
            return false;
        }

        return $eventAt->gte($now->copy()->subMinutes(max(1, $payoutStaleMinutes)));
    }

    /**
     * @return array<string, bool>
     */
    private function healthMeta(PocketInstrument $instrument, int $payoutStaleMinutes, Carbon $now): array
    {
        return [
            'po_ok' => $this->isPoOkForProbe($instrument, $payoutStaleMinutes, $now),
            'po_fresh' => ! IngestHealth::isPayoutEventStale($instrument, $payoutStaleMinutes, $now),
            'payout_is_stale_flag' => (bool) $instrument->payout_is_stale,
            'ingest_alive' => ! IngestHealth::isIngestSilent($instrument, $now),
            'ws_connected' => IngestHealth::isWsRecentlyConnected($instrument, $now),
            'ws_not_failed_recently' => ! IngestHealth::isWsRecentlyFailed($instrument, $now),
        ];
    }
}
