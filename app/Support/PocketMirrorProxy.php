<?php

namespace App\Support;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Models\UserTradingSetting;
use Illuminate\Support\Carbon;

/**
 * Прокси для WebSocket Pocket Option (зеркало сделок).
 * Режимы: авто (min нагрузка), ручной пул (случайный), fallback на прокси пары.
 */
class PocketMirrorProxy
{
    /** Сколько последних auto-выборов хранить в профиле (для таблицы). */
    public const AUTO_HISTORY_LIMIT = 40;

    /**
     * Прокси для зеркала:
     * 1) авто — active с минимальным числом инструментов;
     * 2) случайный из отмеченных в профиле;
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
            $picked = self::pickLeastLoadedActive();
            if ($picked !== null) {
                self::rememberAutoPick($settings, (int) $picked['id']);

                return [
                    'id' => (int) $picked['id'],
                    'url' => $picked['url'],
                ];
            }
        }

        $fromPool = self::randomEndpointFromUserPool($settings);
        if ($fromPool !== null) {
            return $fromPool;
        }

        return self::resolveEndpointForInstrument($instrument);
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
