<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chart live feed (PR2)
    |--------------------------------------------------------------------------
    | Redis PUBLISH channel chart:s5 → Python hub → браузер WS.
    | При live_enabled=false график остаётся на REST poll /candles.
    */
    'live_enabled' => (bool) env('CHART_LIVE_ENABLED', true),

    /** Публичный WS URL (пусто = вывести из APP_URL + /chart-live). */
    'live_ws_url' => env('CHART_LIVE_WS_URL', ''),

    /** TTL HMAC-токена для hub (секунды). */
    'live_token_ttl_sec' => (int) env('CHART_LIVE_TOKEN_TTL_SEC', 43200),

    /**
     * Устарело: при live HTTP-resync хвоста отключён (только WS).
     * Ключ оставлен для совместимости boot/env; клиент его не читает.
     */
    'live_resync_poll_ms' => (int) env('CHART_LIVE_RESYNC_POLL_MS', 20000),

    /*
    |--------------------------------------------------------------------------
    | Chart hot-path (PR3)
    |--------------------------------------------------------------------------
    | Цель: меньше long tasks на main thread (heal setData, DOM overlay tick,
    | sidebar poll). Полный перенос оверлеев на canvas — следующий шаг.
    */
    /** Периодический full setData «heal» — при live WS/resync не нужен. */
    'heal_enabled' => (bool) env('CHART_HEAL_ENABLED', false),

    /** Интервал flush HTML-оверлеев (мс), если dirty. Было 400. */
    'dom_overlay_tick_ms' => (int) env('CHART_DOM_OVERLAY_TICK_MS', 1000),

    /** Мин. пауза между light DOM sync (мс). Было 200. */
    'dom_overlay_sync_min_ms' => (int) env('CHART_DOM_OVERLAY_SYNC_MIN_MS', 500),

    /** Обновление списка инструментов в сайдбаре (мс). Было 1000. */
    'instruments_refresh_ms' => (int) env('CHART_INSTRUMENTS_REFRESH_MS', 5000),

    /**
     * При live WS — редкий heal сайдбара инструментов (мс). 0 = только initial + WS.
     */
    'instruments_refresh_ms_live' => (int) env('CHART_INSTRUMENTS_REFRESH_MS_LIVE', 120000),

    /**
     * Poll блока «Статистика» на странице бота (мс). Было 3000.
     * При live bot:events push клиент использует stats_refresh_ms_live.
     */
    'bot_stats_refresh_ms' => (int) env('CHART_BOT_STATS_REFRESH_MS', 10000),

    /** Fallback stats poll при живом WS (мс). 0 = только push + initial. */
    'bot_stats_refresh_ms_live' => (int) env('CHART_BOT_STATS_REFRESH_MS_LIVE', 120000),

    /** Heal open-trades/signals при live (мс). 0 = только push. */
    'bot_trades_heal_ms_live' => (int) env('CHART_BOT_TRADES_HEAL_MS_LIVE', 120000),

    /** Макс. FPS tween close (series.update). 0 = каждый rAF (~60). */
    'smooth_max_fps' => (int) env('CHART_SMOOTH_MAX_FPS', 24),

    /**
     * PR3b / trade-lite: HTML time-ruler (деления секунд). Нативная ось LWC всегда есть.
     * false = меньше DOM на pan/zoom (ближе к Binodex).
     */
    'html_time_ruler' => (bool) env('CHART_HTML_TIME_RULER', false),

    /** Dashboard лента live-trades (мс). Без live WS. Было 2000. */
    'live_trades_poll_ms' => (int) env('CHART_LIVE_TRADES_POLL_MS', 8000),

    /**
     * При live WS полный push карточек — редкий HTTP heal ленты (мс).
     * 0 = только initial GET + WS, без периодического poll.
     */
    'live_trades_heal_ms_live' => (int) env('CHART_LIVE_TRADES_HEAL_MS_LIVE', 60000),

    /**
     * Периодический DOM-overlay tick loop (attachPrimitive dirty flush).
     * При false — sync только на pan/zoom и при открытых сделках.
     */
    'dom_overlay_tick_loop' => (bool) env('CHART_DOM_OVERLAY_TICK_LOOP', false),

    /*
    |--------------------------------------------------------------------------
    | Trade-lite (PR4)
    |--------------------------------------------------------------------------
    | Торговый экран без oscillator panes (RSI/Vol Candles/…): меньше canvas LWC.
    | Overlay SMA/EMA/BB на основной pane остаются. Analyst: CHART_TRADE_LITE=false.
    */
    'trade_lite' => (bool) env('CHART_TRADE_LITE', true),

    /*
    |--------------------------------------------------------------------------
    | Цвета свечей (как на Pocket Option по умолчанию)
    |--------------------------------------------------------------------------
    | Пользователь может переопределить в /profile/trading.
    | Hex должны совпадать с App\Support\ChartCandleColors::DEFAULT_*.
    */
    'candle_up_color' => env('CHART_CANDLE_UP_COLOR', '#22ff00'),
    'candle_down_color' => env('CHART_CANDLE_DOWN_COLOR', '#ff0000'),
];
