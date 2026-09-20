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
     * Пока WS жив: как часто всё же делать лёгкий REST-resync хвоста (мс).
     * 0 = не ресинкать (только WS). Рекомендуется 15000–30000.
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

    /** Макс. FPS tween close (series.update). 0 = каждый rAF (~60). */
    'smooth_max_fps' => (int) env('CHART_SMOOTH_MAX_FPS', 24),

    /**
     * PR3b / trade-lite: HTML time-ruler (деления секунд). Нативная ось LWC всегда есть.
     * false = меньше DOM на pan/zoom (ближе к Binodex).
     */
    'html_time_ruler' => (bool) env('CHART_HTML_TIME_RULER', false),

    /** Dashboard лента live-trades (мс). Было 2000. */
    'live_trades_poll_ms' => (int) env('CHART_LIVE_TRADES_POLL_MS', 8000),

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
];
