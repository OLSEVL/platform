<?php

namespace App\Support;

/**
 * PR5: общие флаги boot JSON для dashboard/bot Vite chart entries.
 * Дублировать ключи live/heal/tradeLite в двух blade не нужно.
 */
class ChartBootFlags
{
    /**
     * @return array<string, mixed>
     */
    public static function forClient(?int $userId = null): array
    {
        $token = ChartLiveConfig::enabled()
            ? ChartLiveConfig::mintToken($userId)
            : ['token' => '', 'exp' => 0];

        return [
            // PR2 live WS
            'liveEnabled' => ChartLiveConfig::enabled(),
            'liveWsUrl' => ChartLiveConfig::hubPublicUrl(),
            'liveToken' => (string) ($token['token'] ?? ''),
            'liveResyncPollMs' => (int) config('chart.live_resync_poll_ms', 20000),
            // PR3 hot-path
            'healEnabled' => (bool) config('chart.heal_enabled', false),
            'domOverlayTickMs' => (int) config('chart.dom_overlay_tick_ms', 1000),
            'domOverlaySyncMinMs' => (int) config('chart.dom_overlay_sync_min_ms', 500),
            'instrumentsRefreshMs' => (int) config('chart.instruments_refresh_ms', 5000),
            'smoothMaxFps' => (int) config('chart.smooth_max_fps', 24),
            'htmlTimeRuler' => (bool) config('chart.html_time_ruler', false),
            'liveTradesPollMs' => (int) config('chart.live_trades_poll_ms', 8000),
            'domOverlayTickLoop' => (bool) config('chart.dom_overlay_tick_loop', false),
            // PR4 trade-lite
            'tradeLite' => (bool) config('chart.trade_lite', true),
        ];
    }
}
