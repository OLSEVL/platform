<?php

namespace App\Support;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Models\UserTradingSetting;
use Illuminate\Support\Carbon;

/**
 * Прокси для WebSocket Pocket Option (зеркало сделок).
 * Режимы: авто (sticky least-loaded до dead), ручной пул (sticky до dead), fallback на прокси пары.
 */
class PocketMirrorProxy
{
    /** Сколько последних auto-выборов хранить в профиле (для таблицы). */
    public const AUTO_HISTORY_LIMIT = 40;

    /**
     * Прокси для зеркала:
     * 1) авто — sticky last active, иначе min нагрузка;
     * 2) ручной пул — sticky last из пула, иначе случайный;
     * 3) прокси пары / первый active.
     */
    public static function resolveForMirror(
        ?UserTradingSetting $settings,
        ?string $instrument = null,
    ): ?string {
        return self::resolveForMirrorEndpoint($settings, $instrument)['url'] ?? null;
    }

    /**
     * То же, что resolveForMirror, плюс id endpoint (для учёта failure_count / dead).
     *
     * @return array{id: ?int, url: ?string}
     */
    public static function resolveForMirrorEndpoint(
        ?UserTradingSetting $settings,
        ?string $instrument = null,
    ): array {
        if ($settings !== null && (bool) $settings->mirror_proxy_auto) {
            // Sticky warm: пока last endpoint active — не прыгаем на новый (избегаем cold start).
            $sticky = self::peekStickyEndpoint($settings);
            if ($sticky !== null) {
                return $sticky;
            }

            $picked = self::pickLeastLoadedActive();
            if ($picked !== null) {
                self::rememberAutoPick($settings, (int) $picked['id']);

                return [
                    'id' => (int) $picked['id'],
                    'url' => $picked['url'],
                ];
            }
        }

        if ($settings !== null) {
            $stickyPool = self::peekStickyEndpoint($settings);
            if ($stickyPool !== null) {
                return $stickyPool;
            }
        }

        $fromPool = self::randomEndpointFromUserPool($settings);
        if ($fromPool !== null) {
            if ($settings !== null && ($fromPool['id'] ?? null) !== null) {
                self::rememberAutoPick($settings, (int) $fromPool['id']);
            }

            return $fromPool;
        }

        return self::resolveEndpointForInstrument($instrument);
    }

    /**
     * Текущий sticky endpoint без нового pick (для UI status).
     * Условия: last_id > 0, endpoint readyForAssignment; при ручном пуле — id в выбранных.
     *
     * @return array{id: int, url: string}|null
     */
    public static function peekStickyEndpoint(?UserTradingSetting $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        $endpointId = (int) ($settings->mirror_proxy_auto_last_endpoint_id ?? 0);
        if ($endpointId <= 0) {
            return null;
        }

        // Ручной режим: sticky только если endpoint всё ещё в отмеченном пуле.
        if (! (bool) $settings->mirror_proxy_auto) {
            $poolIds = $settings->mirrorProxyEndpointIds();
            if ($poolIds !== [] && ! in_array($endpointId, $poolIds, true)) {
                return null;
            }
            // Пустой ручной пул — sticky всё равно ок, пока endpoint active (fallback path).
        }

        $endpoint = ProxyEndpoint::query()
            ->readyForAssignment()
            ->whereKey($endpointId)
            ->first(['id', 'url']);

        if ($endpoint === null || ! is_string($endpoint->url) || $endpoint->url === '') {
            return null;
        }

        return [
            'id' => (int) $endpoint->id,
            'url' => (string) $endpoint->url,
        ];
    }

    /**
     * Сбросить sticky (после connect fail на warm — чтобы сразу взять другой active).
     */
    public static function forgetSticky(UserTradingSetting $settings): void
    {
        $settings->forceFill([
            'mirror_proxy_auto_last_endpoint_id' => null,
            'mirror_proxy_auto_last_at' => null,
        ])->save();
    }

    /**
     * Найти active/any endpoint по полному URL (как ушёл в place-order).
     */
    public static function findEndpointByUrl(?string $proxyUrl): ?ProxyEndpoint
    {
        if ($proxyUrl === null || trim($proxyUrl) === '') {
            return null;
        }

        $url = trim($proxyUrl);

        return ProxyEndpoint::query()->where('url', $url)->first();
    }

    /**
     * @return array{id: ?int, url: ?string}|null
     */
    private static function randomEndpointFromUserPool(?UserTradingSetting $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        $ids = $settings->mirrorProxyEndpointIds();
        if ($ids === []) {
            return null;
        }

        $rows = ProxyEndpoint::query()
            ->readyForAssignment()
            ->whereIn('id', $ids)
            ->get(['id', 'url'])
            ->filter(fn (ProxyEndpoint $p) => is_string($p->url) && $p->url !== '')
            ->values();

        if ($rows->isEmpty()) {
            return null;
        }

        /** @var ProxyEndpoint $chosen */
        $chosen = $rows->random();

        return [
            'id' => (int) $chosen->id,
            'url' => (string) $chosen->url,
        ];
    }

