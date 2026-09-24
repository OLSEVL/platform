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
        // Auth user, если caller не передал id (blade partial на защищённых страницах).
        if ($userId === null) {
            $authId = auth()->id();
            $userId = $authId !== null ? (int) $authId : null;
        }

        $token = ChartLiveConfig::enabled()
            ? ChartLiveConfig::mintToken($userId)
            : ['token' => '', 'exp' => 0];

        // Цвета свечей: дефолт PO, иначе из профиля пользователя.
        $candleColors = ChartCandleColors::forUser($userId);

        return [
            // PR2 live WS
            'liveEnabled' => ChartLiveConfig::enabled(),
            'liveWsUrl' => ChartLiveConfig::hubPublicUrl(),
            'liveToken' => (string) ($token['token'] ?? ''),
            // Устарело: клиент при live не делает HTTP-resync; ключ оставлен для совместимости.
            'liveResyncPollMs' => (int) config('chart.live_resync_poll_ms', 20000),
            // PR3 hot-path
            'healEnabled' => (bool) config('chart.heal_enabled', false),
            'domOverlayTickMs' => (int) config('chart.dom_overlay_tick_ms', 1000),
            'domOverlaySyncMinMs' => (int) config('chart.dom_overlay_sync_min_ms', 500),
            'instrumentsRefreshMs' => (int) config('chart.instruments_refresh_ms', 5000),
            // Медленный poll open_trade/scan, когда payout идёт по WS.
            'instrumentsRefreshMsLive' => (int) config('chart.instruments_refresh_ms_live', 30000),
            // Блок «Статистика» на странице бота.
            'botStatsRefreshMs' => (int) config('chart.bot_stats_refresh_ms', 10000),
            'botStatsRefreshMsLive' => (int) config('chart.bot_stats_refresh_ms_live', 120000),
            'botTradesHealMsLive' => (int) config('chart.bot_trades_heal_ms_live', 120000),
            'smoothMaxFps' => (int) config('chart.smooth_max_fps', 24),
            'htmlTimeRuler' => (bool) config('chart.html_time_ruler', false),
            'liveTradesPollMs' => (int) config('chart.live_trades_poll_ms', 8000),
            // При live WS — редкий heal ленты (мс); 0 = только initial + push.
            'liveTradesHealMsLive' => (int) config('chart.live_trades_heal_ms_live', 60000),
            // Фильтр bot_event на dashboard (fan-out всем WS).
            'userId' => $userId !== null ? (int) $userId : 0,
            'domOverlayTickLoop' => (bool) config('chart.dom_overlay_tick_loop', false),
            // PR4 trade-lite
            'tradeLite' => (bool) config('chart.trade_lite', true),
            // Цвета свечей (body + wick); doji красится downColor как на PO.
            'candleUpColor' => $candleColors['up'],
            'candleDownColor' => $candleColors['down'],
        ];
    }
}