    /**
     * @return array{id: ?int, url: ?string}
     */
    private static function resolveEndpointForInstrument(?string $instrument): array
    {
        if ($instrument !== null && $instrument !== '') {
            $row = PocketInstrument::query()
                ->where('instrument', $instrument)
                ->with('proxyEndpoint')
                ->first();

            $endpoint = $row?->proxyEndpoint;
            if ($endpoint !== null && is_string($endpoint->url) && $endpoint->url !== '') {
                return [
                    'id' => (int) $endpoint->id,
                    'url' => (string) $endpoint->url,
                ];
            }
        }

        $fallback = ProxyEndpoint::query()
            ->readyForAssignment()
            ->orderBy('id')
            ->first(['id', 'url']);

        if ($fallback === null || ! is_string($fallback->url) || $fallback->url === '') {
            return ['id' => null, 'url' => null];
        }

        return [
            'id' => (int) $fallback->id,
            'url' => (string) $fallback->url,
        ];
    }

    /**
     * Active (не dead) endpoint с минимальной нагрузкой (число пар).
     * При ничьей — случайный среди лидеров.
     *
     * @return array{id:int,url:string}|null
     */
    public static function pickLeastLoadedActive(): ?array
    {
        $rows = ProxyEndpoint::query()
            ->readyForAssignment()
            ->withCount('instruments')
            ->get(['id', 'url']);

        if ($rows->isEmpty()) {
            return null;
        }

        $minLoad = (int) $rows->min('instruments_count');
        $candidates = $rows
            ->filter(fn (ProxyEndpoint $p) => (int) $p->instruments_count === $minLoad)
            ->filter(fn (ProxyEndpoint $p) => is_string($p->url) && $p->url !== '')
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        /** @var ProxyEndpoint $chosen */
        $chosen = $candidates->random();

        return [
            'id' => (int) $chosen->id,
            'url' => (string) $chosen->url,
        ];
    }

    /**
     * Только URL (для тестов/совместимости); без записи в профиль.
     */
    public static function leastLoadedActive(): ?string
    {
        $picked = self::pickLeastLoadedActive();

        return $picked['url'] ?? null;
    }

    /**
     * ID active-прокси с текущей минимальной нагрузкой (кандидаты авто-пула на экране).
     *
     * @return list<int>
     */
    public static function leastLoadedActiveIds(): array
    {
        $rows = ProxyEndpoint::query()
            ->readyForAssignment()
            ->withCount('instruments')
            ->get(['id']);

        if ($rows->isEmpty()) {
            return [];
        }

        $minLoad = (int) $rows->min('instruments_count');

        return $rows
            ->filter(fn (ProxyEndpoint $p) => (int) $p->instruments_count === $minLoad)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Запоминает auto-выбор: последний id + короткая история для UI.
     */
    public static function rememberAutoPick(UserTradingSetting $settings, int $endpointId): void
    {
        if ($endpointId <= 0) {
            return;
        }

        $now = Carbon::now();
        $history = is_array($settings->mirror_proxy_auto_history)
            ? $settings->mirror_proxy_auto_history
            : [];

        $history[] = [
            'id' => $endpointId,
            'at' => $now->toIso8601String(),
        ];

        if (count($history) > self::AUTO_HISTORY_LIMIT) {
            $history = array_slice($history, -self::AUTO_HISTORY_LIMIT);
        }

        // Без touch updated_at лишний раз при каждом зеркале — только нужные поля.
        $settings->forceFill([
            'mirror_proxy_auto_last_endpoint_id' => $endpointId,
            'mirror_proxy_auto_last_at' => $now,
            'mirror_proxy_auto_history' => array_values($history),
        ])->save();
    }

    /**
     * Случайный URL среди active-прокси, отмеченных пользователем.
     */
    public static function randomFromUserPool(?UserTradingSetting $settings): ?string
    {
        if ($settings === null) {
            return null;
        }

        $ids = $settings->mirrorProxyEndpointIds();
        if ($ids === []) {
            return null;
        }

        $urls = ProxyEndpoint::query()
            ->readyForAssignment()
            ->whereIn('id', $ids)
            ->pluck('url')
            ->filter(fn ($url) => is_string($url) && $url !== '')
            ->values()
            ->all();

        if ($urls === []) {
            return null;
        }

        return $urls[array_rand($urls)];
    }

    public static function resolveForInstrument(?string $instrument): ?string
    {
        if ($instrument !== null && $instrument !== '') {
            $row = PocketInstrument::query()
                ->where('instrument', $instrument)
                ->with('proxyEndpoint')
                ->first();

            $url = $row?->proxyEndpoint?->url;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return ProxyEndpoint::query()
            ->readyForAssignment()
            ->orderBy('id')
            ->value('url');
    }
}
