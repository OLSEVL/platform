/**
 * Dashboard chart runtime (Vite). Config from window.__CHART_DASHBOARD_BOOT__.
 * Behavior intentionally matches the former inline Blade IIFE (poll path intact).
 */
(function bootDashboardChart() {
    const boot = window.__CHART_DASHBOARD_BOOT__;
    if (!boot || typeof boot !== 'object') {
        console.error('[chart] missing window.__CHART_DASHBOARD_BOOT__');
        return;
    }

    const CANDLES_URL = boot.candlesUrl;
    const STRUCTURES_URL = boot.structuresUrl;
    const PA_INDICATOR_URL = boot.paIndicatorUrl;
    const LS_INDICATOR_URL = boot.lsIndicatorUrl;
    const SMC_INDICATOR_URL = boot.smcIndicatorUrl;
    // Классика OsEngine: BB/RSI/SMA/EMA/ATR (params с якорного бота).
    const CLASSIC_INDICATORS_URL = boot.classicIndicatorsUrl;
    // Параметры клиентских индикаторов (дефолты модулей, если boot пуст).
    const clientIndicatorParams = (boot.clientIndicatorParams && typeof boot.clientIndicatorParams === 'object')
        ? boot.clientIndicatorParams
        : {};
    const INSTRUMENTS_SIDEBAR_URL = boot.instrumentsSidebarUrl;
    // Сделки всех ботов пользователя по текущему инструменту → маркеры WIN/LOSE/OPEN.
    const CHART_TRADES_URL = boot.chartTradesUrl;
    // Лента зеркалимых на PO сделок для правой колонки dashboard.
    const LIVE_TRADES_URL = boot.liveTradesUrl;
    const LIVE_TRADES_ACTIVITY_TMPL = boot.liveTradesActivityTmpl;

    /**
     * Цвета свечей из профиля (дефолт PO: #22ff00 / #ff0000).
     * Читаем один раз при boot — без лишней работы на каждом poll.
     */
    function normalizeCandleHex(raw, fallback) {
        if (typeof raw !== 'string') return fallback;
        let h = raw.trim().toLowerCase();
        if (!h) return fallback;
        if (h[0] !== '#') h = '#' + h;
        if (/^#[0-9a-f]{3}$/.test(h)) {
            h = '#' + h[1] + h[1] + h[2] + h[2] + h[3] + h[3];
        }
        return /^#[0-9a-f]{6}$/.test(h) ? h : fallback;
    }
    const CANDLE_UP_COLOR = normalizeCandleHex(boot.candleUpColor, '#22ff00');
    const CANDLE_DOWN_COLOR = normalizeCandleHex(boot.candleDownColor, '#ff0000');
    // Для Chrome MCP / e2e: проверить, что boot пробросил цвета профиля.
    window.__platformCandleColors = { up: CANDLE_UP_COLOR, down: CANDLE_DOWN_COLOR };

    function serverUnixNow() {
        return window.PlatformServerClock ? PlatformServerClock.unixNow() : Math.floor(Date.now() / 1000);
    }

    // Окна загрузки разделены на три режима, чтобы переключение между инструментами
    // было быстрым, но при этом сохранялись lazy-догрузка истории и live-обновление.
    //
    // 1) INITIAL — что грузим при первом показе инструмента (selectInstrument / reloadChartData).
    //    Минутные TF: 2 часа (~110 M1-баров). Секундные (S5/S15/S30): окно привязано к viewport
    //    (~130 баров), чтобы не тянуть 1440 S5-свечей на каждый клик.
    // INITIAL — первичный показ: считаем в БАРАХ (не в часах), иначе на M5/M15
    //     2 часа давали ~24/~8 свечей. Берём viewport + буфер = 130 баров на любом TF.
    // MAX — потолок истории при скролле влево: из /settings «Автоматически удалять…»
    //     (раньше был хардкод 24ч — настройка очистки на график не влияла).
    // POLL — узкое окно auto-refresh хвоста.
    const MAX_WINDOW_SEC = Number(boot.chartHistoryMaxSeconds) || 86400;
    const POLL_WINDOW_SEC = 5 * 60;

    // Лимиты баров в одном ответе:
    // - INITIAL_BARS / initialLimitForTf — первичная загрузка по числу свечей TF.
    // - LOAD_MORE_LIMIT — ленивая подгрузка старшей истории.
    // - POLL_LIMIT — апдейт хвоста.
    const INITIAL_VISIBLE_BUFFER_BARS = 20;
    const LOAD_MORE_LIMIT = 1440;
    const POLL_LIMIT = 32;

    // Секундные ID (4005/4015/4030) и минутные (1,2,3...) — PlatformChartTimeframe (PR5).
    function timeframeSeconds(tf) {
        return window.PlatformChartTimeframe
            ? PlatformChartTimeframe.seconds(tf)
            : (tf === 4005 ? 5 : tf === 4015 ? 15 : tf === 4030 ? 30 : Math.max(1, Number(tf) || 1) * 60);
    }
    function timeframeLabel(tf) {
        return window.PlatformChartTimeframe
            ? PlatformChartTimeframe.label(tf)
            : (tf === 4005 ? 'S5' : tf === 4015 ? 'S15' : tf === 4030 ? 'S30' : ('M' + String(tf)));
    }
    function isSecondTimeframe(tf) {
        return window.PlatformChartTimeframe
            ? PlatformChartTimeframe.isSecond(tf)
            : (tf === 4005 || tf === 4015 || tf === 4030);
    }

    const M1_COUNTDOWN_SECONDS = 60;
    /**
     * Таймер M1: сервер в каждом ответе /candles отдаёт m1_seconds_to_close (учёт смещения шкалы time свечей vs PHP time).
     * Между опросами убываем локально по performance.now(), без смешивания «часов» на клиенте.
     */
    let m1CloseCountdownAnchorSec = null;
    let m1CloseCountdownAnchorPerfMs = null;
    let tfCloseCountdownAnchorSec = null;
    let tfCloseCountdownAnchorPerfMs = null;
    /** Точность оси цены; синхронизируется с price_scale из /candles (единые 8 знаков, как PO). */
    let chartPricePrecision = 8;
    let chartPriceMinMove = 0.00001;

    /**
     * Применяем precision/minMove с API к candlestick-серии (8 знаков для всех инструментов).
     * Вызывается на каждом poll — без guard applyOptions дергает LWC canvas ~2 раза/с и ломает хвост.
     */
    function applyPriceScaleFromPayload(payload) {
        if (!candlestickSeries || !payload || !payload.price_scale) return;
        const precision = Number(payload.price_scale.precision);
        const minMove = Number(payload.price_scale.min_move);
        if (!Number.isFinite(precision) || !Number.isFinite(minMove) || precision <= 0 || minMove <= 0) return;
        if (precision === chartPricePrecision && minMove === chartPriceMinMove) return;
        chartPricePrecision = precision;
        chartPriceMinMove = minMove;
        try {
            candlestickSeries.applyOptions({
                priceFormat: { type: 'price', precision, minMove },
            });
        } catch (e) {
            console.error('[dashboard chart] price scale', e);
        }
    }

    function applyM1CountdownFromPayload(payload) {
        if (!payload || typeof payload.m1_seconds_to_close !== 'number' || !Number.isFinite(payload.m1_seconds_to_close)) {
            m1CloseCountdownAnchorSec = null;
            m1CloseCountdownAnchorPerfMs = null;
            return;
        }
        m1CloseCountdownAnchorSec = Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, payload.m1_seconds_to_close));
        m1CloseCountdownAnchorPerfMs = performance.now();
    }

    function m1CountdownLeftNow() {
        if (m1CloseCountdownAnchorSec === null || m1CloseCountdownAnchorPerfMs === null) {
            return null;
        }
        const elapsed = (performance.now() - m1CloseCountdownAnchorPerfMs) / 1000;
        return Math.max(0, Math.min(M1_COUNTDOWN_SECONDS, m1CloseCountdownAnchorSec - elapsed));
    }

    /** Обратный отсчёт до закрытия бара для S5/S15/S30 и прочих не-M1 TF (по server_unix из /candles). */
    function applyGenericCountdownFromPayload(payload, tf) {
        const periodSec = timeframeSeconds(tf);
        if (
            !payload ||
            !Array.isArray(payload.candles) ||
            payload.candles.length === 0 ||
            typeof payload.server_unix !== 'number' ||
            !Number.isFinite(payload.server_unix)
        ) {
            tfCloseCountdownAnchorSec = null;
            tfCloseCountdownAnchorPerfMs = null;
            return;
        }
        const last = payload.candles[payload.candles.length - 1];
        const lastOpen = Number(last && last.time);
        if (!Number.isFinite(lastOpen)) {
            tfCloseCountdownAnchorSec = null;
            tfCloseCountdownAnchorPerfMs = null;
            return;
        }
        const closeTs = lastOpen + periodSec;
        const left = Math.max(0, Math.min(periodSec, closeTs - Number(payload.server_unix)));
        tfCloseCountdownAnchorSec = left;
        tfCloseCountdownAnchorPerfMs = performance.now();
    }

    function genericCountdownLeftNow(tf) {
        const periodSec = timeframeSeconds(tf);
        if (tfCloseCountdownAnchorSec === null || tfCloseCountdownAnchorPerfMs === null) {
            return null;
        }
        const elapsed = (performance.now() - tfCloseCountdownAnchorPerfMs) / 1000;
        return Math.max(0, Math.min(periodSec, tfCloseCountdownAnchorSec - elapsed));
    }
    /**
     * Binodex: бейдж сразу справа от тела текущей свечи (не на 3 интервала вправо).
     * px-зазор от правого края бара; вертикаль — close, центрируем через -translate-y-1/2 в CSS.
     */
    const CANDLE_TIMER_GAP_PX = 6;
    /** Фиксированный зазор над полоской last price (нижний край pill → линия). */
    const CANDLE_TIMER_ABOVE_LINE_PX = 4;

    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartWrapper = document.getElementById('chart-wrapper');
    const structureOverlay = document.getElementById('structure-overlay');
    // PO-лучи открытых сделок (точка входа + горизонталь до экспирации).
    const poOpenTradesOverlay = document.getElementById('po-open-trades-overlay');
    // Paper-сделки ботов: полосы длительности entry→expiry (не PO).
    const tradeDurationOverlay = document.getElementById('trade-duration-overlay');
    const candleTimerOverlay = document.getElementById('candle-timer-overlay');
    const chartTitle = document.getElementById('chart-title');
    const chartTitlePayoutEl = document.getElementById('chart-title-payout');
    const chartLoading = document.getElementById('chart-loading');
    const tfBtn = document.getElementById('tf-dropdown-btn');
    const tfPanel = document.getElementById('tf-dropdown-panel');
    const tfLabelEl = document.getElementById('tf-dropdown-label');
    const candleCloseTimerEl = document.getElementById('candle-close-timer');
    const candleTfLabelEl = document.getElementById('candle-tf-label');
    const layersBtn = document.getElementById('layers-dropdown-btn');
    const layersPanel = document.getElementById('layers-dropdown-panel');
    const layerOb = document.getElementById('layer-ob');
    const layerFvg = document.getElementById('layer-fvg');
    const layerPa = document.getElementById('layer-pa-dgt');
    const layerLiquidity = document.getElementById('layer-liquidity-swings');
    const layerSweepRaid = document.getElementById('layer-sweep-raid-liquidity');
    const layerEngulfing = document.getElementById('layer-engulfing-liquidity');
    const layerSmc = document.getElementById('layer-smc-flow');
    const layerBollinger = document.getElementById('layer-bollinger');
    const layerRsi = document.getElementById('layer-rsi');
    const layerSma = document.getElementById('layer-sma');
    const layerEma = document.getElementById('layer-ema');
    const layerVwma = document.getElementById('layer-vwma');
    const layerAtr = document.getElementById('layer-atr');
    const layerMacd = document.getElementById('layer-macd');
    const layerStochastic = document.getElementById('layer-stochastic');
    const layerCci = document.getElementById('layer-cci');
    const layerKeltner = document.getElementById('layer-keltner');
    const layerEnvelops = document.getElementById('layer-envelops');
    const layerParabolicSar = document.getElementById('layer-parabolic-sar');
    const layerSuperTrend = document.getElementById('layer-supertrend');
    const layerAdx = document.getElementById('layer-adx');
    const layerWilliams = document.getElementById('layer-williams');
    const layerAo = document.getElementById('layer-ao');
    const layerAlligator = document.getElementById('layer-alligator');
    const layerIchimoku = document.getElementById('layer-ichimoku');
    const layerPriceChannel = document.getElementById('layer-price-channel');
    const layerNrtr = document.getElementById('layer-nrtr');
    const layerHma = document.getElementById('layer-hma');
    const layerAroon = document.getElementById('layer-aroon');
    // 16 новых классических индикаторов OsEngine.
    const layerMomentum = document.getElementById('layer-momentum');
    const layerRoc = document.getElementById('layer-roc');
    const layerOsma = document.getElementById('layer-osma');
    const layerTrix = document.getElementById('layer-trix');
    const layerCmo = document.getElementById('layer-cmo');
    const layerDemarker = document.getElementById('layer-demarker');
    const layerRvi = document.getElementById('layer-rvi');
    const layerUltimate = document.getElementById('layer-ultimate');
    const layerSmi = document.getElementById('layer-smi');
    const layerStdDev = document.getElementById('layer-stddev');
    const layerSmaChannel = document.getElementById('layer-sma-channel');
    const layerIvashov = document.getElementById('layer-ivashov');
    const layerLinReg = document.getElementById('layer-linreg');
    const layerLinRegChannel = document.getElementById('layer-linreg-channel');
    const layerKalman = document.getElementById('layer-kalman');
    const layerFractal = document.getElementById('layer-fractal');
    const layerStratAssistant = document.getElementById('layer-strat-assistant');
    // 13 новых OsEngine OHLC-индикаторов (Donchian … Mass Index).
    const layerDonchian = document.getElementById('layer-donchian');
    const layerStochRsi = document.getElementById('layer-stoch-rsi');
    const layerAc = document.getElementById('layer-ac');
    const layerBullsPower = document.getElementById('layer-bulls-power');
    const layerBearsPower = document.getElementById('layer-bears-power');
    const layerDpo = document.getElementById('layer-dpo');
    const layerQstick = document.getElementById('layer-qstick');
    const layerVhFilter = document.getElementById('layer-vh-filter');
    const layerEfficiencyRatio = document.getElementById('layer-efficiency-ratio');
    const layerRavi = document.getElementById('layer-ravi');
    const layerCog = document.getElementById('layer-cog');
    const layerPivotFloor = document.getElementById('layer-pivot-floor');
    const layerMassIndex = document.getElementById('layer-mass-index');
    // Priority-1 OsEngine индикаторы.
    const layerNrtrWatr = document.getElementById('layer-nrtr-watr');
    const layerPriceChannelAdaptive = document.getElementById('layer-price-channel-adaptive');
    const layerPriceChannelOffset = document.getElementById('layer-price-channel-offset');
    const layerParabolicBollinger = document.getElementById('layer-parabolic-bollinger');
    const layerParabolicPriceChannel = document.getElementById('layer-parabolic-price-channel');
    const layerAtrChannel = document.getElementById('layer-atr-channel');
    const layerOffsetSma = document.getElementById('layer-offset-sma');
    const layerOffsetEma = document.getElementById('layer-offset-ema');
    const layerOffsetSsma = document.getElementById('layer-offset-ssma');
    const layerVolatilityAverage = document.getElementById('layer-volatility-average');
    const layerVolatilityAverageChannel = document.getElementById('layer-volatility-average-channel');
    const layerPriceOscillator = document.getElementById('layer-price-oscillator');
    const layerDynamicTrendDetector = document.getElementById('layer-dynamic-trend-detector');
    const layerAdaptiveLookBack = document.getElementById('layer-adaptive-look-back');
    const layerLinRegChannelFast = document.getElementById('layer-linreg-channel-fast');
    const layerVolatilityCandles = document.getElementById('layer-volatility-candles');
    const layerZigZag = document.getElementById('layer-zigzag');
    const layerVolatilityAverageTwice = document.getElementById('layer-volatility-average-twice');
    const layerVolatilityStagesAw = document.getElementById('layer-volatility-stages-aw');
    const priceActionOverlay = document.getElementById('price-action-overlay');
    const liquiditySwingsOverlay = document.getElementById('liquidity-swings-overlay');
    const sweepRaidLiquidityOverlay = document.getElementById('sweep-raid-liquidity-overlay');
    const engulfingLiquidityOverlay = document.getElementById('engulfing-liquidity-overlay');
    const smcFlowOverlay = document.getElementById('smc-flow-overlay');
    const classicIndicatorLabelsEl = document.getElementById('classic-indicator-labels');
    const chartCrosshairOverlay = document.getElementById('chart-crosshair-overlay');
    const chartTimeRulerOverlay = document.getElementById('chart-time-ruler-overlay');
    const chartCrosshairHLine = document.getElementById('chart-crosshair-hline');
    const chartCrosshairVLine = document.getElementById('chart-crosshair-vline');
    const chartCrosshairPrice = document.getElementById('chart-crosshair-price');
    const chartCrosshairTime = document.getElementById('chart-crosshair-time');
    const instrumentSearchInput = document.getElementById('instrument-search');
    const instrumentSearchClearBtn = document.getElementById('instrument-search-clear');
    const instrumentListEl = document.getElementById('instrument-list');
    const showTradesToggle = document.getElementById('show-trades-toggle');
    const showTradesLabel = document.getElementById('show-trades-label');

    // Показ сделок на дашборде: localStorage, по умолчанию включено (как раньше).
    const SHOW_TRADES_STORAGE_KEY = 'platform.dashboard.showTrades';
    // Последняя выбранная пара и ТФ — чтобы перезагрузка не сбрасывала график.
    const SELECTED_INSTRUMENT_KEY = 'platform.dashboard.selectedInstrument';
    const SELECTED_TIMEFRAME_KEY = 'platform.dashboard.selectedTimeframe';

    /**
     * Читает предпочтение «показывать торговлю» из localStorage.
     * false / '0' / 'false' / 'off' → выкл; иначе вкл.
     */
    function loadShowTradesPref() {
        try {
            const v = localStorage.getItem(SHOW_TRADES_STORAGE_KEY);
            if (v == null) return true;
            return !(v === '0' || v === 'false' || v === 'off');
        } catch (_) {
            return true;
        }
    }

    function saveShowTradesPref(on) {
        try {
            localStorage.setItem(SHOW_TRADES_STORAGE_KEY, on ? '1' : '0');
        } catch (_) { /* ignore quota / private mode */ }
    }

    /** Сохраняет выбранный инструмент (символ пары). */
    function saveSelectedInstrument(instrument) {
        try {
            if (!instrument) return;
            localStorage.setItem(SELECTED_INSTRUMENT_KEY, String(instrument));
        } catch (_) { /* ignore */ }
    }

    /** @returns {string|null} */
    function loadSelectedInstrument() {
        try {
            const v = localStorage.getItem(SELECTED_INSTRUMENT_KEY);
            return v && String(v).trim() !== '' ? String(v).trim() : null;
        } catch (_) {
            return null;
        }
    }

    /**
     * Сохраняет таймфрейм (минуты или код секундного: 4005/4015/4030).
     * @param {number} tf
     */
    function saveSelectedTimeframe(tf) {
        try {
            if (!Number.isFinite(tf)) return;
            localStorage.setItem(SELECTED_TIMEFRAME_KEY, String(tf));
        } catch (_) { /* ignore */ }
    }

    /**
     * Читает сохранённый ТФ, только если такая кнопка есть в UI.
     * @returns {number|null}
     */
    function loadSelectedTimeframe() {
        try {
            const raw = localStorage.getItem(SELECTED_TIMEFRAME_KEY);
            if (raw == null || raw === '') return null;
            const tf = parseInt(raw, 10);
            if (!Number.isFinite(tf)) return null;
            const opt = document.querySelector('.tf-option[data-minutes="' + tf + '"]');
            return opt ? tf : null;
        } catch (_) {
            return null;
        }
    }

    /** Текущий флаг: рисовать ли сделки (маркеры + сайдбар). */
    let showTrades = loadShowTradesPref();

    /** Синхронизирует чекбокс/подпись с флагом showTrades. */
    function syncShowTradesToggleUi() {
        if (showTradesToggle) showTradesToggle.checked = showTrades;
        if (showTradesLabel) {
            showTradesLabel.textContent = 'Торговля';
            showTradesLabel.classList.toggle('text-zinc-400', showTrades);
            showTradesLabel.classList.toggle('text-zinc-500', !showTrades);
        }
    }

    /**
     * Убирает из сайдбара визуал открытых сделок (подсветка + таймер),
     * не трогая выбранный инструмент (синий bg-blue-900/40).
     */
    function hideSidebarTradeChrome() {
        document.querySelectorAll('.instrument-btn').forEach((btn) => {
            btn.classList.remove('bg-stone-800/45');
            btn.querySelectorAll('.instrument-trade-timer').forEach((el) => el.remove());
        });
    }

    /**
     * Вкл/выкл показа торговли: маркеры на графике + подсветка/таймеры в списке слева.
     * При выключении — сразу чистый вид; при включении — перезагрузка сайдбара и сделок.
     */
    function applyShowTradesMode(on, { persist = true } = {}) {
        showTrades = !!on;
        if (persist) saveShowTradesPref(showTrades);
        syncShowTradesToggleUi();

        if (!showTrades) {
            // Чистый график: не держим кэш сделок и не рисуем WIN/LOSE/OPEN.
            lastChartSignals = [];
            lastOpenTrades = [];
            // Инвалидируем late-ответы fetchChartTrades через общий helper.
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('chart-trades');
            // Убираем markers-plugin — с ним poll setData ломает canvas даже без маркеров.
            detachCandlestickMarkersPlugin();
            // После detach живой хвост снова через series.update(), не setData всего ряда.
            lastSyncedTailKey = '';
            pushCandlestickSeriesIfTailChanged({ preserveRange: true });
            hideSidebarTradeChrome();
            return;
        }

        // Снова показываем: обновляем список (таймеры/подсветка) и маркеры по инструменту.
        refreshInstrumentSidebar();
        fetchChartTrades();
    }

    // v4: classic OsEngine (bollinger/rsi/sma/ema/atr). При отсутствии ключа тянем v3 без потери старых prefs.
    const LAYER_PREFS_KEY = 'platform.dashboard.chartLayers.v5';

    function loadLayerPrefs() {
        try {
            let raw = localStorage.getItem(LAYER_PREFS_KEY);
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v4');
            }
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v3');
            }
            if (raw == null) {
                raw = localStorage.getItem('platform.dashboard.chartLayers.v2');
            }
            if (raw == null) {
                try {
                    const legacy = localStorage.getItem('platform.dashboard.chartLayers');
                    if (legacy == null) return;
                    const o = JSON.parse(legacy);
                    if (typeof o.orderBlocks === 'boolean') layerOb.checked = o.orderBlocks;
                    if (typeof o.imbalances === 'boolean') layerFvg.checked = o.imbalances;
                } catch (_) { /* ignore */ }
                return;
            }
            const o = JSON.parse(raw);
            if (typeof o.orderBlocks === 'boolean') layerOb.checked = o.orderBlocks;
            if (typeof o.imbalances === 'boolean') layerFvg.checked = o.imbalances;
            if (layerPa && typeof o.paDgt === 'boolean') layerPa.checked = o.paDgt;
            if (layerLiquidity && typeof o.liquiditySwings === 'boolean') layerLiquidity.checked = o.liquiditySwings;
            if (layerSweepRaid && typeof o.sweepRaidLiquidity === 'boolean') layerSweepRaid.checked = o.sweepRaidLiquidity;
            if (layerEngulfing && typeof o.engulfingLiquidity === 'boolean') layerEngulfing.checked = o.engulfingLiquidity;
            if (layerSmc && typeof o.smcFlow === 'boolean') layerSmc.checked = o.smcFlow;
            if (layerBollinger && typeof o.bollinger === 'boolean') layerBollinger.checked = o.bollinger;
            if (layerRsi && typeof o.rsi === 'boolean') layerRsi.checked = o.rsi;
            if (layerSma && typeof o.sma === 'boolean') layerSma.checked = o.sma;
            if (layerEma && typeof o.ema === 'boolean') layerEma.checked = o.ema;
            if (layerVwma && typeof o.vwma === 'boolean') layerVwma.checked = o.vwma;
            if (layerAtr && typeof o.atr === 'boolean') layerAtr.checked = o.atr;
            if (layerMacd && typeof o.macd === 'boolean') layerMacd.checked = o.macd;
            if (layerStochastic && typeof o.stochastic === 'boolean') layerStochastic.checked = o.stochastic;
            if (layerCci && typeof o.cci === 'boolean') layerCci.checked = o.cci;
            if (layerKeltner && typeof o.keltner === 'boolean') layerKeltner.checked = o.keltner;
            if (layerEnvelops && typeof o.envelops === 'boolean') layerEnvelops.checked = o.envelops;
            if (layerParabolicSar && typeof o.parabolicSar === 'boolean') layerParabolicSar.checked = o.parabolicSar;
            if (layerSuperTrend && typeof o.supertrend === 'boolean') layerSuperTrend.checked = o.supertrend;
            if (layerAdx && typeof o.adx === 'boolean') layerAdx.checked = o.adx;
            if (layerWilliams && typeof o.williams === 'boolean') layerWilliams.checked = o.williams;
            if (layerAo && typeof o.ao === 'boolean') layerAo.checked = o.ao;
            if (layerAlligator && typeof o.alligator === 'boolean') layerAlligator.checked = o.alligator;
            if (layerIchimoku && typeof o.ichimoku === 'boolean') layerIchimoku.checked = o.ichimoku;
            if (layerPriceChannel && typeof o.price_channel === 'boolean') layerPriceChannel.checked = o.price_channel;
            if (layerNrtr && typeof o.nrtr === 'boolean') layerNrtr.checked = o.nrtr;
            if (layerHma && typeof o.hma === 'boolean') layerHma.checked = o.hma;
            if (layerAroon && typeof o.aroon === 'boolean') layerAroon.checked = o.aroon;
            if (layerMomentum && typeof o.momentum === 'boolean') layerMomentum.checked = o.momentum;
            if (layerRoc && typeof o.roc === 'boolean') layerRoc.checked = o.roc;
            if (layerOsma && typeof o.osma === 'boolean') layerOsma.checked = o.osma;
            if (layerTrix && typeof o.trix === 'boolean') layerTrix.checked = o.trix;
            if (layerCmo && typeof o.cmo === 'boolean') layerCmo.checked = o.cmo;
            if (layerDemarker && typeof o.demarker === 'boolean') layerDemarker.checked = o.demarker;
            if (layerRvi && typeof o.rvi === 'boolean') layerRvi.checked = o.rvi;
            if (layerUltimate && typeof o.ultimate === 'boolean') layerUltimate.checked = o.ultimate;
            if (layerSmi && typeof o.smi === 'boolean') layerSmi.checked = o.smi;
            if (layerStdDev && typeof o.stddev === 'boolean') layerStdDev.checked = o.stddev;
            if (layerSmaChannel && typeof o.smaChannel === 'boolean') layerSmaChannel.checked = o.smaChannel;
            if (layerIvashov && typeof o.ivashov === 'boolean') layerIvashov.checked = o.ivashov;
            if (layerLinReg && typeof o.linreg === 'boolean') layerLinReg.checked = o.linreg;
            // Совместимость: раньше ключ был camelCase linregChannel, теперь snake_case как в API.
            if (layerLinRegChannel) {
                if (typeof o.linreg_channel === 'boolean') layerLinRegChannel.checked = o.linreg_channel;
                else if (typeof o.linregChannel === 'boolean') layerLinRegChannel.checked = o.linregChannel;
            }
            if (layerKalman && typeof o.kalman === 'boolean') layerKalman.checked = o.kalman;
            if (layerFractal && typeof o.fractal === 'boolean') layerFractal.checked = o.fractal;
            if (layerStratAssistant && typeof o.strat_assistant === 'boolean') layerStratAssistant.checked = o.strat_assistant;
            if (layerDonchian && typeof o.donchian === 'boolean') layerDonchian.checked = o.donchian;
            if (layerStochRsi && typeof o.stoch_rsi === 'boolean') layerStochRsi.checked = o.stoch_rsi;
            if (layerAc && typeof o.ac === 'boolean') layerAc.checked = o.ac;
            if (layerBullsPower && typeof o.bulls_power === 'boolean') layerBullsPower.checked = o.bulls_power;
            if (layerBearsPower && typeof o.bears_power === 'boolean') layerBearsPower.checked = o.bears_power;
            if (layerDpo && typeof o.dpo === 'boolean') layerDpo.checked = o.dpo;
            if (layerQstick && typeof o.qstick === 'boolean') layerQstick.checked = o.qstick;
            if (layerVhFilter && typeof o.vh_filter === 'boolean') layerVhFilter.checked = o.vh_filter;
            if (layerEfficiencyRatio && typeof o.efficiency_ratio === 'boolean') layerEfficiencyRatio.checked = o.efficiency_ratio;
            if (layerRavi && typeof o.ravi === 'boolean') layerRavi.checked = o.ravi;
            if (layerCog && typeof o.cog === 'boolean') layerCog.checked = o.cog;
            if (layerPivotFloor && typeof o.pivot_floor === 'boolean') layerPivotFloor.checked = o.pivot_floor;
            if (layerMassIndex && typeof o.mass_index === 'boolean') layerMassIndex.checked = o.mass_index;
            if (layerNrtrWatr && typeof o.nrtr_watr === 'boolean') layerNrtrWatr.checked = o.nrtr_watr;
            if (layerPriceChannelAdaptive && typeof o.price_channel_adaptive === 'boolean') layerPriceChannelAdaptive.checked = o.price_channel_adaptive;
            if (layerPriceChannelOffset && typeof o.price_channel_offset === 'boolean') layerPriceChannelOffset.checked = o.price_channel_offset;
            if (layerParabolicBollinger && typeof o.parabolic_bollinger === 'boolean') layerParabolicBollinger.checked = o.parabolic_bollinger;
            if (layerParabolicPriceChannel && typeof o.parabolic_price_channel === 'boolean') layerParabolicPriceChannel.checked = o.parabolic_price_channel;
            if (layerAtrChannel && typeof o.atr_channel === 'boolean') layerAtrChannel.checked = o.atr_channel;
            if (layerOffsetSma && typeof o.offset_sma === 'boolean') layerOffsetSma.checked = o.offset_sma;
            if (layerOffsetEma && typeof o.offset_ema === 'boolean') layerOffsetEma.checked = o.offset_ema;
            if (layerOffsetSsma && typeof o.offset_ssma === 'boolean') layerOffsetSsma.checked = o.offset_ssma;
            if (layerVolatilityAverage && typeof o.volatility_average === 'boolean') layerVolatilityAverage.checked = o.volatility_average;
            if (layerVolatilityAverageChannel && typeof o.volatility_average_channel === 'boolean') layerVolatilityAverageChannel.checked = o.volatility_average_channel;
            if (layerPriceOscillator && typeof o.price_oscillator === 'boolean') layerPriceOscillator.checked = o.price_oscillator;
            if (layerDynamicTrendDetector && typeof o.dynamic_trend_detector === 'boolean') layerDynamicTrendDetector.checked = o.dynamic_trend_detector;
            if (layerAdaptiveLookBack && typeof o.adaptive_look_back === 'boolean') layerAdaptiveLookBack.checked = o.adaptive_look_back;
            if (layerLinRegChannelFast && typeof o.linreg_channel_fast === 'boolean') layerLinRegChannelFast.checked = o.linreg_channel_fast;
            if (layerVolatilityCandles && typeof o.volatility_candles === 'boolean') layerVolatilityCandles.checked = o.volatility_candles;
            if (layerZigZag && typeof o.zigzag === 'boolean') layerZigZag.checked = o.zigzag;
            if (layerVolatilityAverageTwice && typeof o.volatility_average_twice === 'boolean') layerVolatilityAverageTwice.checked = o.volatility_average_twice;
            if (layerVolatilityStagesAw && typeof o.volatility_stages_aw === 'boolean') layerVolatilityStagesAw.checked = o.volatility_stages_aw;
        } catch (_) { /* ignore */ }
    }

    function saveLayerPrefs() {
        try {
            localStorage.setItem(LAYER_PREFS_KEY, JSON.stringify({
                orderBlocks: layerOb.checked,
                imbalances: layerFvg.checked,
                paDgt: layerPa ? layerPa.checked : false,
                liquiditySwings: layerLiquidity ? layerLiquidity.checked : false,
                // Отдельный ключ не связывает видимость Sweep / Raid с похожим Liquidity Swings.
                sweepRaidLiquidity: layerSweepRaid ? layerSweepRaid.checked : false,
                engulfingLiquidity: layerEngulfing ? layerEngulfing.checked : false,
                smcFlow: layerSmc ? layerSmc.checked : false,
                bollinger: layerBollinger ? layerBollinger.checked : false,
                rsi: layerRsi ? layerRsi.checked : false,
                sma: layerSma ? layerSma.checked : false,
                ema: layerEma ? layerEma.checked : false,
                vwma: layerVwma ? layerVwma.checked : false,
                atr: layerAtr ? layerAtr.checked : false,
                macd: layerMacd ? layerMacd.checked : false,
                stochastic: layerStochastic ? layerStochastic.checked : false,
                cci: layerCci ? layerCci.checked : false,
                keltner: layerKeltner ? layerKeltner.checked : false,
                envelops: layerEnvelops ? layerEnvelops.checked : false,
                parabolicSar: layerParabolicSar ? layerParabolicSar.checked : false,
                supertrend: layerSuperTrend ? layerSuperTrend.checked : false,
                adx: layerAdx ? layerAdx.checked : false,
                williams: layerWilliams ? layerWilliams.checked : false,
                ao: layerAo ? layerAo.checked : false,
                alligator: layerAlligator ? layerAlligator.checked : false,
                ichimoku: layerIchimoku ? layerIchimoku.checked : false,
                price_channel: layerPriceChannel ? layerPriceChannel.checked : false,
                nrtr: layerNrtr ? layerNrtr.checked : false,
                hma: layerHma ? layerHma.checked : false,
                aroon: layerAroon ? layerAroon.checked : false,
                momentum: layerMomentum ? layerMomentum.checked : false,
                roc: layerRoc ? layerRoc.checked : false,
                osma: layerOsma ? layerOsma.checked : false,
                trix: layerTrix ? layerTrix.checked : false,
                cmo: layerCmo ? layerCmo.checked : false,
                demarker: layerDemarker ? layerDemarker.checked : false,
                rvi: layerRvi ? layerRvi.checked : false,
                ultimate: layerUltimate ? layerUltimate.checked : false,
                smi: layerSmi ? layerSmi.checked : false,
                stddev: layerStdDev ? layerStdDev.checked : false,
                smaChannel: layerSmaChannel ? layerSmaChannel.checked : false,
                ivashov: layerIvashov ? layerIvashov.checked : false,
                linreg: layerLinReg ? layerLinReg.checked : false,
                linregChannel: layerLinRegChannel ? layerLinRegChannel.checked : false,
                kalman: layerKalman ? layerKalman.checked : false,
                fractal: layerFractal ? layerFractal.checked : false,
                strat_assistant: layerStratAssistant ? layerStratAssistant.checked : false,
                donchian: layerDonchian ? layerDonchian.checked : false,
                stoch_rsi: layerStochRsi ? layerStochRsi.checked : false,
                ac: layerAc ? layerAc.checked : false,
                bulls_power: layerBullsPower ? layerBullsPower.checked : false,
                bears_power: layerBearsPower ? layerBearsPower.checked : false,
                dpo: layerDpo ? layerDpo.checked : false,
                qstick: layerQstick ? layerQstick.checked : false,
                vh_filter: layerVhFilter ? layerVhFilter.checked : false,
                efficiency_ratio: layerEfficiencyRatio ? layerEfficiencyRatio.checked : false,
                ravi: layerRavi ? layerRavi.checked : false,
                cog: layerCog ? layerCog.checked : false,
                pivot_floor: layerPivotFloor ? layerPivotFloor.checked : false,
                mass_index: layerMassIndex ? layerMassIndex.checked : false,
                nrtr_watr: layerNrtrWatr ? layerNrtrWatr.checked : false,
                price_channel_adaptive: layerPriceChannelAdaptive ? layerPriceChannelAdaptive.checked : false,
                price_channel_offset: layerPriceChannelOffset ? layerPriceChannelOffset.checked : false,
                parabolic_bollinger: layerParabolicBollinger ? layerParabolicBollinger.checked : false,
                parabolic_price_channel: layerParabolicPriceChannel ? layerParabolicPriceChannel.checked : false,
                atr_channel: layerAtrChannel ? layerAtrChannel.checked : false,
                offset_sma: layerOffsetSma ? layerOffsetSma.checked : false,
                offset_ema: layerOffsetEma ? layerOffsetEma.checked : false,
                offset_ssma: layerOffsetSsma ? layerOffsetSsma.checked : false,
                volatility_average: layerVolatilityAverage ? layerVolatilityAverage.checked : false,
                volatility_average_channel: layerVolatilityAverageChannel ? layerVolatilityAverageChannel.checked : false,
                price_oscillator: layerPriceOscillator ? layerPriceOscillator.checked : false,
                dynamic_trend_detector: layerDynamicTrendDetector ? layerDynamicTrendDetector.checked : false,
                adaptive_look_back: layerAdaptiveLookBack ? layerAdaptiveLookBack.checked : false,
                linreg_channel_fast: layerLinRegChannelFast ? layerLinRegChannelFast.checked : false,
                volatility_candles: layerVolatilityCandles ? layerVolatilityCandles.checked : false,
                zigzag: layerZigZag ? layerZigZag.checked : false,
                volatility_average_twice: layerVolatilityAverageTwice ? layerVolatilityAverageTwice.checked : false,
                volatility_stages_aw: layerVolatilityStagesAw ? layerVolatilityStagesAw.checked : false,
            }));
        } catch (_) { /* ignore */ }
    }

    loadLayerPrefs();
    // PR4 trade-lite: убрать oscillator panes (Vol Candles/RSI/…) с торгового экрана.
    if (window.PlatformChartTradeLite && PlatformChartTradeLite.isEnabled(boot)) {
        PlatformChartTradeLite.stripOscillatorLayers(true);
    }
    let chart = null;
    let candlestickSeries = null;
    /**
     * Единый markers-plugin v5 (как на странице бота).
     * Сейчас dashboard не рисует торговые маркеры, но держим ту же точку расширения:
     * при добавлении markers достаточно вызвать PlatformChartMarkers.applyMarkers(...).
     */
    let candlestickMarkers = null;
    /**
     * Плавный close последней свечи (PlatformCandleSmooth): target из poll,
     * визуал — RAF lerp, allChartData всегда хранит серверные OHLC.
     */
    let candleSmooth = null;
    let currentInstrument = null;
    let currentDisplayName = '';
    let currentTimeframeMinutes = 1;
    let oldestLoadedTime = null;
    let allChartData = [];
    /** Сколько баров реально лежит в candlestickSeries LWC (для детекта рассинхрона с allChartData). */
    let candleSeriesBarCount = 0;
    /** OHLC хвоста (2 бара), уже отданного в LWC — poll без series.update(). */
    let lastSyncedTailKey = '';
    /** Poll merge: только последние N баров; шире — сервер ревизит OHLC и ломает canvas LWC. */
    const POLL_MERGE_TAIL_BARS = 2;
    /** Throttle setData формирующегося бара — не чаще 1 раз/с (новая свеча — сразу). */
    const TAIL_SERIES_UPDATE_MIN_MS = 1000;
    let lastTailSeriesUpdateMs = 0;
    /** Периодический heal: LWC v5 иногда теряет тела свечей даже при корректном setData. */
    const CANDLE_HEAL_RESYNC_MS = 30000;
    let candleHealInterval = null;
    /** Авто-следование за «живым» хвостом; сбрасывается при ручном pan/zoom пользователя. */
    let tailFollowActive = true;
    /** Счётчик подавления: setVisibleLogicalRange из кода, не от пользователя. */
    let viewportChangeSuppressDepth = 0;
    /** setData/update серии — не считаем ручным pan/zoom. */
    let dataSeriesUpdateDepth = 0;
    let loadingMore = false;
    let refreshInterval = null;
    /** PR2: WS live client (null пока не стартовали / флаг выключен). */
    let chartLiveClient = null;
    /** Смена инструмента/TF: игнорируем устаревшие ответы fetchCandles. */
    let chartLoadSeq = 0;
    /** Инкремент при каждом poll и при смене пары — отбрасываем in-flight poll. */
    let pollSeq = 0;
    /** true между setData и фиксацией viewport — poll не дергает maintainTail/setData. */
    let chartViewportSettling = false;
    // Отдельный таймер для тяжёлых оверлеев (структуры и Price Action DGT).
    // Они меняются редко, но каждый запрос /structures и /indicators/price-action-dgt
    // заметно нагружает сервер (последний при дефолтных параметрах тянет до 5000 свечей
    // и ~46 KB ответа). Если опрашивать их в общем 500-мс цикле, fetch-pool браузера
    // быстро переполняется (net::ERR_INSUFFICIENT_RESOURCES), запросы /candles стоят
    // в очереди и при переключении инструмента график «висит» на «Загрузка…», даже
    // если бэкенд готов отдать данные мгновенно.
    let overlaysRefreshInterval = null;
    // Базовый шаг обновления оверлеев — 15 сек. Зоны/индикатор за такое время
    // успевают сменить статус, а нагрузка на БД и сеть кратно ниже.
    const OVERLAYS_REFRESH_MS = 15000;
    let instrumentListRefreshInterval = null;
    let sidebarTimerInterval = null;
    let lastZones = [];
    let lastPaPayload = null;
    /** JSON с сервера: boxes / lines / labels для Liquidity Swings. */
    let lastLsPayload = null;
    /** События общего детектора: sweep/raid и первые pullback-входы. */
    let lastSweepRaidPayload = null;
    let lastEngulfingPayload = null;
    let lastSmcPayload = null;
    /** Сигналы/сделки для маркеров (формат как на bots/show). */
    let lastChartSignals = [];
    let lastOpenTrades = [];
    /** Payloads классики OsEngine (BB/RSI/SMA/EMA/VWMA/ATR). */
    let lastBollingerPayload = null;
    let lastRsiPayload = null;
    let lastSmaPayload = null;
    let lastEmaPayload = null;
    let lastVwmaPayload = null;
    let lastAtrPayload = null;
    let lastMacdPayload = null;
    let lastStochasticPayload = null;
    let lastCciPayload = null;
    let lastKeltnerPayload = null;
    let lastEnvelopsPayload = null;
    let lastParabolicSarPayload = null;
    let lastSuperTrendPayload = null;
    let lastAdxPayload = null;
    let lastWilliamsPayload = null;
    let lastAoPayload = null;
    let lastAlligatorPayload = null;
    let lastIchimokuPayload = null;
    let lastPriceChannelPayload = null;
    let lastNrtrPayload = null;
    let lastHmaPayload = null;
    let lastAroonPayload = null;
    // 16 новых классических индикаторов OsEngine.
    let lastMomentumPayload = null;
    let lastRocPayload = null;
    let lastOsmaPayload = null;
    let lastTrixPayload = null;
    let lastCmoPayload = null;
    let lastDemarkerPayload = null;
    let lastRviPayload = null;
    let lastUltimatePayload = null;
    let lastSmiPayload = null;
    let lastStdDevPayload = null;
    let lastSmaChannelPayload = null;
    let lastIvashovPayload = null;
    let lastLinRegPayload = null;
    let lastLinRegChannelPayload = null;
    let lastKalmanPayload = null;
    let lastFractalPayload = null;
    let lastStratPayload = null;

    /**
     * Клиентские индикаторы (Strat/LS/PA/SMC + classic subset) — без HTTP.
     * @param {{skipRender?: boolean, light?: boolean}} [opts]
     * light=true — Strat+classic на in-bar тиках; PA/LS/SMC — на новом баре.
     */
    function recomputeClientIndicators(opts) {
        const skipRender = !!(opts && opts.skipRender);
        const light = !!(opts && opts.light);
        if (!allChartData.length) {
            if (!skipRender) {
                if (typeof renderChartMarkers === 'function') renderChartMarkers();
                if (typeof redrawPriceActionOverlay === 'function') redrawPriceActionOverlay();
                if (typeof redrawLiquiditySwingsOverlay === 'function') redrawLiquiditySwingsOverlay();
                if (typeof redrawSmcFlowOverlay === 'function') redrawSmcFlowOverlay();
                if (typeof applyClassicOsEngineSeries === 'function') applyClassicOsEngineSeries();
            }
            return;
        }
        const p = clientIndicatorParams || {};

        if (layerStratAssistant && layerStratAssistant.checked && window.PlatformStratAssistant) {
            lastStratPayload = PlatformStratAssistant.compute(
                allChartData,
                p.strat_assistant || PlatformStratAssistant.DEFAULT_PARAMS
            );
        } else {
            lastStratPayload = null;
        }

        if (!light) {
            if (layerLiquidity && layerLiquidity.checked && window.PlatformLiquiditySwings) {
                lastLsPayload = PlatformLiquiditySwings.compute(allChartData, p.liquidity_swings || null);
            } else if (!(layerLiquidity && layerLiquidity.checked)) {
                lastLsPayload = null;
            }

            if (layerPa && layerPa.checked && window.PlatformPriceActionDgt) {
                const barSec = typeof timeframeSeconds === 'function'
                    ? timeframeSeconds(currentTimeframeMinutes)
                    : 60;
                lastPaPayload = PlatformPriceActionDgt.compute(
                    allChartData,
                    p.price_action_dgt || null,
                    barSec
                );
            } else if (!(layerPa && layerPa.checked)) {
                lastPaPayload = null;
            }

            if (layerSmc && layerSmc.checked && window.PlatformSmcFlowCompute) {
                lastSmcPayload = PlatformSmcFlowCompute.compute(allChartData, p.smc_flow || null);
            } else if (!(layerSmc && layerSmc.checked)) {
                lastSmcPayload = null;
            }
        }

        const CI = window.PlatformClassicIndicators;
        if (CI && typeof CI.computeAll === 'function') {
            const wants = {
                sma: !!(layerSma && layerSma.checked),
                ema: !!(layerEma && layerEma.checked),
                rsi: !!(layerRsi && layerRsi.checked),
                bollinger: !!(layerBollinger && layerBollinger.checked),
                atr: !!(layerAtr && layerAtr.checked),
                macd: !!(layerMacd && layerMacd.checked),
                vwma: !!(layerVwma && layerVwma.checked),
                stochastic: !!(layerStochastic && layerStochastic.checked),
            };
            const classic = CI.computeAll(allChartData, wants, {
                sma: p.sma,
                ema: p.ema,
                rsi: p.rsi,
                bollinger: p.bollinger,
                atr: p.atr,
                macd: p.macd,
                vwma: p.vwma,
                stochastic: p.stochastic,
            });
            lastSmaPayload = wants.sma ? (classic.sma || null) : null;
            lastEmaPayload = wants.ema ? (classic.ema || null) : null;
            lastRsiPayload = wants.rsi ? (classic.rsi || null) : null;
            lastBollingerPayload = wants.bollinger ? (classic.bollinger || null) : null;
            lastAtrPayload = wants.atr ? (classic.atr || null) : null;
            lastMacdPayload = wants.macd ? (classic.macd || null) : null;
            lastVwmaPayload = wants.vwma ? (classic.vwma || null) : null;
            lastStochasticPayload = wants.stochastic ? (classic.stochastic || null) : null;
        }

        if (!skipRender) {
            if (!light) {
                if (typeof applyPaBarColors === 'function') {
                    applyPaBarColors({ fullResync: !!(layerPa && layerPa.checked) });
                }
                if (typeof redrawPriceActionOverlay === 'function') redrawPriceActionOverlay();
                if (typeof redrawLiquiditySwingsOverlay === 'function') redrawLiquiditySwingsOverlay();
                if (typeof redrawSmcFlowOverlay === 'function') redrawSmcFlowOverlay();
                // Classic rebuild (clear+addPane+resync setData) нельзя на in-bar тиках:
                // resyncCandlestickSeriesFromAllChartData → reset/snap убивает PlatformCandleSmooth.
                // На light обновляем только payload; series.apply — на новом баре / toggle.
                if (typeof applyClassicOsEngineSeries === 'function') applyClassicOsEngineSeries();
            } else {
                // In-bar: setData на существующих series — без addPane/resync свечей.
                patchClientClassicSeriesFromPayloads();
            }
            if (typeof renderChartMarkers === 'function') renderChartMarkers();
        }
    }

    /** @deprecated alias */
    function recomputeClientStrat(opts) {
        recomputeClientIndicators(opts);
    }
    let lastDonchianPayload = null;
    let lastStochRsiPayload = null;
    let lastAcPayload = null;
    let lastBullsPowerPayload = null;
    let lastBearsPowerPayload = null;
    let lastDpoPayload = null;
    let lastQstickPayload = null;
    let lastVhFilterPayload = null;
    let lastEfficiencyRatioPayload = null;
    let lastRaviPayload = null;
    let lastCogPayload = null;
    let lastPivotFloorPayload = null;
    let lastMassIndexPayload = null;
    // Priority-1 payloads.
    let lastNrtrWatrPayload = null;
    let lastPriceChannelAdaptivePayload = null;
    let lastPriceChannelOffsetPayload = null;
    let lastParabolicBollingerPayload = null;
    let lastParabolicPriceChannelPayload = null;
    let lastAtrChannelPayload = null;
    let lastOffsetSmaPayload = null;
    let lastOffsetEmaPayload = null;
    let lastOffsetSsmaPayload = null;
    let lastVolatilityAveragePayload = null;
    let lastVolatilityAverageChannelPayload = null;
    let lastPriceOscillatorPayload = null;
    let lastDynamicTrendDetectorPayload = null;
    let lastAdaptiveLookBackPayload = null;
    let lastLinRegChannelFastPayload = null;
    let lastVolatilityCandlesPayload = null;
    let lastZigZagPayload = null;
    let lastVolatilityAverageTwicePayload = null;
    let lastVolatilityStagesAwPayload = null;
    let classicLineSeries = {};
    let rsiLevelLines = [];
    let classicOscPaneCount = 0;
    /** overlays — основная pane; oscillators — в порядке addPane (pane 1..n). */
    let lastClassicNameState = { overlays: [], oscillators: [] };
    /** Сигнатура включённых classic-слоёв: при неизменной — patch setData без removePane. */
    let lastClassicLayersSig = '';
    /** Throttle DOM-оверлеев от attachPrimitive (poll/update не должен пересобирать DOM каждый кадр). */
    const CHART_DOM_OVERLAY_SYNC_MIN_MS = Math.max(100, Number(boot.domOverlaySyncMinMs) || 500);
    let chartDomOverlaySyncLastMs = 0;
    /** RAF-tween close → series.update(). FPS ограничен boot.smoothMaxFps (PR3). */
    const CHART_LIVE_SMOOTH_CLOSE = true;
    /** Флаг «грязных» HTML-оверлеев: attachPrimitive только помечает, DOM — раз в CHART_DOM_OVERLAY_TICK_MS. */
    let chartDomOverlayDirty = false;
    let chartDomOverlayTickTimer = null;
    const CHART_DOM_OVERLAY_TICK_MS = Math.max(250, Number(boot.domOverlayTickMs) || 1000);
    /** HTML-линейка секунд. PR3b: off по умолчанию (нативная ось LWC); включить CHART_HTML_TIME_RULER=true. */
    const CHART_HTML_TIME_RULER_ENABLED = boot.htmlTimeRuler === true;

    /**
     * Названия structural-слоёв (OB/FVG/PA/…) при включённом чекбоксе.
     * @returns {Array<{name:string,color:string,layerId:string,settingsUrl:?string}>}
     */
    function structureLayerNameLabels() {
        const out = [];
        const add = (layerId, fallbackName) => {
            const cb = document.getElementById(layerId);
            if (!cb || !cb.checked) return;
            const meta = (window.PLATFORM_INDICATOR_LAYER_META && window.PLATFORM_INDICATOR_LAYER_META[layerId]) || {};
            out.push({
                name: meta.label || fallbackName,
                color: '#f4f4f5',
                layerId,
                settingsUrl: meta.settingsUrl || null,
            });
        };
        add('layer-ob', 'Ордерблоки');
        add('layer-fvg', 'Имбалансы');
        add('layer-pa-dgt', 'Price Action (DGT)');
        add('layer-liquidity-swings', 'Liquidity Swings');
        add('layer-sweep-raid-liquidity', 'Sweep / Raid Liquidity');
        add('layer-engulfing-liquidity', 'Engulfing Liquidity');
        add('layer-smc-flow', 'SMC Flow');
        return out;
    }

    /**
     * Лейбл классики: title из API («RSI 14») + слой для иконок карандаш/×.
     * @returns @{{name:string,color:string,layerId:string,settingsUrl:?string}|null}
     */
    function classicIndicatorLabel(on, payload, fallbackName, layerId) {
        if (!on) return null;
        const meta = (window.PLATFORM_INDICATOR_LAYER_META && window.PLATFORM_INDICATOR_LAYER_META[layerId]) || {};
        const title = payload && payload.title ? String(payload.title) : fallbackName;
        return {
            name: title,
            color: '#f4f4f5',
            layerId: layerId || null,
            settingsUrl: meta.settingsUrl || null,
        };
    }

    /** DOM-бейджи названий индикаторов (structural + classic). */
    function renderAllIndicatorNameLabels() {
        if (!window.PlatformChartMarkers
            || typeof window.PlatformChartMarkers.renderIndicatorNameLabels !== 'function') {
            return;
        }
        window.PlatformChartMarkers.renderIndicatorNameLabels({
            chart,
            container: classicIndicatorLabelsEl,
            mainLabels: [
                ...structureLayerNameLabels(),
                ...(lastClassicNameState.overlays || []),
            ],
            oscLabels: lastClassicNameState.oscillators || [],
        });
    }

    function payoutClassByValue(value) {
        if (value == null || Number.isNaN(value)) return 'text-zinc-500';
        if (value >= 80) return 'text-emerald-400';
        if (value >= 60) return 'text-amber-400';
        return 'text-rose-400';
    }

    /**
     * WS op=payout: точечно обновляем % в сайдбаре (и панель CALL/PUT),
     * без полного GET /instruments/data.
     */
    function applyInstrumentPayoutLive(msg) {
        const instrument = String(msg?.instrument || '').trim();
        if (!instrument) return;
        const raw = msg.current_payout;
        const n = (raw === null || raw === undefined) ? null : Number(raw);
        const span = document.querySelector(
            '.instrument-payout[data-instrument="' + CSS.escape(instrument) + '"]'
        );
        if (span) {
            span.dataset.payout = (n == null || Number.isNaN(n)) ? '' : String(n);
            span.textContent = (n == null || Number.isNaN(n)) ? '—' : `${Math.round(n)}%`;
            span.className = 'instrument-payout text-xs font-medium ' + payoutClassByValue(n);
        }
        if (instrument === currentInstrument && typeof updateManualTradePayout === 'function') {
            updateManualTradePayout();
        }
    }

    /** Интервал HTTP сайдбара: при live — редкий heal (open_trade через bot_event). */
    function instrumentsSidebarPollMs() {
        if (boot.liveEnabled && boot.liveWsUrl && boot.liveToken) {
            let ms = Number(boot.instrumentsRefreshMsLive);
            if (!Number.isFinite(ms) || ms < 0) ms = 120000;
            if (ms === 0) return 0; // caller должен не ставить setInterval
            return Math.max(30000, ms);
        }
        return Math.max(1000, Number(boot.instrumentsRefreshMs) || 5000);
    }

    /** bot_event → флаги open_trade в сайдбаре dashboard (все боты пользователя). */
    function applyDashSidebarBotEvent(msg) {
        if (!msg || typeof msg !== 'object') return;
        const myUserId = Number(boot.userId || 0);
        if (myUserId > 0 && Number(msg.user_id || 0) !== myUserId) return;
        const openMap = msg.open_instruments_user;
        if (!openMap || typeof openMap !== 'object') return;
        document.querySelectorAll('.instrument-btn').forEach((btn) => {
            const inst = btn.dataset.instrument;
            if (!inst) return;
            const expiry = openMap[inst];
            const isOpen = expiry != null && Number(expiry) > 0;
            btn.dataset.openTrade = isOpen ? '1' : '0';
            const showOpenTrade = !!(showTrades && isOpen);
            btn.classList.toggle('bg-stone-800/45', showOpenTrade);
            const nameWrap = btn.querySelector('.flex.min-w-0');
            if (!nameWrap) return;
            let timer = nameWrap.querySelector('.instrument-trade-timer');
            if (showOpenTrade) {
                if (!timer) {
                    timer = document.createElement('span');
                    timer.className = 'instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200';
                    nameWrap.appendChild(timer);
                }
                timer.dataset.expiryTs = String(expiry);
                timer.textContent = formatMmSs(Number(expiry) - serverUnixNow());
            } else if (timer) {
                timer.remove();
            }
        });
    }
    window.__dashSidebarOnBotEvent = applyDashSidebarBotEvent;

    function formatMmSs(totalSeconds) {
        const sec = Math.max(0, Math.floor(totalSeconds));
        const mm = Math.floor(sec / 60);
        const ss = sec % 60;
        return String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
    }

    /** Binodex candle timer: «13s»; от 60с — «1:05» без ведущего нуля минут. */
    function formatCandleCloseLeft(totalSeconds) {
        const sec = Math.max(0, Math.floor(Number(totalSeconds) || 0));
        if (sec < 60) {
            return sec + 's';
        }
        const mm = Math.floor(sec / 60);
        const ss = sec % 60;
        return mm + ':' + String(ss).padStart(2, '0');
    }

    /**
     * Fallback countdown от time последней свечи (когда серверный якорь = 0 / протух).
     */
    function candleCloseLeftFromLastBarOpen(tf) {
        if (!allChartData.length) return null;
        const lastT = Number(allChartData[allChartData.length - 1].time);
        const period = timeframeSeconds(tf);
        if (!Number.isFinite(lastT) || period <= 0) return null;
        const elapsed = Math.max(0, serverUnixNow() - lastT);
        const into = elapsed % period;
        return into === 0 ? period : period - into;
    }

    function tickSidebarTradeTimers() {
        const nowSec = serverUnixNow();
        document.querySelectorAll('.instrument-trade-timer[data-expiry-ts]').forEach((el) => {
            const ts = Number(el.dataset.expiryTs);
            if (!Number.isFinite(ts)) return;
            el.textContent = formatMmSs(ts - nowSec);
        });
    }

    function positionCandleTimerOverlay() {
        if (!candleTimerOverlay || !chart || !candlestickSeries || !allChartData.length) {
            if (candleTimerOverlay) {
                candleTimerOverlay.classList.add('hidden');
            }
            return;
        }
        const last = allChartData[allChartData.length - 1];
        const lastT = Number(last.time);
        if (!Number.isFinite(lastT)) {
            candleTimerOverlay.classList.add('hidden');
            return;
        }
        // Y = та же цена, что у полоски last price (smooth displayClose, иначе last.close).
        let closePx = Number(last.close);
        if (candleSmooth && typeof candleSmooth.getDisplayClose === 'function') {
            const vis = Number(candleSmooth.getDisplayClose());
            if (Number.isFinite(vis)) closePx = vis;
        }
        let yPrice = candlestickSeries.priceToCoordinate(closePx);
        if (yPrice == null || Number.isNaN(yPrice)) {
            // На кадрах зума/пересчёта price scale LWC иногда отдаёт null —
            // не гасим бейдж, держим прошлый top.
            const prevTop = parseFloat(candleTimerOverlay.style.top);
            if (Number.isFinite(prevTop)) {
                yPrice = prevTop;
            } else {
                return;
            }
        }
        const ts = chart.timeScale();
        const lastIdx = allChartData.length - 1;
        const bsRaw = typeof ts.barSpacing === 'function' ? ts.barSpacing() : null;
        const bs = Number.isFinite(bsRaw) && bsRaw > 0 ? bsRaw : 6;

        // Логический индекс стабильнее timeToCoordinate при анимации зума LWC.
        let xCenter = null;
        if (typeof ts.logicalToCoordinate === 'function') {
            xCenter = ts.logicalToCoordinate(lastIdx);
        }
        if (xCenter == null || Number.isNaN(xCenter)) {
            xCenter = ts.timeToCoordinate(lastT);
        }
        if (xCenter == null || Number.isNaN(xCenter)) {
            // Не мигаем hidden на промежуточном кадре зума — оставляем прошлую позицию.
            return;
        }
        let xRight = xCenter + Math.min(bs * 0.45, 16) + CANDLE_TIMER_GAP_PX;
        const plotW = chartWrapper ? chartWrapper.clientWidth : 0;
        if (plotW > 0) {
            // Прячем только если центр бара далеко вне экрана (не из‑за широкого barSpacing).
            if (xCenter < -80 || xCenter > plotW + 80) {
                candleTimerOverlay.classList.add('hidden');
                return;
            }
            xRight = Math.max(4, Math.min(xRight, plotW - 8));
        }
        candleTimerOverlay.classList.remove('hidden');
        candleTimerOverlay.style.left = xRight + 'px';
        // top = линия last price; pill целиком НАД полоской (px, не %).
        let pillH = 18;
        if (candleCloseTimerEl) {
            const measured = candleCloseTimerEl.offsetHeight;
            if (Number.isFinite(measured) && measured > 0) pillH = measured;
            candleCloseTimerEl.style.transform = 'translateY(-' + (pillH + CANDLE_TIMER_ABOVE_LINE_PX) + 'px)';
        }
        candleTimerOverlay.style.top = Math.max(0, yPrice) + 'px';
    }

    function tickCandleCloseTimer() {
        if (!candleCloseTimerEl) return;
        if (!currentInstrument || !candlestickSeries || !allChartData.length) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        const lastT = Number(allChartData[allChartData.length - 1].time);
        if (!Number.isFinite(lastT)) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        const leftRaw = currentTimeframeMinutes === 1
            ? m1CountdownLeftNow()
            : genericCountdownLeftNow(currentTimeframeMinutes);
        let left = leftRaw;
        if (left === null || left <= 0) {
            const fallback = candleCloseLeftFromLastBarOpen(currentTimeframeMinutes);
            if (fallback !== null) left = fallback;
        }
        if (left === null) {
            if (candleTimerOverlay) candleTimerOverlay.classList.add('hidden');
            candleCloseTimerEl.textContent = '';
            return;
        }
        candleCloseTimerEl.textContent = formatCandleCloseLeft(left);
        scheduleCandleTimerSync();
    }

    /**
     * Обновляет payout/таймеры в уже отрисованном списке без replaceChildren().
     * Полная пересборка каждую секунду давала layout-reflow и chart.resize() → «чёрная полоса» под графиком.
     */
    function patchInstrumentSidebarRows(instruments) {
        if (!instrumentListEl || !instruments.length) return false;
        const btns = instrumentListEl.querySelectorAll('.instrument-btn');
        if (btns.length !== instruments.length) return false;
        const nowSec = serverUnixNow();
        for (let i = 0; i < instruments.length; i++) {
            const inst = instruments[i];
            const btn = btns[i];
            if (!btn || btn.dataset.instrument !== inst.instrument) return false;
            const showOpenTrade = !!(showTrades && inst.open_trade);
            btn.classList.toggle('bg-stone-800/45', showOpenTrade);
            btn.dataset.openTrade = inst.open_trade ? '1' : '0';
            const payout = btn.querySelector('.instrument-payout');
            if (payout) {
                const pv = inst.current_payout;
                const n = (pv === null || pv === undefined) ? null : Number(pv);
                payout.dataset.payout = (n == null || Number.isNaN(n)) ? '' : String(n);
                payout.textContent = (n == null || Number.isNaN(n)) ? '—' : `${Math.round(n)}%`;
                payout.className = 'instrument-payout text-xs font-medium ' + payoutClassByValue(n);
            }
            let timerEl = btn.querySelector('.instrument-trade-timer');
            if (showOpenTrade && inst.expiry_ts) {
                if (!timerEl) {
                    timerEl = document.createElement('span');
                    timerEl.className = 'instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200';
                    const nameWrap = btn.querySelector('.flex.min-w-0.flex-1');
                    if (nameWrap) nameWrap.appendChild(timerEl);
                }
                timerEl.dataset.expiryTs = String(inst.expiry_ts);
                timerEl.textContent = formatMmSs(Number(inst.expiry_ts) - nowSec);
            } else if (timerEl) {
                timerEl.remove();
            }
        }
        return true;
    }

    function renderInstrumentSidebarRows(instruments) {
        if (!instrumentListEl) return;
        if (instruments.length && patchInstrumentSidebarRows(instruments)) {
            return;
        }
        instrumentListEl.replaceChildren();
        if (!instruments.length) {
            const li = document.createElement('li');
            li.className = 'px-4 py-6 text-sm text-zinc-500';
            li.textContent = 'Нет активных инструментов.';
            instrumentListEl.appendChild(li);
            return;
        }
        instruments.forEach((inst) => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            // Подсветку открытой сделки показываем только если включён режим «Торговля».
            const showOpenTrade = !!(showTrades && inst.open_trade);
            btn.className = 'instrument-btn w-full text-left px-4 py-3 hover:bg-zinc-800 focus:bg-zinc-800 focus:outline-none transition-colors' + (showOpenTrade ? ' bg-stone-800/45' : '');
            btn.dataset.instrument = inst.instrument;
            btn.dataset.displayName = inst.display_name;
            btn.dataset.search = normalizeText(`${inst.display_name || ''} ${inst.instrument || ''}`);
            btn.dataset.openTrade = inst.open_trade ? '1' : '0';
            const row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-2';
            const nameWrap = document.createElement('div');
            nameWrap.className = 'flex min-w-0 flex-1 items-center gap-1.5';
            const name = document.createElement('span');
            name.className = 'font-medium text-zinc-100 truncate';
            name.textContent = inst.display_name || inst.instrument;
            nameWrap.appendChild(name);
            if (showOpenTrade && inst.expiry_ts) {
                const timer = document.createElement('span');
                timer.className = 'instrument-trade-timer shrink-0 text-xs font-mono tabular-nums text-stone-200';
                timer.dataset.expiryTs = String(inst.expiry_ts);
                const nowSec = serverUnixNow();
                timer.textContent = formatMmSs(Number(inst.expiry_ts) - nowSec);
                nameWrap.appendChild(timer);
            }
            const payout = document.createElement('span');
            const pv = inst.current_payout;
            const n = (pv === null || pv === undefined) ? null : Number(pv);
            payout.className = 'instrument-payout text-xs font-medium ' + payoutClassByValue(n);
            payout.dataset.instrument = inst.instrument;
            payout.dataset.payout = (n == null || Number.isNaN(n)) ? '' : String(n);
            payout.textContent = (n == null || Number.isNaN(n)) ? '—' : `${Math.round(n)}%`;
            row.appendChild(nameWrap);
            row.appendChild(payout);
            btn.appendChild(row);
            li.appendChild(btn);
            instrumentListEl.appendChild(li);
        });
    }

    function refreshInstrumentSidebar() {
        if (!window.PlatformChartFetch) {
            return fetch(INSTRUMENTS_SIDEBAR_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => r.json())
                .then((d) => {
                    if (window.PlatformServerClock) PlatformServerClock.sync(d);
                    renderInstrumentSidebarRows(d.instruments || []);
                    updateChartTitle();
                })
                .catch(() => {});
        }
        // coalesce: несколько триггеров (toggle «Торговля», таймер списка) не дублируют GET.
        return PlatformChartFetch.requestJson('instruments-sidebar', {
            key: 'sidebar',
            url: INSTRUMENTS_SIDEBAR_URL,
            apply: (d) => {
                if (window.PlatformServerClock) PlatformServerClock.sync(d);
                renderInstrumentSidebarRows(d.instruments || []);
                updateChartTitle();
                if (instrumentSearchInput) {
                    applyInstrumentFilter(instrumentSearchInput.value);
                }
                syncSearchClearButton();
                if (currentInstrument) {
                    document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === currentInstrument));
                }
                // Обновляем блок «Выплата» в правой панели после refresh payout.
                if (typeof updateManualTradePayout === 'function') updateManualTradePayout();
            },
        }) || Promise.resolve();
    }

    function normalizeText(s) {
        return (s || '').toString().toLowerCase().trim();
    }

    function applyInstrumentFilter(rawQuery) {
        const query = normalizeText(rawQuery);
        let firstVisibleBtn = null;
        document.querySelectorAll('.instrument-btn').forEach((btn) => {
            const haystack = normalizeText(btn.dataset.search || `${btn.dataset.displayName || ''} ${btn.dataset.instrument || ''}`);
            const visible = query === '' || haystack.includes(query);
            const li = btn.closest('li');
            if (li) {
                li.classList.toggle('hidden', !visible);
            }
            if (visible && !firstVisibleBtn) {
                firstVisibleBtn = btn;
            }
        });
        return firstVisibleBtn;
    }

    function findInstrumentBtnByExactName(rawValue) {
        const value = normalizeText(rawValue);
        if (!value) return null;
        return Array.from(document.querySelectorAll('.instrument-btn')).find((btn) => {
            const displayName = normalizeText(btn.dataset.displayName);
            const instrument = normalizeText(btn.dataset.instrument);
            return displayName === value || instrument === value;
        }) || null;
    }

    function syncSearchClearButton() {
        if (!instrumentSearchInput || !instrumentSearchClearBtn) return;
        const hasValue = normalizeText(instrumentSearchInput.value).length > 0;
        instrumentSearchClearBtn.classList.toggle('hidden', !hasValue);
    }

    /**
     * Нижняя граница «потолка» доступной истории графика.
     * Используется в loadMoreCandles как стоп для ленивой подгрузки влево
     * (`oldestLoadedTime <= candlesSince()` => дальше не идём).
     * Это НЕ окно первичной загрузки: первичный показ = INITIAL_BARS свечей TF.
     */
    function candlesSince() {
        return Math.floor(Date.now() / 1000) - MAX_WINDOW_SEC;
    }

    /**
     * Warmup слева от oldestLoaded: период тяжёлых MA/каналов, чтобы первая
     * видимая точка индикатора была валидной, а не «разгоном».
     */
    const INDICATOR_WARMUP_BARS = 250;

    /**
     * since/limit под уже загруженные свечи (+ warmup), с потолком MAX_WINDOW_SEC.
     * Без этого /indicators/* тянули фикс. 24ч/500 баров → LWC раздувал шкалу
     * или линии обрывались при скролле влево.
     */
    function appendIndicatorHistoryParams(params) {
        const barSec = timeframeSeconds(currentTimeframeMinutes);
        const warmupSec = INDICATOR_WARMUP_BARS * barSec;
        const floor = candlesSince();
        let since;
        if (oldestLoadedTime != null) {
            since = Math.max(floor, oldestLoadedTime - warmupSec);
        } else {
            since = Math.max(
                floor,
                Math.floor(Date.now() / 1000) - initialWindowSecForTf(currentTimeframeMinutes) - warmupSec
            );
        }
        const loaded = Math.max(allChartData.length, INITIAL_BARS);
        const limit = Math.min(5000, loaded + INDICATOR_WARMUP_BARS);
        params.set('since', String(since));
        params.set('limit', String(limit));
        return { since: since, limit: limit };
    }

    /**
     * Не даём setData индикатора расширить timeScale за пределы загруженных свечей.
     */
    function clipIndicatorPointsToCandles(points) {
        if (!Array.isArray(points) || !points.length || !allChartData.length) {
            return Array.isArray(points) ? points : [];
        }
        const t0 = allChartData[0].time;
        const t1 = allChartData[allChartData.length - 1].time;
        return points.filter((p) => p && typeof p.time === 'number' && p.time >= t0 && p.time <= t1);
    }

    function mapClippedLinePoints(data) {
        return clipIndicatorPointsToCandles(data).map((p) => ({ time: p.time, value: p.value }));
    }

    /** Histogram с цветом по знаку — тоже клипаем к свечам. */
    function mapClippedHistPoints(data, posColor, negColor) {
        return clipIndicatorPointsToCandles(data).map((p) => ({
            time: p.time,
            value: p.value,
            color: Number(p.value) >= 0 ? posColor : negColor,
        }));
    }

    /**
     * Маркеры сделок/PA вне загруженных свечей раздувают timeScale LWC (как индикаторы до clip).
     * Оставляем только метки внутри [oldest..newest], иначе тела свечей сжимаются до нуля.
     */
    function clipMarkersToCandles(markers) {
        if (!Array.isArray(markers) || !markers.length || !allChartData.length) {
            return Array.isArray(markers) ? markers : [];
        }
        const t0 = allChartData[0].time;
        const t1 = allChartData[allChartData.length - 1].time;
        return markers.filter((m) => m && typeof m.time === 'number' && m.time >= t0 && m.time <= t1);
    }

    /**
     * После addPane/removePane classic-индикаторов и applyMarkers LWC v5 иногда теряет
     * отрисовку тел свечей (шкала/сетка остаются). Перезаписываем OHLC из allChartData.
     */
    function syncCandlestickSeriesFromCache() {
        resyncCandlestickSeriesFromAllChartData();
    }

    /** Единый mapper OHLC для setData: doji + optional PA-цвета + tween close последнего бара. */
    function mapBarForCandlestickSeries(c) {
        const src = candleSmooth ? candleSmooth.visualFor(c) : c;
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        const paOn = layerPa && layerPa.checked && bc && Object.keys(bc).length > 0;
        if (!paOn) {
            return paintPocketOptionDoji({
                time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
            });
        }
        const o = bc[String(c.time)];
        if (!o) {
            return paintPocketOptionDoji({
                time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
            });
        }
        return {
            time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
            color: o.color, borderColor: o.borderColor || o.color, wickColor: o.wickColor || o.color,
        };
    }

    /** Debounce: сотни DOM-узлов paper-overlay блокируют main thread и «убивают» canvas свечей. */
    let paperTradeOverlayRaf = null;
    function schedulePaperTradeDurationOverlay() {
        if (paperTradeOverlayRaf != null) return;
        paperTradeOverlayRaf = requestAnimationFrame(() => {
            paperTradeOverlayRaf = null;
            renderPaperTradeDurationOverlay();
        });
    }

    /** ~кол-во свечей по горизонтали; «воздух» справа — через timeScale.rightOffset, не logical range > lastIdx. */
    const CHART_DEFAULT_VISIBLE_BARS = 110;
    /** «Воздух» справа через rightOffset; >5 баров ломает отрисовку хвоста в LWC v5. */
    const CHART_RIGHT_GAP_BARS = 3;
    /** Первичный показ: сколько баров TF тянем (viewport + буфер слева). */
    const INITIAL_BARS = CHART_DEFAULT_VISIBLE_BARS + INITIAL_VISIBLE_BUFFER_BARS;

    /**
     * Окно since для initial: INITIAL_BARS × длительность бара.
     * Раньше для минутных был фиксированный 2ч → M5≈24 / M15≈8 свечей.
     */
    function initialWindowSecForTf(tf) {
        return timeframeSeconds(tf) * INITIAL_BARS;
    }

    function initialLimitForTf(tf) {
        return INITIAL_BARS + 10;
    }

    /** setVisibleLogicalRange от кода — не считаем ручным pan/zoom пользователя. */
    function withSuppressedViewportFollow(fn) {
        viewportChangeSuppressDepth++;
        try {
            fn();
        } finally {
            requestAnimationFrame(() => {
                viewportChangeSuppressDepth = Math.max(0, viewportChangeSuppressDepth - 1);
            });
        }
    }

    function withSuppressedDataSeriesViewport(fn) {
        dataSeriesUpdateDepth++;
        try {
            fn();
        } finally {
            requestAnimationFrame(() => {
                dataSeriesUpdateDepth = Math.max(0, dataSeriesUpdateDepth - 1);
            });
        }
    }

    function setChartVisibleLogicalRange(range) {
        if (!chart || !range) return;
        withSuppressedViewportFollow(() => {
            chart.timeScale().setVisibleLogicalRange(range);
        });
    }

    function defaultChartViewportRange() {
        const n = allChartData.length;
        const lastIdx = n - 1;
        const visible = Math.min(CHART_DEFAULT_VISIBLE_BARS, n);
        const from = Math.max(0, lastIdx - visible + 1);
        // Пустое место справа — timeScale.rightOffset, не logical index за пределами ряда.
        const to = lastIdx;
        return { from, to };
    }

    /**
     * Шкала цены свечей (правая ось) — через неё LWC включает/выключает autoScale.
     * Пока autoScale=true, ЛКМ-drag по области свечей двигает только время (влево/вправо);
     * вертикальный pan как в TradingView работает только при autoScale=false.
     *
     * Важно: autoScale=false при zoom-out фиксирует старый ценовой диапазон —
     * свечи с другим диапазоном «уезжают» с экрана. Поэтому режим включается
     * только переключателем «Своб. шкала» (по умолчанию выкл.).
     */
    const FREE_PRICE_SCALE_KEY = 'platform.chart.freePriceScale';
    const freePriceScaleToggle = document.getElementById('free-price-scale-toggle');
    const freePriceScaleLabel = document.getElementById('free-price-scale-label');

    function loadFreePriceScalePref() {
        try {
            return localStorage.getItem(FREE_PRICE_SCALE_KEY) === '1';
        } catch (_) {
            return false;
        }
    }

    function saveFreePriceScalePref(on) {
        try {
            localStorage.setItem(FREE_PRICE_SCALE_KEY, on ? '1' : '0');
        } catch (_) {}
    }

    /** false по умолчанию — zoom-out всегда подгоняет цену под видимые свечи. */
    let freePriceScale = loadFreePriceScalePref();

    function syncFreePriceScaleToggleUi() {
        if (freePriceScaleToggle) freePriceScaleToggle.checked = freePriceScale;
        if (freePriceScaleLabel) {
            freePriceScaleLabel.classList.toggle('text-zinc-400', freePriceScale);
            freePriceScaleLabel.classList.toggle('text-zinc-500', !freePriceScale);
        }
    }

    function mainCandlePriceScale() {
        if (candlestickSeries && typeof candlestickSeries.priceScale === 'function') {
            try { return candlestickSeries.priceScale(); } catch (_) {}
        }
        if (chart && typeof chart.priceScale === 'function') {
            try { return chart.priceScale('right'); } catch (_) {}
        }
        return null;
    }

    /** Подогнать цену под видимые свечи (autoScale on). */
    function fitPriceScaleToVisibleBars() {
        const ps = mainCandlePriceScale();
        if (!ps) return;
        try {
            if (typeof ps.setAutoScale === 'function') ps.setAutoScale(true);
            else if (typeof ps.applyOptions === 'function') ps.applyOptions({ autoScale: true });
        } catch (_) {}
    }

    /**
     * Разрешить двигать график вверх/вниз мышкой (как TradingView после «отлипания» шкалы).
     * Двойной клик по правой оси снова включает autoScale (axisDoubleClickReset).
     */
    function unlockVerticalMousePan() {
        const ps = mainCandlePriceScale();
        if (!ps) return;
        try {
            if (typeof ps.setAutoScale === 'function') ps.setAutoScale(false);
            else if (typeof ps.applyOptions === 'function') ps.applyOptions({ autoScale: false });
        } catch (_) {}
    }

    /**
     * Применить режим шкалы: свободная (верт. pan) или авто (свечи всегда в кадре при zoom).
     */
    function applyPriceScalePanPref() {
        if (freePriceScale) unlockVerticalMousePan();
        else fitPriceScaleToVisibleBars();
    }

    function applyFreePriceScaleMode(on, { persist = true } = {}) {
        freePriceScale = !!on;
        if (persist) saveFreePriceScalePref(freePriceScale);
        syncFreePriceScaleToggleUi();
        applyPriceScalePanPref();
    }

    /** Синхронная установка viewport (сразу после setData — без кадра «весь ряд»). */
    function applyDefaultChartViewportSync() {
        if (!chart || allChartData.length === 0) return;
        tailFollowActive = true;
        setChartVisibleLogicalRange(defaultChartViewportRange());
        // Сначала fit по цене, чтобы свечи были в кадре…
        fitPriceScaleToVisibleBars();
    }

    /** LWC после setData иногда расширяет span — поджимаем, как на bots/show. */
    function verifyDefaultChartViewport() {
        if (!chart || allChartData.length === 0) return;
        const { from, to } = defaultChartViewportRange();
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range || !Number.isFinite(range.from) || !Number.isFinite(range.to)) {
            tailFollowActive = true;
            setChartVisibleLogicalRange({ from, to });
            return;
        }
        const span = range.to - range.from;
        const expectedSpan = to - from;
        if (span > expectedSpan + 25) {
            tailFollowActive = true;
            setChartVisibleLogicalRange({ from, to });
        }
    }

    function applyDefaultChartViewport() {
        if (!chart || allChartData.length === 0) return;
        requestAnimationFrame(() => {
            if (!chart) return;
            applyDefaultChartViewportSync();
            requestAnimationFrame(() => {
                verifyDefaultChartViewport();
                // Своб. шкала вкл → unlock; выкл → autoScale (свечи не пропадают при zoom-out).
                applyPriceScalePanPref();
            });
        });
    }

    /**
     * Первичный показ после selectInstrument / reloadChartData:
     * setData + viewport в одном подавлении dataSeriesUpdateDepth, затем verify в rAF.
     */
    function applyInitialChartPresentation(onSettled) {
        if (!candlestickSeries || !allChartData.length) {
            chartViewportSettling = false;
            if (typeof onSettled === 'function') onSettled();
            return;
        }
        chartViewportSettling = true;
        if (candleSmooth) candleSmooth.reset();
        lastSyncedTailKey = '';
        withSuppressedDataSeriesViewport(() => {
            try {
                candlestickSeries.setData(allChartData.map(mapBarForCandlestickSeries));
                candleSeriesBarCount = allChartData.length;
                if (candleSmooth && allChartData.length) {
                    candleSmooth.snap(allChartData[allChartData.length - 1]);
                }
            } catch (e) {
                console.error('[chart] applyInitialChartPresentation setData', e);
            }
            syncLastBarKeyFromData();
            applyDefaultChartViewportSync();
        });
        applyPaBarColors({ fullResync: true });
        requestAnimationFrame(() => {
            if (!chart) {
                chartViewportSettling = false;
                if (typeof onSettled === 'function') onSettled();
                return;
            }
            verifyDefaultChartViewport();
            // Своб. шкала вкл → unlock; выкл → autoScale (свечи не пропадают при zoom-out).
            applyPriceScalePanPref();
            chartViewportSettling = false;
            if (typeof onSettled === 'function') onSettled();
        });
    }

    /**
     * Режим «следую за хвостом»: пользователь не pan/zoom'ил вручную и смотрит правую часть.
     */
    function isViewNearRightEdge() {
        if (!tailFollowActive) return false;
        if (!chart || allChartData.length === 0) return true;
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range) return true;
        const lastIdx = allChartData.length - 1;
        return range.to >= lastIdx - 1e-3;
    }

    /** Сдвиг viewport при новой свече: сохраняем масштаб, правый край = lastIdx (gap через rightOffset). */
    function maintainTailViewportIfFollowing() {
        if (chartViewportSettling || !tailFollowActive || !chart || allChartData.length === 0) return;
        const lastIdx = allChartData.length - 1;
        const wantTo = lastIdx;
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range) return;
        const span = range.to - range.from;
        if (!Number.isFinite(span) || span <= 0) return;
        const wantFrom = wantTo - span;
        const tol = 0.25;
        if (Math.abs(range.to - wantTo) <= tol && Math.abs(range.from - wantFrom) <= tol) {
            return;
        }
        setChartVisibleLogicalRange({ from: wantFrom, to: wantTo });
    }

    /** Ключ OHLC одного бара — poll пушит setData только при изменении. */
    function tailBarSyncKey(bar) {
        if (!bar) return '';
        return `${bar.time}|${bar.open}|${bar.high}|${bar.low}|${bar.close}`;
    }

    function syncLastBarKeyFromData() {
        lastSyncedTailKey = tailPollSyncKey(allChartData);
    }

    /** Ключ OHLC хвоста poll (forming + только что закрытый). */
    function tailPollSyncKey(data) {
        if (!Array.isArray(data) || !data.length) return '';
        const slice = data.slice(Math.max(0, data.length - POLL_MERGE_TAIL_BARS));
        return slice.map(tailBarSyncKey).join(';');
    }

    /**
     * Обновление хвоста серии после poll.
     * С включённым candleSmooth: series.update() только на last (и только что закрытый)
     * через toward()/snap — плавный close. Full setData — при смене длины ряда / без smooth.
     * (Heal раньше всегда делал setData: LWC v5 ломал соседние бары при update на всём хвосте;
     *  точечный update одного last-бара + tween безопаснее и даёт «живую» свечу.)
     */
    function pushCandlestickSeriesIfTailChanged(opts = {}) {
        if (!candlestickSeries || !allChartData.length) return false;
        const key = tailPollSyncKey(allChartData);
        if (!key || key === lastSyncedTailKey) return false;

        const prevParts = lastSyncedTailKey ? lastSyncedTailKey.split(';') : [];
        const prevLastTime = prevParts.length ? prevParts[prevParts.length - 1].split('|')[0] : '';
        const lastBar = allChartData[allChartData.length - 1];
        const curLastTime = String(lastBar.time);
        const isNewBarTime = prevLastTime !== '' && prevLastTime !== curLastTime;

        // Длина ряда изменилась — нужен полный setData (индексы LWC).
        if (candleSeriesBarCount !== allChartData.length) {
            lastSyncedTailKey = key;
            resyncCandlestickSeriesFromAllChartData(opts);
            return true;
        }

        // Плавный путь: не throttle 1с и не full setData — иначе tween никогда не виден.
        if (candleSmooth) {
            withSuppressedDataSeriesViewport(() => {
                // Только что закрытый бар: один update без tween (OHLC уже финальный).
                if (isNewBarTime && allChartData.length >= 2) {
                    try {
                        candlestickSeries.update(mapBarForCandlestickSeries(allChartData[allChartData.length - 2]));
                    } catch (e) {
                        console.error('[chart] closed-bar update', e);
                    }
                }
                candleSmooth.toward(lastBar);
            });
            lastSyncedTailKey = key;
            lastTailSeriesUpdateMs = performance.now();
            return true;
        }

        const now = performance.now();
        if (!isNewBarTime && (now - lastTailSeriesUpdateMs) < TAIL_SERIES_UPDATE_MIN_MS) {
            return false;
        }

        lastSyncedTailKey = key;
        lastTailSeriesUpdateMs = now;
        resyncCandlestickSeriesFromAllChartData(opts);
        return true;
    }

    function startCandleHealLoop() {
        // PR3: при live WS + soft-resync полный setData раз в 30с только мешает (рывок + long task).
        // Heal только при явном CHART_HEAL_ENABLED=true.
        if (boot.healEnabled !== true) {
            stopCandleHealLoop();
            return;
        }
        if (chartLiveClient && chartLiveClient.isConnected()) {
            stopCandleHealLoop();
            return;
        }
        if (candleHealInterval) clearInterval(candleHealInterval);
        candleHealInterval = setInterval(() => {
            if (chartViewportSettling || !chart || !candlestickSeries || !allChartData.length) return;
            if (!tailFollowActive || !isViewNearRightEdge()) return;
            // Периодический setData выравнивает canvas с allChartData (poll merge только 2 бара).
            lastSyncedTailKey = '';
            resyncCandlestickSeriesFromAllChartData({ preserveRange: true });
        }, CANDLE_HEAL_RESYNC_MS);
    }

    function stopCandleHealLoop() {
        if (!candleHealInterval) return;
        clearInterval(candleHealInterval);
        candleHealInterval = null;
    }

    /** Полная перезапись OHLC-серии из allChartData (после addPane, рассинхрона poll/LWC). */
    function resyncCandlestickSeriesFromAllChartData(opts = {}) {
        if (!candlestickSeries || !allChartData.length) return;
        const preserveRange = opts.preserveRange !== false && chart;
        // preserveSmooth: setData неизбежен после addPane, но last close снова гоняем через toward
        // (не snap), чтобы не сбрасывать Binodex/PO chase на каждом rebuild индикаторов.
        const preserveSmooth = opts.preserveSmooth === true && candleSmooth;
        let savedRange = null;
        if (preserveRange) {
            try { savedRange = chart.timeScale().getVisibleLogicalRange(); } catch (_) {}
        }
        withSuppressedDataSeriesViewport(() => {
            if (candleSmooth && !preserveSmooth) candleSmooth.reset();
            try {
                candlestickSeries.setData(allChartData.map((c, idx) => {
                    // Хвост — визуальный close chase, чтобы setData не мигал серверным OHLC.
                    const src = (preserveSmooth && idx === allChartData.length - 1)
                        ? candleSmooth.visualFor(c)
                        : c;
                    return mapBarForCandlestickSeries(src);
                }));
                candleSeriesBarCount = allChartData.length;
                if (candleSmooth && allChartData.length) {
                    const last = allChartData[allChartData.length - 1];
                    if (preserveSmooth) candleSmooth.toward(last);
                    else candleSmooth.snap(last);
                }
            } catch (e) {
                console.error('[chart] resyncCandlestickSeriesFromAllChartData', e);
            }
        });
        syncLastBarKeyFromData();
        if (savedRange && chart) {
            withSuppressedViewportFollow(() => {
                try {
                    const lastIdx = allChartData.length - 1;
                    if (Number.isFinite(savedRange.to) && savedRange.to <= lastIdx + 0.5) {
                        setChartVisibleLogicalRange(savedRange);
                    }
                } catch (_) {}
            });
        }
    }

    /** Patch setData осцилляторов без removePane (15-с таймер classic indicators). */
    function patchVolatilityCandlesSeries() {
        const s = classicLineSeries['vc'];
        const data = lastVolatilityCandlesPayload && lastVolatilityCandlesPayload.series
            ? lastVolatilityCandlesPayload.series.vc
            : null;
        if (!s || !Array.isArray(data) || !data.length) return false;
        s.setData(mapClippedLinePoints(data));
        return true;
    }

    function patchRsiSeries() {
        const s = classicLineSeries['rsi'];
        const data = lastRsiPayload && lastRsiPayload.series ? lastRsiPayload.series.rsi : null;
        if (!s || !Array.isArray(data) || !data.length) return false;
        s.setData(mapClippedLinePoints(data));
        return true;
    }

    function patchDemarkerSeries() {
        const s = classicLineSeries['demarker'];
        const data = lastDemarkerPayload && lastDemarkerPayload.series ? lastDemarkerPayload.series.dem : null;
        if (!s || !Array.isArray(data) || !data.length) return false;
        s.setData(mapClippedLinePoints(data));
        return true;
    }

    /**
     * In-bar: обновить клиентский classic-subset на месте (без addPane/resync свечей).
     * Не трогает chase PlatformCandleSmooth.
     */
    function patchClientClassicSeriesFromPayloads() {
        if (!chart || !Object.keys(classicLineSeries).length) return false;
        let touched = false;

        const setLine = (key, points) => {
            const s = classicLineSeries[key];
            if (!s || !Array.isArray(points) || !points.length) return;
            try {
                s.setData(mapClippedLinePoints(points));
                touched = true;
            } catch (e) {
                console.error('[classic light-patch]', key, e);
            }
        };

        if (lastSmaPayload) setLine('sma', (lastSmaPayload.series || {}).ma);
        if (lastEmaPayload) setLine('ema', (lastEmaPayload.series || {}).ema);
        if (lastVwmaPayload) setLine('vwma', (lastVwmaPayload.series || {}).vwma);
        if (lastBollingerPayload) {
            const ser = lastBollingerPayload.series || {};
            setLine('bb_up', ser.up);
            setLine('bb_mid', ser.mid);
            setLine('bb_down', ser.down);
        }
        if (lastRsiPayload) setLine('rsi', (lastRsiPayload.series || {}).rsi);
        if (lastAtrPayload) setLine('atr', (lastAtrPayload.series || {}).atr);
        if (lastStochasticPayload) {
            const ser = lastStochasticPayload.series || {};
            setLine('stoch_k', ser.k);
            setLine('stoch_d', ser.d);
        }
        if (lastMacdPayload) {
            const ser = lastMacdPayload.series || {};
            const st = lastMacdPayload.style || {};
            setLine('macd_line', ser.macd);
            setLine('macd_signal', ser.signal);
            const hs = classicLineSeries['macd_hist'];
            if (hs && Array.isArray(ser.histogram) && ser.histogram.length) {
                const pos = st.color_hist_up || '#22c55e';
                const neg = st.color_hist_down || '#ef4444';
                try {
                    hs.setData(mapClippedHistPoints(ser.histogram, pos, neg));
                    touched = true;
                } catch (e) {
                    console.error('[classic light-patch] macd_hist', e);
                }
            }
        }

        if (patchRsiSeries()) touched = true;
        if (patchDemarkerSeries()) touched = true;
        if (patchVolatilityCandlesSeries()) touched = true;
        return touched;
    }

    /** Карта patch-обработчиков: только осцилляторы с отдельной pane (без overlay на свечах). */
    const CLASSIC_SERIES_PATCH_HANDLERS = {
        rsi: patchRsiSeries,
        demarker: patchDemarkerSeries,
        volatility_candles: patchVolatilityCandlesSeries,
    };

    /**
     * Обновить classic-series на месте, если pane уже созданы.
     * @returns {boolean} true — все включённые слои обновлены без rebuild.
     */
    function patchEnabledClassicSeriesData(enabledLayers) {
        if (!chart || !Array.isArray(enabledLayers) || !enabledLayers.length) return false;
        if (!Object.keys(classicLineSeries).length) return false;
        for (let i = 0; i < enabledLayers.length; i++) {
            const patch = CLASSIC_SERIES_PATCH_HANDLERS[enabledLayers[i]];
            if (!patch || !patch()) return false;
        }
        return true;
    }

    /**
     * DOM-зоны рисуются поверх canvas LW Charts: если time_end = текущий бар,
     * полупрозрачный прямоугольник перекрывает формирующуюся свечу.
     * Обрезаем правый край до левой границы колонки последнего бара.
     */
    function zoneEndXClipped(ts, tEnd, candles) {
        if (!candles?.length) return null;
        const lastBarTime = candles[candles.length - 1].time;
        const endTime = tEnd ?? lastBarTime;
        let x2 = ts.timeToCoordinate(endTime);
        if (x2 == null) return null;
        if (endTime < lastBarTime || candles.length < 2) return x2;
        const xLast = ts.timeToCoordinate(lastBarTime);
        const xPrev = ts.timeToCoordinate(candles[candles.length - 2].time);
        if (xLast == null || xPrev == null) return x2;
        const halfBar = Math.max(2, Math.abs(xLast - xPrev) / 2);
        return Math.min(x2, xLast - halfBar);
    }


    /** Координаты LWC → canvas API (OB/PA/LS/SMC). */
    function buildChartCanvasCoordApi(extra) {
        if (!chart || !candlestickSeries) return null;
        const ts = chart.timeScale();
        return Object.assign({
            viewportW: Math.max(1, chartWrapper?.clientWidth || 0),
            viewportH: Math.max(1, chartWrapper?.clientHeight || 0),
            timeToCoordinate: (t) => {
                const x = ts.timeToCoordinate(Number(t));
                return Number.isFinite(x) ? x : null;
            },
            priceToCoordinate: (p) => {
                const y = candlestickSeries.priceToCoordinate(Number(p));
                return Number.isFinite(y) ? y : null;
            },
            zoneEndX: (tEnd) => {
                const x = zoneEndXClipped(ts, Number(tEnd), allChartData);
                return Number.isFinite(x) ? x : null;
            },
            lastBarTime: allChartData.length ? allChartData[allChartData.length - 1].time : 0,
        }, extra || {});
    }

    /** Per-frame canvas paint тяжёлых оверлеев (без DOM thrash). */
    function paintAllChartCanvasOverlaysFast() {
        try {
            redrawStructureOverlay({ skipFollowups: true });
            redrawLiquiditySwingsOverlay();
            redrawSweepRaidLiquidityOverlay();
            redrawEngulfingLiquidityOverlay();
            redrawPriceActionOverlay({ skipLiquidity: true, skipNameLabels: true });
            if (layerSmc && layerSmc.checked && lastSmcPayload) redrawSmcFlowOverlay();
        } catch (e) {
            console.error('[chart] canvas overlays fast paint', e);
        }
    }


    function redrawStructureOverlay(opts) {
        const skipFollowups = !!(opts && opts.skipFollowups);
        if (!chart || !candlestickSeries || !structureOverlay) return;
        const painter = window.PlatformChartCanvasOverlay;
        const showOb = !!(layerOb && layerOb.checked);
        const showFvg = !!(layerFvg && layerFvg.checked);
        const enabled = !!(allChartData.length && lastZones.length && (showOb || showFvg));
        if (!painter || typeof painter.paintStructure !== 'function') {
            structureOverlay.replaceChildren();
        } else {
            try {
                const api = buildChartCanvasCoordApi({ enabled, showOb, showFvg });
                if (api) painter.paintStructure(structureOverlay, lastZones, api);
                else structureOverlay.replaceChildren();
            } catch (e) {
                console.error('[Structure] dashboard canvas redraw', e);
            }
        }
        if (!skipFollowups) {
            redrawSweepRaidLiquidityOverlay();
            redrawEngulfingLiquidityOverlay();
            requestAnimationFrame(redrawPriceActionOverlay);
        }
    }


    /**
     * Рисует Sweep / Raid независимо от Liquidity Swings:
     * lines отмечают снятые pivot-уровни, labels — фракталы, события и pullback-входы.
     * Цвета и видимость элементов уже нормализованы indicator-service на backend.
     */

    /**
     * Sweep / Raid — Canvas2D (lines + labels), без DOM на zoom.
     */
    function redrawSweepRaidLiquidityOverlay() {
        if (!sweepRaidLiquidityOverlay || !chart || !candlestickSeries) return;
        const painter = window.PlatformChartCanvasOverlay;
        const enabled = !!(layerSweepRaid && layerSweepRaid.checked && lastSweepRaidPayload && allChartData.length);
        if (!painter || typeof painter.paintLiquidity !== 'function') {
            sweepRaidLiquidityOverlay.replaceChildren();
            return;
        }
        try {
            const api = buildChartCanvasCoordApi({ enabled });
            if (api) painter.paintLiquidity(sweepRaidLiquidityOverlay, lastSweepRaidPayload, api);
            else sweepRaidLiquidityOverlay.replaceChildren();
        } catch (e) {
            console.error('[SweepRaid] dashboard canvas redraw', e);
        }
    }


    /** Метки ENGULF 1/2/3 — тот же формат labels, что у Sweep/Raid (без линий). */

    function redrawEngulfingLiquidityOverlay() {
        if (!engulfingLiquidityOverlay || !chart || !candlestickSeries) return;
        const painter = window.PlatformChartCanvasOverlay;
        const enabled = !!(layerEngulfing && layerEngulfing.checked && lastEngulfingPayload && allChartData.length);
        if (!painter || typeof painter.paintLiquidity !== 'function') {
            engulfingLiquidityOverlay.replaceChildren();
            return;
        }
        try {
            const api = buildChartCanvasCoordApi({ enabled });
            if (api) painter.paintLiquidity(engulfingLiquidityOverlay, lastEngulfingPayload, api);
            else engulfingLiquidityOverlay.replaceChildren();
        } catch (e) {
            console.error('[Engulfing] dashboard canvas redraw', e);
        }
    }


    function paCssColor(c) {
        if (c == null || c === '') return 'rgba(148,163,184,0.5)';
        const s = String(c);
        if (s.startsWith('#') && s.length === 9) return s;
        return s;
    }

    /** Отрезок оверлея между двумя координатами (ломаная UT-трейла SMC Flow). */
    function appendOverlaySegment(parent, x1, y1, x2, y2, color, lineWidth) {
        if (!parent || ![x1, y1, x2, y2].every(Number.isFinite)) return;
        const dx = x2 - x1;
        const dy = y2 - y1;
        const length = Math.hypot(dx, dy);
        if (length < 0.5) return;
        const div = document.createElement('div');
        div.style.position = 'absolute';
        div.style.left = x1 + 'px';
        div.style.top = y1 + 'px';
        div.style.width = length + 'px';
        div.style.height = Math.max(1, lineWidth) + 'px';
        div.style.background = color;
        div.style.pointerEvents = 'none';
        div.style.transformOrigin = '0 50%';
        div.style.transform = 'rotate(' + Math.atan2(dy, dx) + 'rad)';
        parent.appendChild(div);
    }

    /** PA DGT — клиентский пересчёт (без dashboard.indicators.price-action-dgt). */
    function fetchPriceActionIndicator() {
        if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('pa');
        recomputeClientIndicators();
    }

    /**
     * Тянем сделки пользователя по текущему инструменту и рисуем маркеры
     * тем же helper, что и на странице бота (ВВЕРХ/ВНИЗ/WIN/LOSE/OPEN).
     * Если переключатель «Торговля» выключен — не ходим на API и чистим маркеры.
     */
    function fetchChartTrades() {
        if (!showTrades) {
            lastChartSignals = [];
            lastOpenTrades = [];
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('chart-trades');
            detachCandlestickMarkersPlugin();
            return;
        }
        if (!currentInstrument) {
            lastChartSignals = [];
            lastOpenTrades = [];
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('chart-trades');
            renderChartMarkers();
            return;
        }
        if (!window.PlatformChartFetch) return;
        const params = new URLSearchParams({ instrument: currentInstrument });
        PlatformChartFetch.requestJson('chart-trades', {
            key: currentInstrument,
            url: CHART_TRADES_URL + '?' + params.toString(),
            apply: (d) => {
                if (!showTrades) return;
                lastChartSignals = Array.isArray(d && d.signals) ? d.signals : [];
                lastOpenTrades = Array.isArray(d && d.open_trades) ? d.open_trades : [];
                renderChartMarkers();
            },
            onError: () => {
                // При ошибке сети не стираем уже показанные маркеры.
            },
        });
    }

    /**
     * Собирает и применяет маркеры: история сделок + открытые + PA (если слой включён).
     * Через PlatformChartMarkers — тот же код, что на bots/show.
     * При выключенной «Торговле» оставляем только маркеры индикаторов (например PA).
     */
    /** Маркеры создаём лениво: createSeriesMarkers на пустой серии + poll update() ломали canvas. */
    function detachCandlestickMarkersPlugin() {
        if (!candlestickMarkers) return;
        const PCM = window.PlatformChartMarkers;
        try {
            if (PCM && typeof PCM.detachPlugin === 'function') {
                PCM.detachPlugin(candlestickMarkers);
            } else if (typeof candlestickMarkers.detach === 'function') {
                candlestickMarkers.detach();
            }
        } catch (e) {
            console.error('[dashboard chart] detachCandlestickMarkersPlugin', e);
        }
        candlestickMarkers = null;
    }

    function ensureCandlestickMarkersPlugin() {
        if (candlestickMarkers || !candlestickSeries) return candlestickMarkers;
        const PCM = window.PlatformChartMarkers;
        try {
            if (PCM && typeof PCM.createPluginForSeries === 'function') {
                candlestickMarkers = PCM.createPluginForSeries(candlestickSeries, []);
            } else if (typeof LightweightCharts.createSeriesMarkers === 'function') {
                candlestickMarkers = LightweightCharts.createSeriesMarkers(candlestickSeries, []);
            }
            // После attach plugin poll хвоста идёт через setData, не update().
            if (candlestickMarkers) lastSyncedTailKey = '';
        } catch (e) {
            console.error('[dashboard chart] ensureCandlestickMarkersPlugin', e);
        }
        return candlestickMarkers;
    }

    function renderChartMarkers() {
        if (!candlestickSeries || !currentInstrument) return;
        const PCM = window.PlatformChartMarkers;
        if (!PCM) return;

        // Сделки на графике только если пользователь не попросил «чистый» режим.
        const history = (showTrades && typeof PCM.buildHistoryMarkers === 'function')
            ? PCM.buildHistoryMarkers(lastChartSignals, {
                instrument: currentInstrument,
                barSec: timeframeSeconds(currentTimeframeMinutes),
            })
            : [];
        const open = (showTrades && typeof PCM.buildOpenTradeMarkers === 'function')
            ? PCM.buildOpenTradeMarkers(lastOpenTrades, {
                instrument: currentInstrument,
                nowSec: serverUnixNow(),
                barSec: timeframeSeconds(currentTimeframeMinutes),
            })
            : [];
        const paMk = (layerPa && layerPa.checked && lastPaPayload && Array.isArray(lastPaPayload.markers))
            ? lastPaPayload.markers
            : [];
        const stratMk = (layerStratAssistant && layerStratAssistant.checked && lastStratPayload && Array.isArray(lastStratPayload.markers))
            ? lastStratPayload.markers
            : [];
        const merged = typeof PCM.mergeMarkers === 'function'
            ? PCM.mergeMarkers(history, open, paMk, stratMk)
            : [...history, ...open, ...paMk, ...stratMk].sort((a, b) => a.time - b.time);
        const all = clipMarkersToCandles(merged);

        // Пустой plugin на серии + poll setData каждые 500ms → через ~5–7 мин «дырявый» хвост.
        if (!all.length) {
            detachCandlestickMarkersPlugin();
            return;
        }

        ensureCandlestickMarkersPlugin();

        if (typeof PCM.applyMarkers === 'function') {
            PCM.applyMarkers(candlestickMarkers, all);
        }
        // PO/paper — только при «Торговле»; HTML-линейку не трогаем (перерисовка оси → дёргание).
        if (showTrades) {
            renderPoOpenTradeRaysOverlay();
            schedulePaperTradeDurationOverlay();
        }
    }

    /**
     * Горизонтальные полосы Paper-сделок (entry → expiry) на уровне цены входа.
     * Отдельно от PO-лучей: те рисуют только открытые сделки в стиле Pocket Option.
     */
    function renderPaperTradeDurationOverlay() {
        const PCM = window.PlatformChartMarkers;
        if (!PCM || typeof PCM.renderTradeDurationOverlay !== 'function') return;
        const durations = (typeof PCM.buildTradeDurationsFromSignals === 'function')
            ? PCM.buildTradeDurationsFromSignals(lastChartSignals, {
                instrument: currentInstrument,
                nowSec: serverUnixNow(),
                openTrades: lastOpenTrades,
            })
            : [];
        PCM.renderTradeDurationOverlay({
            overlayEl: tradeDurationOverlay,
            chartWrapperEl: chartWrapper,
            chart,
            series: candlestickSeries,
            candleData: allChartData,
            barSec: timeframeSeconds(currentTimeframeMinutes),
            show: !!showTrades,
            durations,
        });
    }

    function buildDashboardTradeDurations() {
        const PCM = window.PlatformChartMarkers;
        if (!PCM || typeof PCM.buildTradeDurationsFromSignals !== 'function' || !currentInstrument) {
            return [];
        }
        return PCM.buildTradeDurationsFromSignals(lastChartSignals, {
            instrument: currentInstrument,
            nowSec: serverUnixNow(),
            openTrades: lastOpenTrades,
        });
    }

    function goToDashboardTradeDetails(pick) {
        const tradeId = Number(pick && pick.tradeId);
        if (!Number.isFinite(tradeId) || tradeId <= 0) return;
        const botId = Number(pick && pick.botId);
        if (Number.isFinite(botId) && botId > 0 && LIVE_TRADES_ACTIVITY_TMPL) {
            window.location.href = `${LIVE_TRADES_ACTIVITY_TMPL.replace('__BOT_ID__', encodeURIComponent(String(botId)))}?trade_id=${encodeURIComponent(String(tradeId))}`;
            return;
        }
        window.location.href = `${boot.liveTradesPageUrl}?trade_id=${encodeURIComponent(String(tradeId))}`;
    }

    function pickDashboardTradeFromChartClick(param) {
        const PCM = window.PlatformChartMarkers;
        if (!PCM || !param || !showTrades) return null;
        const durations = buildDashboardTradeDurations();
        if (typeof PCM.resolveTradePickAtChartPoint === 'function' && param.point) {
            const pick = PCM.resolveTradePickAtChartPoint({
                chart,
                series: candlestickSeries,
                candleData: allChartData,
                barSec: timeframeSeconds(currentTimeframeMinutes),
                x: param.point.x,
                y: param.point.y,
                durations,
            });
            if (pick && pick.tradeId) return pick;
        }
        return null;
    }

    /**
     * Рисует/прячет PO-лучи открытых сделок на DOM-оверлее графика.
     * Пересчитывается при pan/zoom, смене ТФ и тике таймера раз в секунду.
     */
    function renderPoOpenTradeRaysOverlay() {
        const PCM = window.PlatformChartMarkers;
        if (!PCM || typeof PCM.renderPoOpenTradeRays !== 'function') return;
        PCM.renderPoOpenTradeRays({
            overlayEl: poOpenTradesOverlay,
            chart,
            series: candlestickSeries,
            candleData: allChartData,
            trades: lastOpenTrades,
            instrument: currentInstrument,
            barSec: timeframeSeconds(currentTimeframeMinutes),
            nowSec: serverUnixNow(),
            show: !!showTrades,
        });
    }

    /** Серверные classic-слои (без клиентского subset SMA/EMA/RSI/BB/ATR/MACD/VWMA/Stoch). */
    function anyServerClassicLayerOn() {
        return !!(
            (layerCci && layerCci.checked) ||
            (layerKeltner && layerKeltner.checked) ||
            (layerEnvelops && layerEnvelops.checked) ||
            (layerParabolicSar && layerParabolicSar.checked) ||
            (layerSuperTrend && layerSuperTrend.checked) ||
            (layerAdx && layerAdx.checked) ||
            (layerWilliams && layerWilliams.checked) ||
            (layerAo && layerAo.checked) ||
            (layerAlligator && layerAlligator.checked) ||
            (layerIchimoku && layerIchimoku.checked) ||
            (layerPriceChannel && layerPriceChannel.checked) ||
            (layerNrtr && layerNrtr.checked) ||
            (layerHma && layerHma.checked) ||
            (layerAroon && layerAroon.checked) ||
            (layerMomentum && layerMomentum.checked) ||
            (layerRoc && layerRoc.checked) ||
            (layerOsma && layerOsma.checked) ||
            (layerTrix && layerTrix.checked) ||
            (layerCmo && layerCmo.checked) ||
            (layerDemarker && layerDemarker.checked) ||
            (layerRvi && layerRvi.checked) ||
            (layerUltimate && layerUltimate.checked) ||
            (layerSmi && layerSmi.checked) ||
            (layerStdDev && layerStdDev.checked) ||
            (layerSmaChannel && layerSmaChannel.checked) ||
            (layerIvashov && layerIvashov.checked) ||
            (layerLinReg && layerLinReg.checked) ||
            (layerLinRegChannel && layerLinRegChannel.checked) ||
            (layerKalman && layerKalman.checked) ||
            (layerFractal && layerFractal.checked) ||
            (layerDonchian && layerDonchian.checked) ||
            (layerStochRsi && layerStochRsi.checked) ||
            (layerAc && layerAc.checked) ||
            (layerBullsPower && layerBullsPower.checked) ||
            (layerBearsPower && layerBearsPower.checked) ||
            (layerDpo && layerDpo.checked) ||
            (layerQstick && layerQstick.checked) ||
            (layerVhFilter && layerVhFilter.checked) ||
            (layerEfficiencyRatio && layerEfficiencyRatio.checked) ||
            (layerRavi && layerRavi.checked) ||
            (layerCog && layerCog.checked) ||
            (layerPivotFloor && layerPivotFloor.checked) ||
            (layerMassIndex && layerMassIndex.checked) ||
            (layerNrtrWatr && layerNrtrWatr.checked) ||
            (layerPriceChannelAdaptive && layerPriceChannelAdaptive.checked) ||
            (layerPriceChannelOffset && layerPriceChannelOffset.checked) ||
            (layerParabolicBollinger && layerParabolicBollinger.checked) ||
            (layerParabolicPriceChannel && layerParabolicPriceChannel.checked) ||
            (layerAtrChannel && layerAtrChannel.checked) ||
            (layerOffsetSma && layerOffsetSma.checked) ||
            (layerOffsetEma && layerOffsetEma.checked) ||
            (layerOffsetSsma && layerOffsetSsma.checked) ||
            (layerVolatilityAverage && layerVolatilityAverage.checked) ||
            (layerVolatilityAverageChannel && layerVolatilityAverageChannel.checked) ||
            (layerPriceOscillator && layerPriceOscillator.checked) ||
            (layerDynamicTrendDetector && layerDynamicTrendDetector.checked) ||
            (layerAdaptiveLookBack && layerAdaptiveLookBack.checked) ||
            (layerLinRegChannelFast && layerLinRegChannelFast.checked) ||
            (layerVolatilityCandles && layerVolatilityCandles.checked) ||
            (layerZigZag && layerZigZag.checked) ||
            (layerVolatilityAverageTwice && layerVolatilityAverageTwice.checked) ||
            (layerVolatilityStagesAw && layerVolatilityStagesAw.checked)
        );
    }

    /** Включён ли хотя бы один classic-слой OsEngine. */
    function anyClassicLayerOn() {
        return !!(
            (layerBollinger && layerBollinger.checked) ||
            (layerRsi && layerRsi.checked) ||
            (layerSma && layerSma.checked) ||
            (layerEma && layerEma.checked) ||
            (layerVwma && layerVwma.checked) ||
            (layerAtr && layerAtr.checked) ||
            (layerMacd && layerMacd.checked) ||
            (layerStochastic && layerStochastic.checked) ||
            (layerCci && layerCci.checked) ||
            (layerKeltner && layerKeltner.checked) ||
            (layerEnvelops && layerEnvelops.checked) ||
            (layerParabolicSar && layerParabolicSar.checked) ||
            (layerSuperTrend && layerSuperTrend.checked) ||
            (layerAdx && layerAdx.checked) ||
            (layerWilliams && layerWilliams.checked) ||
            (layerAo && layerAo.checked) ||
            (layerAlligator && layerAlligator.checked) ||
            (layerIchimoku && layerIchimoku.checked) ||
            (layerPriceChannel && layerPriceChannel.checked) ||
            (layerNrtr && layerNrtr.checked) ||
            (layerHma && layerHma.checked) ||
            (layerAroon && layerAroon.checked) ||
            (layerMomentum && layerMomentum.checked) ||
            (layerRoc && layerRoc.checked) ||
            (layerOsma && layerOsma.checked) ||
            (layerTrix && layerTrix.checked) ||
            (layerCmo && layerCmo.checked) ||
            (layerDemarker && layerDemarker.checked) ||
            (layerRvi && layerRvi.checked) ||
            (layerUltimate && layerUltimate.checked) ||
            (layerSmi && layerSmi.checked) ||
            (layerStdDev && layerStdDev.checked) ||
            (layerSmaChannel && layerSmaChannel.checked) ||
            (layerIvashov && layerIvashov.checked) ||
            (layerLinReg && layerLinReg.checked) ||
            (layerLinRegChannel && layerLinRegChannel.checked) ||
            (layerKalman && layerKalman.checked) ||
            (layerFractal && layerFractal.checked) ||
            (layerDonchian && layerDonchian.checked) ||
            (layerStochRsi && layerStochRsi.checked) ||
            (layerAc && layerAc.checked) ||
            (layerBullsPower && layerBullsPower.checked) ||
            (layerBearsPower && layerBearsPower.checked) ||
            (layerDpo && layerDpo.checked) ||
            (layerQstick && layerQstick.checked) ||
            (layerVhFilter && layerVhFilter.checked) ||
            (layerEfficiencyRatio && layerEfficiencyRatio.checked) ||
            (layerRavi && layerRavi.checked) ||
            (layerCog && layerCog.checked) ||
            (layerPivotFloor && layerPivotFloor.checked) ||
            (layerMassIndex && layerMassIndex.checked) ||
            (layerNrtrWatr && layerNrtrWatr.checked) ||
            (layerPriceChannelAdaptive && layerPriceChannelAdaptive.checked) ||
            (layerPriceChannelOffset && layerPriceChannelOffset.checked) ||
            (layerParabolicBollinger && layerParabolicBollinger.checked) ||
            (layerParabolicPriceChannel && layerParabolicPriceChannel.checked) ||
            (layerAtrChannel && layerAtrChannel.checked) ||
            (layerOffsetSma && layerOffsetSma.checked) ||
            (layerOffsetEma && layerOffsetEma.checked) ||
            (layerOffsetSsma && layerOffsetSsma.checked) ||
            (layerVolatilityAverage && layerVolatilityAverage.checked) ||
            (layerVolatilityAverageChannel && layerVolatilityAverageChannel.checked) ||
            (layerPriceOscillator && layerPriceOscillator.checked) ||
            (layerDynamicTrendDetector && layerDynamicTrendDetector.checked) ||
            (layerAdaptiveLookBack && layerAdaptiveLookBack.checked) ||
            (layerLinRegChannelFast && layerLinRegChannelFast.checked) ||
            (layerVolatilityCandles && layerVolatilityCandles.checked) ||
            (layerZigZag && layerZigZag.checked) ||
            (layerVolatilityAverageTwice && layerVolatilityAverageTwice.checked) ||
            (layerVolatilityStagesAw && layerVolatilityStagesAw.checked)
        );
    }

    /**
     * Удаляет line-series классики и лишние pane осцилляторов (LWC v5).
     * Логика 1:1 с bots/show — иначе RSI останется поверх свечей.
     */
    function clearClassicOsEngineSeries() {
        if (!chart) {
            classicLineSeries = {};
            rsiLevelLines = [];
            classicOscPaneCount = 0;
            lastClassicNameState = { overlays: [], oscillators: [] };
            lastClassicLayersSig = '';
            renderAllIndicatorNameLabels();
            return;
        }
        Object.keys(classicLineSeries).forEach((k) => {
            try { chart.removeSeries(classicLineSeries[k]); } catch (_) {}
        });
        classicLineSeries = {};
        rsiLevelLines = [];
        try {
            while (typeof chart.panes === 'function' && chart.panes().length > 1) {
                chart.removePane(chart.panes().length - 1);
            }
        } catch (_) {}
        classicOscPaneCount = 0;
        lastClassicNameState = { overlays: [], oscillators: [] };
        lastClassicLayersSig = '';
        try {
            chart.priceScale('right').applyOptions({
                scaleMargins: { top: 0.08, bottom: 0.05 },
            });
        } catch (_) {}
        renderAllIndicatorNameLabels();
    }

    /** Рисует BB/SMA/EMA/VWMA на pane 0; RSI и ATR — в отдельных addPane(). */
    function applyClassicOsEngineSeries() {
        if (!chart || !candlestickSeries) return;
        clearClassicOsEngineSeries();

        const LineSeries = LightweightCharts.LineSeries;
        const HistogramSeries = LightweightCharts.HistogramSeries;
        if (!LineSeries || typeof chart.addSeries !== 'function') {
            console.error('[dashboard indicators] LWC v5 addSeries/LineSeries недоступны');
            return;
        }

        const wantBb = layerBollinger && layerBollinger.checked && lastBollingerPayload;
        const wantRsi = layerRsi && layerRsi.checked && lastRsiPayload;
        const wantSma = layerSma && layerSma.checked && lastSmaPayload;
        const wantEma = layerEma && layerEma.checked && lastEmaPayload;
        const wantVwma = layerVwma && layerVwma.checked && lastVwmaPayload;
        const wantAtr = layerAtr && layerAtr.checked && lastAtrPayload;
        const wantMacd = layerMacd && layerMacd.checked && lastMacdPayload;
        const wantStochastic = layerStochastic && layerStochastic.checked && lastStochasticPayload;
        const wantCci = layerCci && layerCci.checked && lastCciPayload;
        const wantKeltner = layerKeltner && layerKeltner.checked && lastKeltnerPayload;
        const wantEnvelops = layerEnvelops && layerEnvelops.checked && lastEnvelopsPayload;
        const wantParabolicSar = layerParabolicSar && layerParabolicSar.checked && lastParabolicSarPayload;
        const wantSuperTrend = layerSuperTrend && layerSuperTrend.checked && lastSuperTrendPayload;
        const wantAdx = layerAdx && layerAdx.checked && lastAdxPayload;
        const wantWilliams = layerWilliams && layerWilliams.checked && lastWilliamsPayload;
        const wantAo = layerAo && layerAo.checked && lastAoPayload;
        const wantAlligator = layerAlligator && layerAlligator.checked && lastAlligatorPayload;
        const wantIchimoku = layerIchimoku && layerIchimoku.checked && lastIchimokuPayload;
        const wantPriceChannel = layerPriceChannel && layerPriceChannel.checked && lastPriceChannelPayload;
        const wantNrtr = layerNrtr && layerNrtr.checked && lastNrtrPayload;
        const wantHma = layerHma && layerHma.checked && lastHmaPayload;
        const wantAroon = layerAroon && layerAroon.checked && lastAroonPayload;
        const wantMomentum = layerMomentum && layerMomentum.checked && lastMomentumPayload;
        const wantRoc = layerRoc && layerRoc.checked && lastRocPayload;
        const wantOsma = layerOsma && layerOsma.checked && lastOsmaPayload;
        const wantTrix = layerTrix && layerTrix.checked && lastTrixPayload;
        const wantCmo = layerCmo && layerCmo.checked && lastCmoPayload;
        const wantDemarker = layerDemarker && layerDemarker.checked && lastDemarkerPayload;
        const wantRvi = layerRvi && layerRvi.checked && lastRviPayload;
        const wantUltimate = layerUltimate && layerUltimate.checked && lastUltimatePayload;
        const wantSmi = layerSmi && layerSmi.checked && lastSmiPayload;
        const wantStdDev = layerStdDev && layerStdDev.checked && lastStdDevPayload;
        const wantSmaChannel = layerSmaChannel && layerSmaChannel.checked && lastSmaChannelPayload;
        const wantIvashov = layerIvashov && layerIvashov.checked && lastIvashovPayload;
        const wantLinReg = layerLinReg && layerLinReg.checked && lastLinRegPayload;
        const wantLinRegChannel = layerLinRegChannel && layerLinRegChannel.checked && lastLinRegChannelPayload;
        const wantKalman = layerKalman && layerKalman.checked && lastKalmanPayload;
        const wantFractal = layerFractal && layerFractal.checked && lastFractalPayload;
        const wantStrat = layerStratAssistant && layerStratAssistant.checked && lastStratPayload;
        const wantDonchian = layerDonchian && layerDonchian.checked && lastDonchianPayload;
        const wantStochRsi = layerStochRsi && layerStochRsi.checked && lastStochRsiPayload;
        const wantAc = layerAc && layerAc.checked && lastAcPayload;
        const wantBullsPower = layerBullsPower && layerBullsPower.checked && lastBullsPowerPayload;
        const wantBearsPower = layerBearsPower && layerBearsPower.checked && lastBearsPowerPayload;
        const wantDpo = layerDpo && layerDpo.checked && lastDpoPayload;
        const wantQstick = layerQstick && layerQstick.checked && lastQstickPayload;
        const wantVhFilter = layerVhFilter && layerVhFilter.checked && lastVhFilterPayload;
        const wantEfficiencyRatio = layerEfficiencyRatio && layerEfficiencyRatio.checked && lastEfficiencyRatioPayload;
        const wantRavi = layerRavi && layerRavi.checked && lastRaviPayload;
        const wantCog = layerCog && layerCog.checked && lastCogPayload;
        const wantPivotFloor = layerPivotFloor && layerPivotFloor.checked && lastPivotFloorPayload;
        const wantMassIndex = layerMassIndex && layerMassIndex.checked && lastMassIndexPayload;
        const wantNrtrWatr = layerNrtrWatr && layerNrtrWatr.checked && lastNrtrWatrPayload;
        const wantPriceChannelAdaptive = layerPriceChannelAdaptive && layerPriceChannelAdaptive.checked && lastPriceChannelAdaptivePayload;
        const wantPriceChannelOffset = layerPriceChannelOffset && layerPriceChannelOffset.checked && lastPriceChannelOffsetPayload;
        const wantParabolicBollinger = layerParabolicBollinger && layerParabolicBollinger.checked && lastParabolicBollingerPayload;
        const wantParabolicPriceChannel = layerParabolicPriceChannel && layerParabolicPriceChannel.checked && lastParabolicPriceChannelPayload;
        const wantAtrChannel = layerAtrChannel && layerAtrChannel.checked && lastAtrChannelPayload;
        const wantOffsetSma = layerOffsetSma && layerOffsetSma.checked && lastOffsetSmaPayload;
        const wantOffsetEma = layerOffsetEma && layerOffsetEma.checked && lastOffsetEmaPayload;
        const wantOffsetSsma = layerOffsetSsma && layerOffsetSsma.checked && lastOffsetSsmaPayload;
        const wantVolatilityAverage = layerVolatilityAverage && layerVolatilityAverage.checked && lastVolatilityAveragePayload;
        const wantVolatilityAverageChannel = layerVolatilityAverageChannel && layerVolatilityAverageChannel.checked && lastVolatilityAverageChannelPayload;
        const wantPriceOscillator = layerPriceOscillator && layerPriceOscillator.checked && lastPriceOscillatorPayload;
        const wantDynamicTrendDetector = layerDynamicTrendDetector && layerDynamicTrendDetector.checked && lastDynamicTrendDetectorPayload;
        const wantAdaptiveLookBack = layerAdaptiveLookBack && layerAdaptiveLookBack.checked && lastAdaptiveLookBackPayload;
        const wantLinRegChannelFast = layerLinRegChannelFast && layerLinRegChannelFast.checked && lastLinRegChannelFastPayload;
        const wantVolatilityCandles = layerVolatilityCandles && layerVolatilityCandles.checked && lastVolatilityCandlesPayload;
        const wantZigZag = layerZigZag && layerZigZag.checked && lastZigZagPayload;
        const wantVolatilityAverageTwice = layerVolatilityAverageTwice && layerVolatilityAverageTwice.checked && lastVolatilityAverageTwicePayload;
        const wantVolatilityStagesAw = layerVolatilityStagesAw && layerVolatilityStagesAw.checked && lastVolatilityStagesAwPayload;

        const addMainLine = (key, data, color, width) => {
            if (!Array.isArray(data) || !data.length) return;
            const clipped = mapClippedLinePoints(data);
            if (!clipped.length) return;
            const s = chart.addSeries(LineSeries, {
                color: color || '#22c55e',
                lineWidth: width || 1,
                priceLineVisible: false,
                lastValueVisible: false,
                crosshairMarkerVisible: false,
            }, 0);
            s.setData(clipped);
            classicLineSeries[key] = s;
        };

        if (wantBb) {
            const st = lastBollingerPayload.style || {};
            const ser = lastBollingerPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('bb_up', ser.up, st.color_up, w);
            addMainLine('bb_mid', ser.mid, st.color_mid, w);
            addMainLine('bb_down', ser.down, st.color_down, w);
        }
        if (wantSma) {
            const st = lastSmaPayload.style || {};
            addMainLine('sma', (lastSmaPayload.series || {}).ma, st.color, st.line_width);
        }
        if (wantEma) {
            const st = lastEmaPayload.style || {};
            addMainLine('ema', (lastEmaPayload.series || {}).ema, st.color, st.line_width);
        }
        if (wantVwma) {
            const st = lastVwmaPayload.style || {};
            addMainLine('vwma', (lastVwmaPayload.series || {}).vwma, st.color, st.line_width);
        }

        if (wantKeltner) {
            const st = lastKeltnerPayload.style || {};
            const ser = lastKeltnerPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('keltner_up', ser.up, st.color_up, w);
            addMainLine('keltner_mid', ser.mid, st.color_mid, w);
            addMainLine('keltner_down', ser.down, st.color_down, w);
        }
        if (wantEnvelops) {
            const st = lastEnvelopsPayload.style || {};
            const ser = lastEnvelopsPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('envelops_up', ser.up, st.color_up, w);
            addMainLine('envelops_mid', ser.mid, st.color_mid, w);
            addMainLine('envelops_down', ser.down, st.color_down, w);
        }
        if (wantParabolicSar) {
            const st = lastParabolicSarPayload.style || {};
            const data = (lastParabolicSarPayload.series || {}).sar;
            if (Array.isArray(data) && data.length) {
                const s = chart.addSeries(LineSeries, {
                    color: st.color || '#1e90ff',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 2,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries['parabolic_sar'] = s;
            }
        }
        if (wantSuperTrend) {
            const st = lastSuperTrendPayload.style || {};
            const ser = lastSuperTrendPayload.series || {};
            const w = st.line_width || 2;
            addMainLine('supertrend_center', ser.center, st.color_center, w);
            addMainLine('supertrend_upper', ser.upper, st.color_upper, w);
            addMainLine('supertrend_lower', ser.lower, st.color_lower, w);
        }
        if (wantAlligator) {
            const st = lastAlligatorPayload.style || {};
            const ser = lastAlligatorPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('alligator_jaw', ser.jaw, st.color_jaw, w);
            addMainLine('alligator_teeth', ser.teeth, st.color_teeth, w);
            addMainLine('alligator_lips', ser.lips, st.color_lips, w);
        }
        if (wantIchimoku) {
            const st = lastIchimokuPayload.style || {};
            const ser = lastIchimokuPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('ichimoku_tenkan', ser.tenkan, st.color_tenkan, w);
            addMainLine('ichimoku_kijun', ser.kijun, st.color_kijun, w);
            addMainLine('ichimoku_senkou_a', ser.senkou_a, st.color_senkou_a, w);
            addMainLine('ichimoku_senkou_b', ser.senkou_b, st.color_senkou_b, w);
            const chinkou = ser.chinkou;
            if (Array.isArray(chinkou) && chinkou.length) {
                const s = chart.addSeries(LineSeries, {
                    color: st.color_chinkou || '#8b0000',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.chinkou_point_radius || 2,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(mapClippedLinePoints(chinkou));
                classicLineSeries['ichimoku_chinkou'] = s;
            }
        }
        if (wantPriceChannel) {
            const st = lastPriceChannelPayload.style || {};
            const ser = lastPriceChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('price_channel_up', ser.up, st.color_up, w);
            addMainLine('price_channel_down', ser.down, st.color_down, w);
        }
        if (wantNrtr) {
            const st = lastNrtrPayload.style || {};
            const ser = lastNrtrPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('nrtr_center', ser.center, st.color_center, w);
            addMainLine('nrtr_up', ser.up, st.color_up, w);
            addMainLine('nrtr_down', ser.down, st.color_down, w);
        }
        if (wantHma) {
            const st = lastHmaPayload.style || {};
            addMainLine('hma', (lastHmaPayload.series || {}).hma, st.color, st.line_width);
        }
        if (wantSmaChannel) {
            const st = lastSmaChannelPayload.style || {};
            const ser = lastSmaChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('sma_channel_up', ser.up, st.color_up, w);
            addMainLine('sma_channel_mid', ser.mid, st.color_mid, w);
            addMainLine('sma_channel_down', ser.down, st.color_down, w);
        }
        if (wantLinReg) {
            const st = lastLinRegPayload.style || {};
            addMainLine('linreg', (lastLinRegPayload.series || {}).linreg, st.color, st.line_width);
        }
        if (wantLinRegChannel) {
            const st = lastLinRegChannelPayload.style || {};
            const ser = lastLinRegChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('linreg_channel_up', ser.up, st.color_up, w);
            addMainLine('linreg_channel_mid', ser.mid, st.color_mid, w);
            addMainLine('linreg_channel_down', ser.down, st.color_down, w);
        }
        if (wantKalman) {
            const st = lastKalmanPayload.style || {};
            addMainLine('kalman', (lastKalmanPayload.series || {}).kalman, st.color, st.line_width);
        }
        if (wantFractal) {
            const st = lastFractalPayload.style || {};
            const ser = lastFractalPayload.series || {};
            // OsEngine Point: фрактал — отдельные точки без соединяющей линии (как Parabolic SAR).
            const addFractalPoints = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#00bfff',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 3,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
            };
            addFractalPoints('fractal_up', ser.up, st.color_up || '#00bfff');
            addFractalPoints('fractal_down', ser.down, st.color_down || '#ff4500');
        }
        if (wantDonchian) {
            const st = lastDonchianPayload.style || {};
            const ser = lastDonchianPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('donchian_up', ser.up, st.color_up, w);
            addMainLine('donchian_mid', ser.mid, st.color_mid, w);
            addMainLine('donchian_down', ser.down, st.color_down, w);
        }
        if (wantPivotFloor) {
            const st = lastPivotFloorPayload.style || {};
            const ser = lastPivotFloorPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('pivot_p', ser.p, st.color_p, w);
            addMainLine('pivot_r1', ser.r1, st.color_r, w);
            addMainLine('pivot_r2', ser.r2, st.color_r, w);
            addMainLine('pivot_r3', ser.r3, st.color_r, w);
            addMainLine('pivot_s1', ser.s1, st.color_s, w);
            addMainLine('pivot_s2', ser.s2, st.color_s, w);
            addMainLine('pivot_s3', ser.s3, st.color_s, w);
        }


        // Priority-1 overlays on pane 0.
        if (wantNrtrWatr) {
            const st = lastNrtrWatrPayload.style || {};
            const ser = lastNrtrWatrPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('nrtr_watr', ser.nrtr, st.color, w);
        }
        if (wantPriceChannelAdaptive) {
            const st = lastPriceChannelAdaptivePayload.style || {};
            const ser = lastPriceChannelAdaptivePayload.series || {};
            const w = st.line_width || 1;
            addMainLine('pca_up', ser.up, st.color_up, w);
            addMainLine('pca_down', ser.down, st.color_down, w);
        }
        if (wantPriceChannelOffset) {
            const st = lastPriceChannelOffsetPayload.style || {};
            const ser = lastPriceChannelOffsetPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('pco_up', ser.up, st.color_up, w);
            addMainLine('pco_down', ser.down, st.color_down, w);
            addMainLine('pco_up_border', ser.up_border, st.color_up_border, w);
            addMainLine('pco_down_border', ser.down_border, st.color_down_border, w);
        }
        if (wantParabolicBollinger) {
            const st = lastParabolicBollingerPayload.style || {};
            const ser = lastParabolicBollingerPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('pb_up', ser.up, st.color_up, w);
            addMainLine('pb_down', ser.down, st.color_down, w);
            const addParabPoints = (pkey, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#ffff00',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 2,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[pkey] = s;
            };
            addParabPoints('parabolic_bollinger_parab', ser.parabolic, st.color_parabolic);
        }
        if (wantParabolicPriceChannel) {
            const st = lastParabolicPriceChannelPayload.style || {};
            const ser = lastParabolicPriceChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('ppc_up', ser.up, st.color_up, w);
            addMainLine('ppc_down', ser.down, st.color_down, w);
            const addParabPoints = (pkey, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#ffff00',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 2,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[pkey] = s;
            };
            addParabPoints('parabolic_price_channel_parab', ser.parabolic, st.color_parabolic);
        }
        if (wantAtrChannel) {
            const st = lastAtrChannelPayload.style || {};
            const ser = lastAtrChannelPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('atr_channel', ser.channel, st.color, w);
        }
        if (wantOffsetSma) {
            const st = lastOffsetSmaPayload.style || {};
            const ser = lastOffsetSmaPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('offset_sma', ser.sma, st.color, w);
        }
        if (wantOffsetEma) {
            const st = lastOffsetEmaPayload.style || {};
            const ser = lastOffsetEmaPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('offset_ema', ser.ema, st.color, w);
        }
        if (wantOffsetSsma) {
            const st = lastOffsetSsmaPayload.style || {};
            const ser = lastOffsetSsmaPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('offset_ssma', ser.ssma, st.color, w);
        }
        if (wantDynamicTrendDetector) {
            const st = lastDynamicTrendDetectorPayload.style || {};
            const ser = lastDynamicTrendDetectorPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('dtd', ser.dtd, st.color, w);
        }
        if (wantLinRegChannelFast) {
            const st = lastLinRegChannelFastPayload.style || {};
            const ser = lastLinRegChannelFastPayload.series || {};
            const w = st.line_width || 1;
            addMainLine('lrcf_up', ser.up, st.color_up, w);
            addMainLine('lrcf_mid', ser.mid, st.color_mid, w);
            addMainLine('lrcf_down', ser.down, st.color_down, w);
        }


        // ZigZag — ломаная + точки хай/лоу на основной pane.
        if (wantZigZag) {
            const st = lastZigZagPayload.style || {};
            const ser = lastZigZagPayload.series || {};
            const w = st.line_width || 1;
            if (ser.line && ser.line.length) {
                addMainLine('zz_line', ser.line, st.color_line || '#6495ed', w);
            }
            const addZzPoints = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#adff2f',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 3,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, 0);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
            };
            addZzPoints('zz_highs', ser.highs, st.color_high || '#adff2f');
            addZzPoints('zz_lows', ser.lows, st.color_low || '#ff0000');
            addZzPoints('zz_pts', ser.zigzag, st.color_line || '#6495ed');
        }

        const oscCount = (wantRsi ? 1 : 0) + (wantMacd ? 1 : 0) + (wantStochastic ? 1 : 0) + (wantCci ? 1 : 0) + (wantAtr ? 1 : 0) + (wantAdx ? 1 : 0) + (wantWilliams ? 1 : 0) + (wantAo ? 1 : 0) + (wantAroon ? 1 : 0)
            + (wantMomentum ? 1 : 0) + (wantRoc ? 1 : 0) + (wantOsma ? 1 : 0) + (wantTrix ? 1 : 0) + (wantCmo ? 1 : 0) + (wantDemarker ? 1 : 0) + (wantRvi ? 1 : 0) + (wantUltimate ? 1 : 0) + (wantSmi ? 1 : 0) + (wantStdDev ? 1 : 0) + (wantIvashov ? 1 : 0)
            + (wantStochRsi ? 1 : 0) + (wantAc ? 1 : 0) + (wantBullsPower ? 1 : 0) + (wantBearsPower ? 1 : 0) + (wantDpo ? 1 : 0) + (wantQstick ? 1 : 0) + (wantVhFilter ? 1 : 0) + (wantEfficiencyRatio ? 1 : 0) + (wantRavi ? 1 : 0) + (wantCog ? 1 : 0) + (wantMassIndex ? 1 : 0) + (wantVolatilityAverage ? 1 : 0) + (wantVolatilityAverageChannel ? 1 : 0) + (wantPriceOscillator ? 1 : 0) + (wantAdaptiveLookBack ? 1 : 0) + (wantVolatilityCandles ? 1 : 0) + (wantVolatilityAverageTwice ? 1 : 0) + (wantVolatilityStagesAw ? 2 : 0);
        if (oscCount > 0) {
            try {
                const mainPane = chart.panes()[0];
                if (mainPane && typeof mainPane.setStretchFactor === 'function') {
                    mainPane.setStretchFactor(1);
                }
            } catch (_) {}
        }

        if (wantRsi) {
            const st = lastRsiPayload.style || {};
            const levels = lastRsiPayload.levels || {};
            const data = ((lastRsiPayload.series || {}).rsi) || [];
            // Нет точек (мало баров на H1/H4) — не создаём пустую чёрную pane.
            if (!Array.isArray(data) || !data.length) {
                // skip empty RSI pane
            } else {
            let paneIndex = 1;
            let rsiPane = null;
            try {
                rsiPane = chart.addPane(false);
                if (rsiPane && typeof rsiPane.setStretchFactor === 'function') {
                    rsiPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane RSI', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#1e90ff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                baseLineVisible: false,
                // Показываем текущее значение RSI справа, чтобы видеть актуальный уровень.
                lastValueVisible: true,
                crosshairMarkerVisible: false,
                // RSI — осциллятор в шкале 0..100, отображаем целые значения.
                priceFormat: { type: 'price', precision: 0, minMove: 1 },
                // Жёсткий диапазон нужен, чтобы уровни 70/30 всегда были видимыми.
                autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
            }, paneIndex);
            s.setData(mapClippedLinePoints(data));
            classicLineSeries['rsi'] = s;
            // Небольшие внешние отступы для более аккуратного расположения линии RSI.
            try {
                s.priceScale().applyOptions({
                    scaleMargins: { top: 0.08, bottom: 0.08 },
                    borderVisible: false,
                    entireTextOnly: true,
                });
            } catch (_) {}

            // Накладываем однотонный фон именно на RSI-pane, чтобы визуально погасить сетку.
            try {
                const paneApi = rsiPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] RSI pane backdrop', e);
            }

            // Подписи 70/30 на правой шкале для быстрого чтения перекупленности/перепроданности.
            try {
                const lines = (window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function')
                    ? window.PlatformChartMarkers.attachRsiLevelLines(s, levels)
                    : [];
                rsiLevelLines.push(...lines);
            } catch (_) {}
            } // end non-empty RSI data
        }

        // MACD pane: histogram + lines + нулевая линия (как на bots/show).
        if (wantMacd) {
            const st = lastMacdPayload.style || {};
            const ser = lastMacdPayload.series || {};
            const histColor = st.color_histogram || '#1e90ff';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.32);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane MACD', e);
            }

            if (HistogramSeries && Array.isArray(ser.histogram) && ser.histogram.length) {
                try {
                    const hs = chart.addSeries(HistogramSeries, {
                        priceLineVisible: false,
                        lastValueVisible: false,
                        baseLineVisible: true,
                        baseLineColor: 'rgba(161,161,170,0.45)',
                        baseLineWidth: 1,
                        baseLineStyle: 2,
                    }, paneIndex);
                    hs.setData(ser.histogram.map((p) => ({
                        time: p.time,
                        value: p.value,
                        color: Number(p.value) >= 0 ? histColor : histNeg,
                    })));
                    classicLineSeries['macd_hist'] = hs;
                } catch (e) {
                    console.error('[dashboard indicators] MACD histogram', e);
                }
            }

            const addMacdLine = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#006400',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: key === 'macd_line',
                    crosshairMarkerVisible: false,
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
                return s;
            };
            const macdLine = addMacdLine('macd_line', ser.macd, st.color_macd || '#006400');
            addMacdLine('macd_signal', ser.signal, st.color_signal || '#8b0000');

            try {
                const anchor = macdLine || classicLineSeries['macd_signal'] || classicLineSeries['macd_hist'];
                if (anchor && typeof anchor.createPriceLine === 'function') {
                    rsiLevelLines.push(anchor.createPriceLine({
                        price: 0,
                        color: 'rgba(161,161,170,0.55)',
                        lineWidth: 1,
                        lineStyle: 2,
                        axisLabelVisible: true,
                        title: '0',
                    }));
                }
            } catch (_) {}
        }

        // Stochastic pane 0..100 (%K/%D + уровни 80/20).
        if (wantStochastic) {
            const st = lastStochasticPayload.style || {};
            const levels = lastStochasticPayload.levels || {};
            const ser = lastStochasticPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let stochPane = null;
            try {
                stochPane = chart.addPane(false);
                if (stochPane && typeof stochPane.setStretchFactor === 'function') {
                    stochPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Stochastic', e);
            }

            const addStochLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 0, minMove: 1 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
                return s;
            };
            const kLine = addStochLine('stoch_k', ser.k, st.color_k || '#1e90ff', true);
            addStochLine('stoch_d', ser.d, st.color_d || '#8b0000', false);

            try {
                const anchor = kLine || classicLineSeries['stoch_d'];
                if (anchor && typeof anchor.priceScale === 'function') {
                    anchor.priceScale().applyOptions({
                        scaleMargins: { top: 0.08, bottom: 0.08 },
                        borderVisible: false,
                        entireTextOnly: true,
                    });
                }
            } catch (_) {}

            try {
                const paneApi = stochPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] Stochastic pane backdrop', e);
            }

            try {
                const anchor = kLine || classicLineSeries['stoch_d'];
                if (anchor && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    const lines = window.PlatformChartMarkers.attachRsiLevelLines(anchor, levels);
                    rsiLevelLines.push(...lines);
                }
            } catch (_) {}
        }

        // CCI — отдельный pane без фиксированной шкалы 0..100 (значения не ограничены).
        if (wantCci) {
            const st = lastCciPayload.style || {};
            const levels = lastCciPayload.levels || {};
            const ser = lastCciPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let cciPane = null;
            try {
                cciPane = chart.addPane(false);
                if (cciPane && typeof cciPane.setStretchFactor === 'function') {
                    cciPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane CCI', e);
            }

            const cciData = ser.cci;
            let cciLine = null;
            if (Array.isArray(cciData) && cciData.length) {
                // Обычный autoscale — CCI может уходить далеко за ±100.
                cciLine = chart.addSeries(LineSeries, {
                    color: st.color || '#5f9ea0',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                cciLine.setData(mapClippedLinePoints(cciData));
                classicLineSeries['cci'] = cciLine;
            }

            try {
                const paneApi = cciPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] CCI pane backdrop', e);
            }

            try {
                if (cciLine && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    const lines = window.PlatformChartMarkers.attachRsiLevelLines(cciLine, levels);
                    rsiLevelLines.push(...lines);
                }
            } catch (_) {}

            // Нулевая линия CCI (как у MACD — priceLine на основной серии).
            try {
                if (cciLine && typeof cciLine.createPriceLine === 'function') {
                    rsiLevelLines.push(cciLine.createPriceLine({
                        price: 0,
                        color: 'rgba(161,161,170,0.55)',
                        lineWidth: 1,
                        lineStyle: 2,
                        axisLabelVisible: true,
                        title: '0',
                    }));
                }
            } catch (_) {}
        }


        // ADX — отдельный pane (ADX / +DI / −DI), уровни 20/25.
        if (wantAdx) {
            const st = lastAdxPayload.style || {};
            const levels = lastAdxPayload.levels || {};
            const ser = lastAdxPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let adxPane = null;
            try {
                adxPane = chart.addPane(false);
                if (adxPane && typeof adxPane.setStretchFactor === 'function') {
                    adxPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane ADX', e);
            }

            const mk = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#228b22',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
                return s;
            };
            const adxLine = mk('adx', ser.adx, st.color_adx);
            mk('adx_plus', ser.plus, st.color_plus);
            mk('adx_minus', ser.minus, st.color_minus);

            try {
                const paneApi = adxPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi && typeof paneApi.attachPrimitive === 'function'
                    && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[indicators] ADX pane backdrop', e);
            }

            try {
                if (adxLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    const lines = window.PlatformChartMarkers.attachRsiLevelLines(adxLine, levels);
                    rsiLevelLines.push(...lines);
                }
            } catch (_) {}
        }

        if (wantWilliams) {
            const st = lastWilliamsPayload.style || {};
            const levels = lastWilliamsPayload.levels || {};
            const ser = lastWilliamsPayload.series || {};
            let paneIndex = 1;
            let wrPane = null;
            try {
                wrPane = chart.addPane(false);
                if (wrPane && typeof wrPane.setStretchFactor === 'function') wrPane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane Williams', e); }
            const data = ser.wr || [];
            let wrLine = null;
            if (Array.isArray(data) && data.length) {
                wrLine = chart.addSeries(LineSeries, {
                    color: st.color || '#1e90ff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: -100, maxValue: 0 } }),
                }, paneIndex);
                wrLine.setData(mapClippedLinePoints(data));
                classicLineSeries['williams'] = wrLine;
            }
            try {
                if (wrLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(wrLine, levels));
                }
            } catch (_) {}
        }

        if (wantAo) {
            const st = lastAoPayload.style || {};
            const ser = lastAoPayload.series || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane?.setStretchFactor) pane.setStretchFactor(0.32);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane AO', e); }
            if (HistogramSeries && Array.isArray(ser.ao) && ser.ao.length) {
                const hs = chart.addSeries(HistogramSeries, {
                    priceLineVisible: false,
                    lastValueVisible: false,
                    baseLineVisible: true,
                    baseLineColor: 'rgba(161,161,170,0.45)',
                    baseLineWidth: 1,
                    baseLineStyle: 2,
                }, paneIndex);
                hs.setData(ser.ao.map((p) => ({
                    time: p.time,
                    value: p.value,
                    color: Number(p.value) >= 0 ? (st.color_histogram || '#006400') : (st.color_histogram_neg || '#f43f5e'),
                })));
                classicLineSeries['ao_hist'] = hs;
            }
        }

        if (wantAroon) {
            const st = lastAroonPayload.style || {};
            const levels = lastAroonPayload.levels || {};
            const ser = lastAroonPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane?.setStretchFactor) pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) { console.error('[dashboard] addPane Aroon', e); }
            const mkAroon = (key, data, color) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#008000',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 0, minMove: 1 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
                return s;
            };
            const upLine = mkAroon('aroon_up', ser.up, st.color_up);
            mkAroon('aroon_down', ser.down, st.color_down);
            try {
                const anchor = upLine || classicLineSeries['aroon_down'];
                if (anchor && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(anchor, levels));
                }
            } catch (_) {}
        }

        // Momentum — pane вокруг «нейтрали» 100.
        if (wantMomentum) {
            const st = lastMomentumPayload.style || {};
            const levels = lastMomentumPayload.levels || {};
            let paneIndex = 1;
            let momentumPane = null;
            try {
                momentumPane = chart.addPane(false);
                if (momentumPane && typeof momentumPane.setStretchFactor === 'function') {
                    momentumPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Momentum', e);
            }
            const data = (lastMomentumPayload.series || {}).momentum || [];
            let momentumLine = null;
            if (Array.isArray(data) && data.length) {
                momentumLine = chart.addSeries(LineSeries, {
                    color: st.color || '#00ffff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                momentumLine.setData(mapClippedLinePoints(data));
                classicLineSeries['momentum'] = momentumLine;
            }
            try {
                const paneApi = momentumPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] Momentum pane backdrop', e);
            }
            try {
                if (momentumLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(momentumLine, levels));
                }
            } catch (_) {}
        }

        // ROC — pane вокруг нулевой линии.
        if (wantRoc) {
            const st = lastRocPayload.style || {};
            const levels = lastRocPayload.levels || {};
            let paneIndex = 1;
            let rocPane = null;
            try {
                rocPane = chart.addPane(false);
                if (rocPane && typeof rocPane.setStretchFactor === 'function') {
                    rocPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane ROC', e);
            }
            const data = (lastRocPayload.series || {}).roc || [];
            let rocLine = null;
            if (Array.isArray(data) && data.length) {
                rocLine = chart.addSeries(LineSeries, {
                    color: st.color || '#00ffff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                rocLine.setData(mapClippedLinePoints(data));
                classicLineSeries['roc'] = rocLine;
            }
            try {
                const paneApi = rocPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] ROC pane backdrop', e);
            }
            try {
                if (rocLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(rocLine, levels));
                }
            } catch (_) {}
        }

        // OsMA — как MACD: histogram + osma line + signal (+ нулевая линия).
        if (wantOsma) {
            const st = lastOsmaPayload.style || {};
            const ser = lastOsmaPayload.series || {};
            const histColor = st.color_histogram || '#1e90ff';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.32);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane OsMA', e);
            }
            if (HistogramSeries && Array.isArray(ser.histogram) && ser.histogram.length) {
                try {
                    const hs = chart.addSeries(HistogramSeries, {
                        priceLineVisible: false,
                        lastValueVisible: false,
                        baseLineVisible: true,
                        baseLineColor: 'rgba(161,161,170,0.45)',
                        baseLineWidth: 1,
                        baseLineStyle: 2,
                    }, paneIndex);
                    hs.setData(ser.histogram.map((p) => ({
                        time: p.time,
                        value: p.value,
                        color: Number(p.value) >= 0 ? histColor : histNeg,
                    })));
                    classicLineSeries['osma_hist'] = hs;
                } catch (e) {
                    console.error('[dashboard indicators] OsMA histogram', e);
                }
            }
            const addOsmaLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#ffff00',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
                return s;
            };
            const osmaLine = addOsmaLine('osma_line', ser.osma, st.color_osma || '#ffff00', true);
            addOsmaLine('osma_signal', ser.signal, st.color_signal || '#ff0000', false);
            try {
                const anchor = osmaLine || classicLineSeries['osma_signal'] || classicLineSeries['osma_hist'];
                if (anchor && typeof anchor.createPriceLine === 'function') {
                    rsiLevelLines.push(anchor.createPriceLine({
                        price: 0,
                        color: 'rgba(161,161,170,0.55)',
                        lineWidth: 1,
                        lineStyle: 2,
                        axisLabelVisible: true,
                        title: '0',
                    }));
                }
            } catch (_) {}
        }

        // TRIX — pane вокруг нулевой линии.
        if (wantTrix) {
            const st = lastTrixPayload.style || {};
            const levels = lastTrixPayload.levels || {};
            let paneIndex = 1;
            let trixPane = null;
            try {
                trixPane = chart.addPane(false);
                if (trixPane && typeof trixPane.setStretchFactor === 'function') {
                    trixPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane TRIX', e);
            }
            const data = (lastTrixPayload.series || {}).trix || [];
            let trixLine = null;
            if (Array.isArray(data) && data.length) {
                trixLine = chart.addSeries(LineSeries, {
                    color: st.color || '#00ffff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                trixLine.setData(mapClippedLinePoints(data));
                classicLineSeries['trix'] = trixLine;
            }
            try {
                const paneApi = trixPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] TRIX pane backdrop', e);
            }
            try {
                if (trixLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(trixLine, levels));
                }
            } catch (_) {}
        }

        // CMO — pane с уровнями ±50.
        if (wantCmo) {
            const st = lastCmoPayload.style || {};
            const levels = lastCmoPayload.levels || {};
            let paneIndex = 1;
            let cmoPane = null;
            try {
                cmoPane = chart.addPane(false);
                if (cmoPane && typeof cmoPane.setStretchFactor === 'function') {
                    cmoPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane CMO', e);
            }
            const data = (lastCmoPayload.series || {}).cmo || [];
            let cmoLine = null;
            if (Array.isArray(data) && data.length) {
                cmoLine = chart.addSeries(LineSeries, {
                    color: st.color || '#ffa500',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                cmoLine.setData(mapClippedLinePoints(data));
                classicLineSeries['cmo'] = cmoLine;
            }
            try {
                const paneApi = cmoPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] CMO pane backdrop', e);
            }
            try {
                if (cmoLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(cmoLine, levels));
                }
            } catch (_) {}
        }

        // DeMarker — pane в шкале 0..1 с уровнями 0.7/0.3.
        if (wantDemarker) {
            const st = lastDemarkerPayload.style || {};
            const levels = lastDemarkerPayload.levels || {};
            let paneIndex = 1;
            let demPane = null;
            try {
                demPane = chart.addPane(false);
                if (demPane && typeof demPane.setStretchFactor === 'function') {
                    demPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane DeMarker', e);
            }
            const data = (lastDemarkerPayload.series || {}).dem || [];
            let demLine = null;
            if (Array.isArray(data) && data.length) {
                demLine = chart.addSeries(LineSeries, {
                    color: st.color || '#00ffff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    // DeMarker всегда в диапазоне 0..1 — фиксируем шкалу под уровни 0.7/0.3.
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 1 } }),
                }, paneIndex);
                demLine.setData(mapClippedLinePoints(data));
                classicLineSeries['demarker'] = demLine;
            }
            try {
                const paneApi = demPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] DeMarker pane backdrop', e);
            }
            try {
                if (demLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(demLine, levels));
                }
            } catch (_) {}
        }

        // RVI — pane: линия + сигнал (без фиксированных уровней).
        if (wantRvi) {
            const st = lastRviPayload.style || {};
            const ser = lastRviPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let rviPane = null;
            try {
                rviPane = chart.addPane(false);
                if (rviPane && typeof rviPane.setStretchFactor === 'function') {
                    rviPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane RVI', e);
            }
            const addRviLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#8b0000',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
                return s;
            };
            addRviLine('rvi', ser.rvi, st.color_rvi || '#8b0000', true);
            addRviLine('rvi_signal', ser.signal, st.color_signal || '#1e90ff', false);
            try {
                const paneApi = rviPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] RVI pane backdrop', e);
            }
        }

        // Ultimate Oscillator — pane 0..100 с уровнями 70/30.
        if (wantUltimate) {
            const st = lastUltimatePayload.style || {};
            const levels = lastUltimatePayload.levels || {};
            let paneIndex = 1;
            let uoPane = null;
            try {
                uoPane = chart.addPane(false);
                if (uoPane && typeof uoPane.setStretchFactor === 'function') {
                    uoPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Ultimate', e);
            }
            const data = (lastUltimatePayload.series || {}).uo || [];
            let uoLine = null;
            if (Array.isArray(data) && data.length) {
                uoLine = chart.addSeries(LineSeries, {
                    color: st.color || '#1e90ff',
                    lineWidth: st.line_width || 1,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: true,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                uoLine.setData(mapClippedLinePoints(data));
                classicLineSeries['uo'] = uoLine;
            }
            try {
                const paneApi = uoPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] Ultimate pane backdrop', e);
            }
            try {
                if (uoLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(uoLine, levels));
                }
            } catch (_) {}
        }

        // SMI — pane: линия + сигнал + уровни ±40.
        if (wantSmi) {
            const st = lastSmiPayload.style || {};
            const levels = lastSmiPayload.levels || {};
            const ser = lastSmiPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            let smiPane = null;
            try {
                smiPane = chart.addPane(false);
                if (smiPane && typeof smiPane.setStretchFactor === 'function') {
                    smiPane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane SMI', e);
            }
            const addSmiLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#8a2be2',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
                return s;
            };
            const smiLine = addSmiLine('smi', ser.smi, st.color_smi || '#8a2be2', true);
            addSmiLine('smi_signal', ser.signal, st.color_signal || '#008000', false);
            try {
                const paneApi = smiPane || (typeof chart.panes === 'function' ? chart.panes()[paneIndex] : null);
                if (paneApi?.attachPrimitive && window.PlatformChartMarkers?.createPaneSolidBackdrop) {
                    paneApi.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                }
            } catch (e) {
                console.error('[dashboard indicators] SMI pane backdrop', e);
            }
            try {
                if (smiLine && levels.show && window.PlatformChartMarkers?.attachRsiLevelLines) {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(smiLine, levels));
                }
            } catch (_) {}
        }

        // StdDev — ещё один pane волатильности (без уровней).
        if (wantStdDev) {
            const st = lastStdDevPayload.style || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane StdDev', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#008000',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
            }, paneIndex);
            const data = ((lastStdDevPayload.series || {}).stddev) || [];
            s.setData(mapClippedLinePoints(data));
            classicLineSeries['stddev'] = s;
        }

        // Ivashov Range — ещё один pane волатильности (без уровней).
        if (wantIvashov) {
            const st = lastIvashovPayload.style || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Ivashov', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#0000ff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
            }, paneIndex);
            const data = ((lastIvashovPayload.series || {}).ivashov) || [];
            s.setData(mapClippedLinePoints(data));
            classicLineSeries['ivashov'] = s;
        }

        if (wantStochRsi) {
            const st = lastStochRsiPayload.style || {};
            const levels = lastStochRsiPayload.levels || {};
            const ser = lastStochRsiPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Stoch RSI', e);
            }
            const addStochRsiLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return null;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    baseLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 0, minMove: 1 },
                    autoscaleInfoProvider: () => ({ priceRange: { minValue: 0, maxValue: 100 } }),
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
                return s;
            };
            const kLine = addStochRsiLine('stoch_rsi_k', ser.k, st.color_k, true);
            addStochRsiLine('stoch_rsi_d', ser.d, st.color_d, false);
            try {
                const anchor = kLine || classicLineSeries['stoch_rsi_d'];
                if (anchor && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function') {
                    rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(anchor, levels));
                }
            } catch (_) {}
        }

        const addHistPane = (key, payload, seriesKey) => {
            if (!payload) return;
            const st = payload.style || {};
            const ser = payload.series || {};
            const histColor = st.color_histogram || '#006400';
            const histNeg = st.color_histogram_neg || '#f43f5e';
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.32);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane', key, e);
                return;
            }
            const data = ser[seriesKey];
            if (HistogramSeries && Array.isArray(data) && data.length) {
                const hs = chart.addSeries(HistogramSeries, {
                    priceLineVisible: false,
                    lastValueVisible: false,
                    baseLineVisible: true,
                    baseLineColor: 'rgba(161,161,170,0.45)',
                    baseLineWidth: 1,
                    baseLineStyle: 2,
                }, paneIndex);
                hs.setData(mapClippedHistPoints(data, histColor, histNeg));
                classicLineSeries[key] = hs;
            }
        };
        if (wantAc) addHistPane('ac_hist', lastAcPayload, 'ac');
        if (wantBullsPower) addHistPane('bulls_power_hist', lastBullsPowerPayload, 'bulls_power');
        if (wantBearsPower) addHistPane('bears_power_hist', lastBearsPowerPayload, 'bears_power');

        const addSimpleOscPaneLine = (key, payload, seriesKey, colorKey) => {
            if (!payload) return;
            const st = payload.style || {};
            const data = (payload.series || {})[seriesKey] || [];
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane', key, e);
                return;
            }
            if (!Array.isArray(data) || !data.length) return;
            const s = chart.addSeries(LineSeries, {
                color: st[colorKey] || st.color || '#00ffff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                baseLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
                priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
            }, paneIndex);
            s.setData(mapClippedLinePoints(data));
            classicLineSeries[key] = s;
        };
        if (wantDpo) addSimpleOscPaneLine('dpo', lastDpoPayload, 'dpo', 'color');
        if (wantQstick) addSimpleOscPaneLine('qstick', lastQstickPayload, 'qstick', 'color');
        if (wantVhFilter) addSimpleOscPaneLine('vh_filter', lastVhFilterPayload, 'vh_filter', 'color');
        if (wantEfficiencyRatio) addSimpleOscPaneLine('efficiency_ratio', lastEfficiencyRatioPayload, 'er', 'color');

        if (wantRavi) {
            const st = lastRaviPayload.style || {};
            const ser = lastRaviPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane RAVI', e);
            }
            const addRaviLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#8b0000',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
            };
            addRaviLine('ravi', ser.ravi, st.color_ravi, true);
            addRaviLine('ravi_up_line', ser.up_line, st.color_up_line, false);
            addRaviLine('ravi_down_line', ser.down_line, st.color_down_line, false);
        }

        if (wantCog) {
            const st = lastCogPayload.style || {};
            const ser = lastCogPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane COG', e);
            }
            const addCogLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
            };
            addCogLine('cog', ser.cog, st.color_cog, true);
            addCogLine('prev_cog', ser.prev_cog, st.color_prev_cog, false);
        }

        if (wantMassIndex) {
            const st = lastMassIndexPayload.style || {};
            const ser = lastMassIndexPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane Mass Index', e);
            }
            const addMiLine = (key, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[key] = s;
            };
            addMiLine('mass_index_mi', ser.mi, st.color_mi, true);
            addMiLine('mass_index_level_up', ser.level_up, st.color_level, false);
            addMiLine('mass_index_level_down', ser.level_down, st.color_level, false);
        }

        // Priority-1 oscillator panes.
        if (wantVolatilityAverage) {
            const st = lastVolatilityAveragePayload.style || {};
            const ser = lastVolatilityAveragePayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Vol Average', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[okey] = s;
            };
            addOscLine('va_vol', ser.volatility, st.color_volatility, true);
            addOscLine('va_sma', ser.sma, st.color_sma, false);
        }
        if (wantVolatilityAverageChannel) {
            const st = lastVolatilityAverageChannelPayload.style || {};
            const ser = lastVolatilityAverageChannelPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Vol Avg Channel', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[okey] = s;
            };
            addOscLine('vac_vol', ser.volatility, st.color_volatility, true);
            addOscLine('vac_sma_fast', ser.sma_fast, st.color_sma_fast, false);
            addOscLine('vac_sma_slow', ser.sma_slow, st.color_sma_slow, false);
            addOscLine('vac_up', ser.up, st.color_up, false);
            addOscLine('vac_down', ser.down, st.color_down, false);
        }
        if (wantPriceOscillator) {
            const st = lastPriceOscillatorPayload.style || {};
            const ser = lastPriceOscillatorPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Price Oscillator', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[okey] = s;
            };
            addOscLine('po', ser.po, st.color, true);
        }
        if (wantAdaptiveLookBack) {
            const st = lastAdaptiveLookBackPayload.style || {};
            const ser = lastAdaptiveLookBackPayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Adaptive Look Back', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[okey] = s;
            };
            addOscLine('alb', ser.alb, st.color, true);
        }
        // VolatilityCandles — относительная амплитуда бара (%) в отдельной pane.
        if (wantVolatilityCandles) {
            const st = lastVolatilityCandlesPayload.style || {};
            const ser = lastVolatilityCandlesPayload.series || {};
            const lineW = st.line_width || 1;
            const vcData = ser.vc || [];
            // length=50 → на H1 нужно ≥51 часовых баров; при короткой истории не рисуем пустую pane.
            if (Array.isArray(vcData) && vcData.length) {
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Volatility Candles', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#00ffff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 3, minMove: 0.001 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[okey] = s;
            };
            addOscLine('vc', vcData, st.color, true);
            }
        }

        // VolatilityAverageTwice — вола + fast/slow SMA в отдельной pane.
        if (wantVolatilityAverageTwice) {
            const st = lastVolatilityAverageTwicePayload.style || {};
            const ser = lastVolatilityAverageTwicePayload.series || {};
            const lineW = st.line_width || 1;
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[indicators] addPane Vol Avg Twice', e);
            }
            const addOscLine = (okey, data, color, showLast) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#1e90ff',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[okey] = s;
            };
            addOscLine('vat_vol', ser.volatility, st.color_volatility, true);
            addOscLine('vat_fast', ser.sma_fast, st.color_sma_fast, false);
            addOscLine('vat_slow', ser.sma_slow, st.color_sma_slow, false);
        }
        // VolatilityStagesAW — две pane: стадии (1..4) отдельно от канала/SMA волы.
        // Иначе ordinal stages≈2 и SMA≈0.7 на одной шкале: канал «прилипает» к низу и кажется, что индикатор не рисуется.
        if (wantVolatilityStagesAw) {
            const st = lastVolatilityStagesAwPayload.style || {};
            const ser = lastVolatilityStagesAwPayload.series || {};
            const lineW = st.line_width || 1;

            const addOscLineOnPane = (okey, data, color, showLast, paneIndex, precision) => {
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: color || '#008000',
                    lineWidth: lineW,
                    priceLineVisible: false,
                    lastValueVisible: !!showLast,
                    crosshairMarkerVisible: false,
                    priceFormat: { type: 'price', precision: precision, minMove: Math.pow(10, -precision) },
                }, paneIndex);
                s.setData(mapClippedLinePoints(data));
                classicLineSeries[okey] = s;
            };

            // Pane 1: только стадии режима (целые уровни).
            if (Array.isArray(ser.stages) && ser.stages.length) {
                let stagesPaneIndex = 1;
                try {
                    const pane = chart.addPane(false);
                    if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.22);
                    stagesPaneIndex = Math.max(1, chart.panes().length - 1);
                    classicOscPaneCount += 1;
                } catch (e) {
                    console.error('[indicators] addPane Vol Stages AW (stages)', e);
                }
                addOscLineOnPane('vsa_stages', ser.stages, st.color_stages, true, stagesPaneIndex, 0);
            }

            // Pane 2: volatility + SMA + границы канала (одна масштабная «семья»).
            const channelSeries = [
                ['vsa_vol', ser.volatility, st.color_volatility || '#1e90ff', true],
                ['vsa_fast', ser.sma_fast, st.color_sma_fast || '#8b0000', false],
                ['vsa_slow', ser.sma_slow, st.color_sma_slow || '#f5f5f5', true],
                ['vsa_up', ser.up, st.color_up || '#f5f5f5', false],
                ['vsa_down', ser.down, st.color_down || '#f5f5f5', false],
            ].filter((row) => Array.isArray(row[1]) && row[1].length);

            if (channelSeries.length) {
                let channelPaneIndex = 1;
                try {
                    const pane = chart.addPane(false);
                    if (pane && typeof pane.setStretchFactor === 'function') pane.setStretchFactor(0.28);
                    channelPaneIndex = Math.max(1, chart.panes().length - 1);
                    classicOscPaneCount += 1;
                } catch (e) {
                    console.error('[indicators] addPane Vol Stages AW (channel)', e);
                }
                channelSeries.forEach(([okey, data, color, showLast]) => {
                    addOscLineOnPane(okey, data, color, showLast, channelPaneIndex, 2);
                });
            }
        }

        if (wantAtr) {
            const st = lastAtrPayload.style || {};
            let paneIndex = 1;
            try {
                const pane = chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, chart.panes().length - 1);
                classicOscPaneCount += 1;
            } catch (e) {
                console.error('[dashboard indicators] addPane ATR', e);
            }
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#1e90ff',
                lineWidth: st.line_width || 1,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: false,
            }, paneIndex);
            const data = ((lastAtrPayload.series || {}).atr) || [];
            s.setData(mapClippedLinePoints(data));
            classicLineSeries['atr'] = s;
        }

        // Подписи названий в стиле PO: title из API («RSI 14») + иконки через layerId.
        const overlays = [];
        const pushOverlay = (on, payload, fallbackName, layerId) => {
            const lb = classicIndicatorLabel(on, payload, fallbackName, layerId);
            if (lb) overlays.push(lb);
        };
        pushOverlay(wantBb, lastBollingerPayload, 'Bollinger', 'layer-bollinger');
        pushOverlay(wantSma, lastSmaPayload, 'SMA', 'layer-sma');
        pushOverlay(wantEma, lastEmaPayload, 'EMA', 'layer-ema');
        pushOverlay(wantVwma, lastVwmaPayload, 'VWMA', 'layer-vwma');
        pushOverlay(wantKeltner, lastKeltnerPayload, 'Keltner', 'layer-keltner');
        pushOverlay(wantEnvelops, lastEnvelopsPayload, 'Envelops', 'layer-envelops');
        pushOverlay(wantParabolicSar, lastParabolicSarPayload, 'Parabolic SAR', 'layer-parabolic-sar');
        pushOverlay(wantSuperTrend, lastSuperTrendPayload, 'SuperTrend', 'layer-supertrend');
        pushOverlay(wantAlligator, lastAlligatorPayload, 'Alligator', 'layer-alligator');
        pushOverlay(wantIchimoku, lastIchimokuPayload, 'Ichimoku', 'layer-ichimoku');
        pushOverlay(wantPriceChannel, lastPriceChannelPayload, 'Price Channel', 'layer-price-channel');
        pushOverlay(wantNrtr, lastNrtrPayload, 'NRTR', 'layer-nrtr');
        pushOverlay(wantHma, lastHmaPayload, 'HMA', 'layer-hma');
        pushOverlay(wantSmaChannel, lastSmaChannelPayload, 'SMA Channel', 'layer-sma-channel');
        pushOverlay(wantLinReg, lastLinRegPayload, 'Linear Regression', 'layer-linreg');
        pushOverlay(wantLinRegChannel, lastLinRegChannelPayload, 'Linear Reg. Channel', 'layer-linreg-channel');
        pushOverlay(wantKalman, lastKalmanPayload, 'Kalman Filter', 'layer-kalman');
        pushOverlay(wantFractal, lastFractalPayload, 'Fractal', 'layer-fractal');
        pushOverlay(wantStrat, lastStratPayload, 'Strat Assistant', 'layer-strat-assistant');
        pushOverlay(wantDonchian, lastDonchianPayload, 'Donchian', 'layer-donchian');
        pushOverlay(wantPivotFloor, lastPivotFloorPayload, 'Pivot Floor', 'layer-pivot-floor');
        pushOverlay(wantNrtrWatr, lastNrtrWatrPayload, 'NRTR WATR', 'layer-nrtr-watr');
        pushOverlay(wantPriceChannelAdaptive, lastPriceChannelAdaptivePayload, 'PC Adaptive', 'layer-price-channel-adaptive');
        pushOverlay(wantPriceChannelOffset, lastPriceChannelOffsetPayload, 'PC Offset', 'layer-price-channel-offset');
        pushOverlay(wantParabolicBollinger, lastParabolicBollingerPayload, 'Parabolic BB', 'layer-parabolic-bollinger');
        pushOverlay(wantParabolicPriceChannel, lastParabolicPriceChannelPayload, 'Parabolic PC', 'layer-parabolic-price-channel');
        pushOverlay(wantAtrChannel, lastAtrChannelPayload, 'ATR Channel', 'layer-atr-channel');
        pushOverlay(wantOffsetSma, lastOffsetSmaPayload, 'Offset SMA', 'layer-offset-sma');
        pushOverlay(wantOffsetEma, lastOffsetEmaPayload, 'Offset EMA', 'layer-offset-ema');
        pushOverlay(wantOffsetSsma, lastOffsetSsmaPayload, 'Offset SSMA', 'layer-offset-ssma');
        pushOverlay(wantDynamicTrendDetector, lastDynamicTrendDetectorPayload, 'DTD', 'layer-dynamic-trend-detector');
        pushOverlay(wantLinRegChannelFast, lastLinRegChannelFastPayload, 'LR Channel Fast', 'layer-linreg-channel-fast');
        pushOverlay(wantZigZag, lastZigZagPayload, 'ZigZag', 'layer-zigzag');

        const oscillators = [];
        const pushOsc = (on, payload, fallbackName, layerId) => {
            const lb = classicIndicatorLabel(on, payload, fallbackName, layerId);
            if (lb) oscillators.push(lb);
        };
        pushOsc(wantRsi, lastRsiPayload, 'RSI', 'layer-rsi');
        pushOsc(wantMacd, lastMacdPayload, 'MACD', 'layer-macd');
        pushOsc(wantStochastic, lastStochasticPayload, 'Stochastic', 'layer-stochastic');
        pushOsc(wantCci, lastCciPayload, 'CCI', 'layer-cci');
        pushOsc(wantAdx, lastAdxPayload, 'ADX', 'layer-adx');
        pushOsc(wantWilliams, lastWilliamsPayload, 'Williams %R', 'layer-williams');
        pushOsc(wantAo, lastAoPayload, 'AO', 'layer-ao');
        pushOsc(wantAroon, lastAroonPayload, 'Aroon', 'layer-aroon');
        pushOsc(wantMomentum, lastMomentumPayload, 'Momentum', 'layer-momentum');
        pushOsc(wantRoc, lastRocPayload, 'ROC', 'layer-roc');
        pushOsc(wantOsma, lastOsmaPayload, 'OsMA', 'layer-osma');
        pushOsc(wantTrix, lastTrixPayload, 'TRIX', 'layer-trix');
        pushOsc(wantCmo, lastCmoPayload, 'CMO', 'layer-cmo');
        pushOsc(wantDemarker, lastDemarkerPayload, 'DeMarker', 'layer-demarker');
        pushOsc(wantRvi, lastRviPayload, 'RVI', 'layer-rvi');
        pushOsc(wantUltimate, lastUltimatePayload, 'Ultimate Oscillator', 'layer-ultimate');
        pushOsc(wantSmi, lastSmiPayload, 'SMI', 'layer-smi');
        pushOsc(wantStdDev, lastStdDevPayload, 'StdDev', 'layer-stddev');
        pushOsc(wantIvashov, lastIvashovPayload, 'Ivashov Range', 'layer-ivashov');
        pushOsc(wantStochRsi, lastStochRsiPayload, 'Stoch RSI', 'layer-stoch-rsi');
        pushOsc(wantAc, lastAcPayload, 'AC', 'layer-ac');
        pushOsc(wantBullsPower, lastBullsPowerPayload, 'Bulls Power', 'layer-bulls-power');
        pushOsc(wantBearsPower, lastBearsPowerPayload, 'Bears Power', 'layer-bears-power');
        pushOsc(wantDpo, lastDpoPayload, 'DPO', 'layer-dpo');
        pushOsc(wantQstick, lastQstickPayload, 'QStick', 'layer-qstick');
        pushOsc(wantVhFilter, lastVhFilterPayload, 'VHFilter', 'layer-vh-filter');
        pushOsc(wantEfficiencyRatio, lastEfficiencyRatioPayload, 'Efficiency Ratio', 'layer-efficiency-ratio');
        pushOsc(wantRavi, lastRaviPayload, 'RAVI', 'layer-ravi');
        pushOsc(wantCog, lastCogPayload, 'COG', 'layer-cog');
        pushOsc(wantMassIndex, lastMassIndexPayload, 'Mass Index', 'layer-mass-index');
        pushOsc(wantVolatilityAverage, lastVolatilityAveragePayload, 'Vol Average', 'layer-volatility-average');
        pushOsc(wantVolatilityAverageChannel, lastVolatilityAverageChannelPayload, 'Vol Avg Channel', 'layer-volatility-average-channel');
        pushOsc(wantPriceOscillator, lastPriceOscillatorPayload, 'Price Oscillator', 'layer-price-oscillator');
        pushOsc(wantAdaptiveLookBack, lastAdaptiveLookBackPayload, 'Adaptive Look Back', 'layer-adaptive-look-back');
        pushOsc(wantVolatilityCandles, lastVolatilityCandlesPayload, 'Volatility Candles', 'layer-volatility-candles');
        pushOsc(wantVolatilityAverageTwice, lastVolatilityAverageTwicePayload, 'Vol Avg Twice', 'layer-volatility-average-twice');
        if (wantVolatilityStagesAw && lastVolatilityStagesAwPayload) {
            const ser = lastVolatilityStagesAwPayload.series || {};
            const baseTitle = (lastVolatilityStagesAwPayload.title)
                ? String(lastVolatilityStagesAwPayload.title)
                : 'Vol Stages AW';
            const meta = (window.PLATFORM_INDICATOR_LAYER_META && window.PLATFORM_INDICATOR_LAYER_META['layer-volatility-stages-aw']) || {};
            if (Array.isArray(ser.stages) && ser.stages.length) {
                oscillators.push({
                    name: baseTitle,
                    color: '#f4f4f5',
                    layerId: 'layer-volatility-stages-aw',
                    settingsUrl: meta.settingsUrl || null,
                });
            }
            const hasChannel = ['volatility', 'sma_fast', 'sma_slow', 'up', 'down']
                .some((k) => Array.isArray(ser[k]) && ser[k].length);
            if (hasChannel) {
                oscillators.push({
                    name: `${baseTitle} (ch)`,
                    color: '#f4f4f5',
                    layerId: 'layer-volatility-stages-aw',
                    settingsUrl: meta.settingsUrl || null,
                });
            }
        }
        pushOsc(wantAtr, lastAtrPayload, 'ATR', 'layer-atr');

        lastClassicNameState = { overlays, oscillators };
        requestAnimationFrame(() => renderAllIndicatorNameLabels());
        // addPane/removePane сбрасывает canvas свечей LWC v5 — setData нужен.
        // После setData продолжаем chase (toward), а не snap: иначе live-свеча снова «прыгает».
        resyncCandlestickSeriesFromAllChartData({ preserveSmooth: true });
        // После rebuild panes применить режим шкалы (addPane может сбросить autoScale).
        applyPriceScalePanPref();
        renderChartMarkers();
    }

    /** Тянем серверные classic-слои; клиентский subset уже в recomputeClientIndicators. */
    function fetchClassicIndicators() {
        recomputeClientIndicators({ skipRender: true });
        if (!currentInstrument || !anyServerClassicLayerOn()) {
            // Чистим только серверные payload; клиентские уже выставлены recompute.
            lastCciPayload = null;
            lastKeltnerPayload = null;
            lastEnvelopsPayload = null;
            lastParabolicSarPayload = null;
            lastSuperTrendPayload = null;
            lastAdxPayload = null;
            lastWilliamsPayload = null;
            lastAoPayload = null;
            lastAlligatorPayload = null;
            lastIchimokuPayload = null;
            lastPriceChannelPayload = null;
            lastNrtrPayload = null;
            lastHmaPayload = null;
            lastAroonPayload = null;
            lastMomentumPayload = null;
            lastRocPayload = null;
            lastOsmaPayload = null;
            lastTrixPayload = null;
            lastCmoPayload = null;
            lastDemarkerPayload = null;
            lastRviPayload = null;
            lastUltimatePayload = null;
            lastSmiPayload = null;
            lastStdDevPayload = null;
            lastSmaChannelPayload = null;
            lastIvashovPayload = null;
            lastLinRegPayload = null;
            lastLinRegChannelPayload = null;
            lastKalmanPayload = null;
            lastFractalPayload = null;
            // Strat клиентский — уже выставлен recomputeClientIndicators.
            lastDonchianPayload = null;
            lastStochRsiPayload = null;
            lastAcPayload = null;
            lastBullsPowerPayload = null;
            lastBearsPowerPayload = null;
            lastDpoPayload = null;
            lastQstickPayload = null;
            lastVhFilterPayload = null;
            lastEfficiencyRatioPayload = null;
            lastRaviPayload = null;
            lastCogPayload = null;
            lastPivotFloorPayload = null;
            lastMassIndexPayload = null;
            lastNrtrWatrPayload = null;
            lastPriceChannelAdaptivePayload = null;
            lastPriceChannelOffsetPayload = null;
            lastParabolicBollingerPayload = null;
            lastParabolicPriceChannelPayload = null;
            lastAtrChannelPayload = null;
            lastOffsetSmaPayload = null;
            lastOffsetEmaPayload = null;
            lastOffsetSsmaPayload = null;
            lastVolatilityAveragePayload = null;
            lastVolatilityAverageChannelPayload = null;
            lastPriceOscillatorPayload = null;
            lastDynamicTrendDetectorPayload = null;
            lastAdaptiveLookBackPayload = null;
            lastLinRegChannelFastPayload = null;
            lastVolatilityCandlesPayload = null;
            lastZigZagPayload = null;
            lastVolatilityAverageTwicePayload = null;
            lastVolatilityStagesAwPayload = null;
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('classic');
            applyPaBarColors({ fullResync: !!(layerPa && layerPa.checked) });
            redrawPriceActionOverlay();
            redrawLiquiditySwingsOverlay();
            redrawSmcFlowOverlay();
            applyClassicOsEngineSeries();
            renderChartMarkers();
            return;
        }
        const wantCci = layerCci && layerCci.checked;
        const wantKeltner = layerKeltner && layerKeltner.checked;
        const wantEnvelops = layerEnvelops && layerEnvelops.checked;
        const wantParabolicSar = layerParabolicSar && layerParabolicSar.checked;
        const wantSuperTrend = layerSuperTrend && layerSuperTrend.checked;
        const wantAdx = layerAdx && layerAdx.checked;
        const wantWilliams = layerWilliams && layerWilliams.checked;
        const wantAo = layerAo && layerAo.checked;
        const wantAlligator = layerAlligator && layerAlligator.checked;
        const wantIchimoku = layerIchimoku && layerIchimoku.checked;
        const wantPriceChannel = layerPriceChannel && layerPriceChannel.checked;
        const wantNrtr = layerNrtr && layerNrtr.checked;
        const wantHma = layerHma && layerHma.checked;
        const wantAroon = layerAroon && layerAroon.checked;
        const wantMomentum = layerMomentum && layerMomentum.checked;
        const wantRoc = layerRoc && layerRoc.checked;
        const wantOsma = layerOsma && layerOsma.checked;
        const wantTrix = layerTrix && layerTrix.checked;
        const wantCmo = layerCmo && layerCmo.checked;
        const wantDemarker = layerDemarker && layerDemarker.checked;
        const wantRvi = layerRvi && layerRvi.checked;
        const wantUltimate = layerUltimate && layerUltimate.checked;
        const wantSmi = layerSmi && layerSmi.checked;
        const wantStdDev = layerStdDev && layerStdDev.checked;
        const wantSmaChannel = layerSmaChannel && layerSmaChannel.checked;
        const wantIvashov = layerIvashov && layerIvashov.checked;
        const wantLinReg = layerLinReg && layerLinReg.checked;
        const wantLinRegChannel = layerLinRegChannel && layerLinRegChannel.checked;
        const wantKalman = layerKalman && layerKalman.checked;
        const wantFractal = layerFractal && layerFractal.checked;
        const wantStrat = layerStratAssistant && layerStratAssistant.checked;
        const wantDonchian = layerDonchian && layerDonchian.checked;
        const wantStochRsi = layerStochRsi && layerStochRsi.checked;
        const wantAc = layerAc && layerAc.checked;
        const wantBullsPower = layerBullsPower && layerBullsPower.checked;
        const wantBearsPower = layerBearsPower && layerBearsPower.checked;
        const wantDpo = layerDpo && layerDpo.checked;
        const wantQstick = layerQstick && layerQstick.checked;
        const wantVhFilter = layerVhFilter && layerVhFilter.checked;
        const wantEfficiencyRatio = layerEfficiencyRatio && layerEfficiencyRatio.checked;
        const wantRavi = layerRavi && layerRavi.checked;
        const wantCog = layerCog && layerCog.checked;
        const wantPivotFloor = layerPivotFloor && layerPivotFloor.checked;
        const wantMassIndex = layerMassIndex && layerMassIndex.checked;
        const wantNrtrWatr = layerNrtrWatr && layerNrtrWatr.checked;
        const wantPriceChannelAdaptive = layerPriceChannelAdaptive && layerPriceChannelAdaptive.checked;
        const wantPriceChannelOffset = layerPriceChannelOffset && layerPriceChannelOffset.checked;
        const wantParabolicBollinger = layerParabolicBollinger && layerParabolicBollinger.checked;
        const wantParabolicPriceChannel = layerParabolicPriceChannel && layerParabolicPriceChannel.checked;
        const wantAtrChannel = layerAtrChannel && layerAtrChannel.checked;
        const wantOffsetSma = layerOffsetSma && layerOffsetSma.checked;
        const wantOffsetEma = layerOffsetEma && layerOffsetEma.checked;
        const wantOffsetSsma = layerOffsetSsma && layerOffsetSsma.checked;
        const wantVolatilityAverage = layerVolatilityAverage && layerVolatilityAverage.checked;
        const wantVolatilityAverageChannel = layerVolatilityAverageChannel && layerVolatilityAverageChannel.checked;
        const wantPriceOscillator = layerPriceOscillator && layerPriceOscillator.checked;
        const wantDynamicTrendDetector = layerDynamicTrendDetector && layerDynamicTrendDetector.checked;
        const wantAdaptiveLookBack = layerAdaptiveLookBack && layerAdaptiveLookBack.checked;
        const wantLinRegChannelFast = layerLinRegChannelFast && layerLinRegChannelFast.checked;
        const wantVolatilityCandles = layerVolatilityCandles && layerVolatilityCandles.checked;
        const wantZigZag = layerZigZag && layerZigZag.checked;
        const wantVolatilityAverageTwice = layerVolatilityAverageTwice && layerVolatilityAverageTwice.checked;
        const wantVolatilityStagesAw = layerVolatilityStagesAw && layerVolatilityStagesAw.checked;
        const layers = [];
        if (wantCci) layers.push('cci');
        if (wantKeltner) layers.push('keltner');
        if (wantEnvelops) layers.push('envelops');
        if (wantParabolicSar) layers.push('parabolic_sar');
        if (wantSuperTrend) layers.push('supertrend');
        if (wantAdx) layers.push('adx');
        if (wantWilliams) layers.push('williams');
        if (wantAo) layers.push('ao');
        if (wantAlligator) layers.push('alligator');
        if (wantIchimoku) layers.push('ichimoku');
        if (wantPriceChannel) layers.push('price_channel');
        if (wantNrtr) layers.push('nrtr');
        if (wantHma) layers.push('hma');
        if (wantAroon) layers.push('aroon');
        if (wantMomentum) layers.push('momentum');
        if (wantRoc) layers.push('roc');
        if (wantOsma) layers.push('osma');
        if (wantTrix) layers.push('trix');
        if (wantCmo) layers.push('cmo');
        if (wantDemarker) layers.push('demarker');
        if (wantRvi) layers.push('rvi');
        if (wantUltimate) layers.push('ultimate');
        if (wantSmi) layers.push('smi');
        if (wantStdDev) layers.push('stddev');
        if (wantSmaChannel) layers.push('sma_channel');
        if (wantIvashov) layers.push('ivashov');
        if (wantLinReg) layers.push('linreg');
        if (wantLinRegChannel) layers.push('linreg_channel');
        if (wantKalman) layers.push('kalman');
        if (wantFractal) layers.push('fractal');
        // strat_assistant — PlatformStratAssistant на клиенте.
        if (wantDonchian) layers.push('donchian');
        if (wantStochRsi) layers.push('stoch_rsi');
        if (wantAc) layers.push('ac');
        if (wantBullsPower) layers.push('bulls_power');
        if (wantBearsPower) layers.push('bears_power');
        if (wantDpo) layers.push('dpo');
        if (wantQstick) layers.push('qstick');
        if (wantVhFilter) layers.push('vh_filter');
        if (wantEfficiencyRatio) layers.push('efficiency_ratio');
        if (wantRavi) layers.push('ravi');
        if (wantCog) layers.push('cog');
        if (wantPivotFloor) layers.push('pivot_floor');
        if (wantMassIndex) layers.push('mass_index');
        if (wantNrtrWatr) layers.push('nrtr_watr');
        if (wantPriceChannelAdaptive) layers.push('price_channel_adaptive');
        if (wantPriceChannelOffset) layers.push('price_channel_offset');
        if (wantParabolicBollinger) layers.push('parabolic_bollinger');
        if (wantParabolicPriceChannel) layers.push('parabolic_price_channel');
        if (wantAtrChannel) layers.push('atr_channel');
        if (wantOffsetSma) layers.push('offset_sma');
        if (wantOffsetEma) layers.push('offset_ema');
        if (wantOffsetSsma) layers.push('offset_ssma');
        if (wantVolatilityAverage) layers.push('volatility_average');
        if (wantVolatilityAverageChannel) layers.push('volatility_average_channel');
        if (wantPriceOscillator) layers.push('price_oscillator');
        if (wantDynamicTrendDetector) layers.push('dynamic_trend_detector');
        if (wantAdaptiveLookBack) layers.push('adaptive_look_back');
        if (wantLinRegChannelFast) layers.push('linreg_channel_fast');
        if (wantVolatilityCandles) layers.push('volatility_candles');
        if (wantZigZag) layers.push('zigzag');
        if (wantVolatilityAverageTwice) layers.push('volatility_average_twice');
        if (wantVolatilityStagesAw) layers.push('volatility_stages_aw');

        if (!window.PlatformChartFetch) return;

        // Ключ: instrument|tf|layers|since|limit — после loadMore (новый since) пересчитываем.
        const params = new URLSearchParams({
            instrument: currentInstrument,
            timeframe: String(currentTimeframeMinutes),
        });
        layers.forEach((l) => params.append('layers[]', l));
        const hist = appendIndicatorHistoryParams(params);
        const requestKey = [
            currentInstrument,
            String(currentTimeframeMinutes),
            layers.slice().sort().join(','),
            String(hist.since),
            String(hist.limit),
        ].join('|');

        PlatformChartFetch.requestJson('classic', {
            key: requestKey,
            url: CLASSIC_INDICATORS_URL + '?' + params.toString(),
            apply: (d) => {
                try {
                    lastCciPayload = wantCci && d && d.cci ? d.cci : null;
                    lastKeltnerPayload = wantKeltner && d && d.keltner ? d.keltner : null;
                    lastEnvelopsPayload = wantEnvelops && d && d.envelops ? d.envelops : null;
                    lastParabolicSarPayload = wantParabolicSar && d && d.parabolic_sar ? d.parabolic_sar : null;
                    lastSuperTrendPayload = wantSuperTrend && d && d.supertrend ? d.supertrend : null;
                    lastAdxPayload = wantAdx && d && d.adx ? d.adx : null;
                    lastWilliamsPayload = wantWilliams && d && d.williams ? d.williams : null;
                    lastAoPayload = wantAo && d && d.ao ? d.ao : null;
                    lastAlligatorPayload = wantAlligator && d && d.alligator ? d.alligator : null;
                    lastIchimokuPayload = wantIchimoku && d && d.ichimoku ? d.ichimoku : null;
                    lastPriceChannelPayload = wantPriceChannel && d && d.price_channel ? d.price_channel : null;
                    lastNrtrPayload = wantNrtr && d && d.nrtr ? d.nrtr : null;
                    lastHmaPayload = wantHma && d && d.hma ? d.hma : null;
                    lastAroonPayload = wantAroon && d && d.aroon ? d.aroon : null;
                    lastMomentumPayload = wantMomentum && d && d.momentum ? d.momentum : null;
                    lastRocPayload = wantRoc && d && d.roc ? d.roc : null;
                    lastOsmaPayload = wantOsma && d && d.osma ? d.osma : null;
                    lastTrixPayload = wantTrix && d && d.trix ? d.trix : null;
                    lastCmoPayload = wantCmo && d && d.cmo ? d.cmo : null;
                    lastDemarkerPayload = wantDemarker && d && d.demarker ? d.demarker : null;
                    lastRviPayload = wantRvi && d && d.rvi ? d.rvi : null;
                    lastUltimatePayload = wantUltimate && d && d.ultimate ? d.ultimate : null;
                    lastSmiPayload = wantSmi && d && d.smi ? d.smi : null;
                    lastStdDevPayload = wantStdDev && d && d.stddev ? d.stddev : null;
                    lastSmaChannelPayload = wantSmaChannel && d && d.sma_channel ? d.sma_channel : null;
                    lastIvashovPayload = wantIvashov && d && d.ivashov ? d.ivashov : null;
                    lastLinRegPayload = wantLinReg && d && d.linreg ? d.linreg : null;
                    lastLinRegChannelPayload = wantLinRegChannel && d && d.linreg_channel ? d.linreg_channel : null;
                    lastKalmanPayload = wantKalman && d && d.kalman ? d.kalman : null;
                    lastFractalPayload = wantFractal && d && d.fractal ? d.fractal : null;
                    lastDonchianPayload = wantDonchian && d && d.donchian ? d.donchian : null;
                    lastStochRsiPayload = wantStochRsi && d && d.stoch_rsi ? d.stoch_rsi : null;
                    lastAcPayload = wantAc && d && d.ac ? d.ac : null;
                    lastBullsPowerPayload = wantBullsPower && d && d.bulls_power ? d.bulls_power : null;
                    lastBearsPowerPayload = wantBearsPower && d && d.bears_power ? d.bears_power : null;
                    lastDpoPayload = wantDpo && d && d.dpo ? d.dpo : null;
                    lastQstickPayload = wantQstick && d && d.qstick ? d.qstick : null;
                    lastVhFilterPayload = wantVhFilter && d && d.vh_filter ? d.vh_filter : null;
                    lastEfficiencyRatioPayload = wantEfficiencyRatio && d && d.efficiency_ratio ? d.efficiency_ratio : null;
                    lastRaviPayload = wantRavi && d && d.ravi ? d.ravi : null;
                    lastCogPayload = wantCog && d && d.cog ? d.cog : null;
                    lastPivotFloorPayload = wantPivotFloor && d && d.pivot_floor ? d.pivot_floor : null;
                    lastMassIndexPayload = wantMassIndex && d && d.mass_index ? d.mass_index : null;
                    lastNrtrWatrPayload = wantNrtrWatr && d && d.nrtr_watr ? d.nrtr_watr : null;
                    lastPriceChannelAdaptivePayload = wantPriceChannelAdaptive && d && d.price_channel_adaptive ? d.price_channel_adaptive : null;
                    lastPriceChannelOffsetPayload = wantPriceChannelOffset && d && d.price_channel_offset ? d.price_channel_offset : null;
                    lastParabolicBollingerPayload = wantParabolicBollinger && d && d.parabolic_bollinger ? d.parabolic_bollinger : null;
                    lastParabolicPriceChannelPayload = wantParabolicPriceChannel && d && d.parabolic_price_channel ? d.parabolic_price_channel : null;
                    lastAtrChannelPayload = wantAtrChannel && d && d.atr_channel ? d.atr_channel : null;
                    lastOffsetSmaPayload = wantOffsetSma && d && d.offset_sma ? d.offset_sma : null;
                    lastOffsetEmaPayload = wantOffsetEma && d && d.offset_ema ? d.offset_ema : null;
                    lastOffsetSsmaPayload = wantOffsetSsma && d && d.offset_ssma ? d.offset_ssma : null;
                    lastVolatilityAveragePayload = wantVolatilityAverage && d && d.volatility_average ? d.volatility_average : null;
                    lastVolatilityAverageChannelPayload = wantVolatilityAverageChannel && d && d.volatility_average_channel ? d.volatility_average_channel : null;
                    lastPriceOscillatorPayload = wantPriceOscillator && d && d.price_oscillator ? d.price_oscillator : null;
                    lastDynamicTrendDetectorPayload = wantDynamicTrendDetector && d && d.dynamic_trend_detector ? d.dynamic_trend_detector : null;
                    lastAdaptiveLookBackPayload = wantAdaptiveLookBack && d && d.adaptive_look_back ? d.adaptive_look_back : null;
                    lastLinRegChannelFastPayload = wantLinRegChannelFast && d && d.linreg_channel_fast ? d.linreg_channel_fast : null;
                    lastVolatilityCandlesPayload = wantVolatilityCandles && d && d.volatility_candles ? d.volatility_candles : null;
                    lastZigZagPayload = wantZigZag && d && d.zigzag ? d.zigzag : null;
                    lastVolatilityAverageTwicePayload = wantVolatilityAverageTwice && d && d.volatility_average_twice ? d.volatility_average_twice : null;
                    lastVolatilityStagesAwPayload = wantVolatilityStagesAw && d && d.volatility_stages_aw ? d.volatility_stages_aw : null;
                    renderChartMarkers();
                    const layersSig = layers.slice().sort().join(',');
                    if (layersSig === lastClassicLayersSig && patchEnabledClassicSeriesData(layers)) {
                        // Pane не трогаем — только setData линий; viewport не трогаем.
                    } else {
                        lastClassicLayersSig = layersSig;
                        applyClassicOsEngineSeries();
                    }
                } catch (e) {
                    console.error('[dashboard classic indicators] apply', e);
                    lastBollingerPayload = null;
                    lastRsiPayload = null;
                    lastSmaPayload = null;
                    lastEmaPayload = null;
                    lastVwmaPayload = null;
                    lastAtrPayload = null;
                    lastMacdPayload = null;
                    lastStochasticPayload = null;
                    lastCciPayload = null;
                    lastKeltnerPayload = null;
                    lastEnvelopsPayload = null;
                    lastParabolicSarPayload = null;
                    lastSuperTrendPayload = null;
                    lastAdxPayload = null;
                    lastWilliamsPayload = null;
                    lastAoPayload = null;
                    lastAlligatorPayload = null;
                    lastIchimokuPayload = null;
                    lastPriceChannelPayload = null;
                    lastNrtrPayload = null;
                    lastHmaPayload = null;
                    lastAroonPayload = null;
                    lastMomentumPayload = null;
                    lastRocPayload = null;
                    lastOsmaPayload = null;
                    lastTrixPayload = null;
                    lastCmoPayload = null;
                    lastDemarkerPayload = null;
                    lastRviPayload = null;
                    lastUltimatePayload = null;
                    lastSmiPayload = null;
                    lastStdDevPayload = null;
                    lastSmaChannelPayload = null;
                    lastIvashovPayload = null;
                    lastLinRegPayload = null;
                    lastLinRegChannelPayload = null;
                    lastKalmanPayload = null;
                    lastFractalPayload = null;
                    lastStratPayload = null;
                    lastDonchianPayload = null;
                    lastStochRsiPayload = null;
                    lastAcPayload = null;
                    lastBullsPowerPayload = null;
                    lastBearsPowerPayload = null;
                    lastDpoPayload = null;
                    lastQstickPayload = null;
                    lastVhFilterPayload = null;
                    lastEfficiencyRatioPayload = null;
                    lastRaviPayload = null;
                    lastCogPayload = null;
                    lastPivotFloorPayload = null;
                    lastMassIndexPayload = null;
                    lastNrtrWatrPayload = null;
                    lastPriceChannelAdaptivePayload = null;
                    lastPriceChannelOffsetPayload = null;
                    lastParabolicBollingerPayload = null;
                    lastParabolicPriceChannelPayload = null;
                    lastAtrChannelPayload = null;
                    lastOffsetSmaPayload = null;
                    lastOffsetEmaPayload = null;
                    lastOffsetSsmaPayload = null;
                    lastVolatilityAveragePayload = null;
                    lastVolatilityAverageChannelPayload = null;
                    lastPriceOscillatorPayload = null;
                    lastDynamicTrendDetectorPayload = null;
                    lastAdaptiveLookBackPayload = null;
                    lastLinRegChannelFastPayload = null;
                    lastVolatilityCandlesPayload = null;
                    lastZigZagPayload = null;
                    lastVolatilityAverageTwicePayload = null;
                    lastVolatilityStagesAwPayload = null;
                    try { applyClassicOsEngineSeries(); } catch (_) {}
                }
            },
        });
    }

    /**
     * Тянем Liquidity Swings с отдельного эндпоинта (как DGT), чтобы не смешивать тяжёлые ответы.
     * Отрисовка — в redrawLiquiditySwingsOverlay(), вызывается перед PA-оверлеем.
     */
    /** Liquidity Swings — клиентский пересчёт. */
    function fetchLiquiditySwingsIndicator() {
        if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('liquidity');
        recomputeClientIndicators();
    }

    /**
     * SMC Flow — отдельный эндпоинт (как Liquidity Swings), чтобы не раздувать ответ DGT.
     * Отрисовка: canvas PlatformSmcFlowOverlay (см. redrawSmcFlowOverlay).
     */
    /** SMC Flow — клиентский пересчёт. */
    function fetchSmcFlowIndicator() {
        if (window.PlatformChartFetch) window.PlatformChartFetch.invalidate('smc');
        recomputeClientIndicators();
    }

    /** Размер подписей объёма: как Labels Size в LuxAlgo (tiny / small / normal). */
    function lsLabelFontPx(sz) {
        const s = (sz || 'tiny').toString().toLowerCase();
        if (s === 'small') return '11px';
        if (s === 'normal') return '13px';
        return '9px';
    }

    /**
     * DOM-оверлей зон / линий / подписей объёма (аналог отображения в TradingView).
     */

    /** Liquidity Swings — Canvas2D. */
    function redrawLiquiditySwingsOverlay() {
        if (!liquiditySwingsOverlay || !chart || !candlestickSeries) return;
        const painter = window.PlatformChartCanvasOverlay;
        const enabled = !!(layerLiquidity && layerLiquidity.checked && lastLsPayload && allChartData.length);
        if (!painter || typeof painter.paintLiquidity !== 'function') {
            liquiditySwingsOverlay.replaceChildren();
            return;
        }
        try {
            const api = buildChartCanvasCoordApi({ enabled });
            if (api) painter.paintLiquidity(liquiditySwingsOverlay, lastLsPayload, api);
            else liquiditySwingsOverlay.replaceChildren();
        } catch (e) {
            console.error('[Liquidity Swings] dashboard canvas redraw', e);
        }
    }


    /** Mxwll BoS/CHoCH — горизонтальный пунктир структуры. */
    function smcIsStructureLine(ln) {
        if (!ln || typeof ln !== 'object') return false;
        const kind = String(ln.kind || '');
        if (kind === 'mxwll_main_leg') return true;
        const st = String(ln.style || 'Solid').toLowerCase();
        const p0 = Number(ln.price_start ?? ln.price);
        const p1 = Number(ln.price_end ?? ln.price);
        return st === 'dashed' && Math.abs(p0 - p1) < 1e-10;
    }

    function smcIsStructureLabel(lb) {
        if (!lb || typeof lb !== 'object') return false;
        return String(lb.kind || '') === 'mxwll_structure'
            || /^(I-)?(BoS|CHoCH)$/.test(String(lb.text || ''));
    }

    function smcAppendOverlayLine(fragment, ts, ln, viewportW) {
        const x1 = ts.timeToCoordinate(Number(ln.time_start));
        const x2 = ts.timeToCoordinate(Number(ln.time_end));
        const priceStart = Number(ln.price_start ?? ln.price);
        const priceEnd = Number(ln.price_end ?? ln.price);
        const y1 = candlestickSeries.priceToCoordinate(priceStart);
        const y2 = candlestickSeries.priceToCoordinate(priceEnd);
        if (x1 == null || x2 == null || y1 == null || y2 == null) return false;
        if ([x1, x2, y1, y2].some((v) => Number.isNaN(v))) return false;
        if (Math.max(x1, x2) < -60 || Math.min(x1, x2) > viewportW + 60) return false;
        const st = (ln.style || 'Solid').toLowerCase();
        const col = paCssColor(ln.color);
        const lw = Math.max(1, Number(ln.width) || 2);
        const isHoriz = Math.abs(priceStart - priceEnd) < 1e-10;
        if (isHoriz && (st === 'dotted' || st === 'dashed')) {
            const left = Math.min(x1, x2);
            const w = Math.max(1, Math.abs(x2 - x1));
            const div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.left = left + 'px';
            div.style.top = (y1 - lw / 2) + 'px';
            div.style.width = w + 'px';
            div.style.height = '0';
            div.style.pointerEvents = 'none';
            div.style.background = 'transparent';
            div.style.borderTop = (st === 'dotted' ? `${lw}px dotted ` : `${lw}px dashed `) + col;
            fragment.appendChild(div);
        } else {
            appendOverlaySegment(fragment, x1, y1, x2, y2, col, lw);
        }
        return true;
    }

    function smcAppendOverlayLabel(fragment, ts, lb, viewportW) {
        if (!lb || typeof lb !== 'object') return;
        const isStructureLabel = smcIsStructureLabel(lb);
        const x = ts.timeToCoordinate(Number(lb.time));
        const y = candlestickSeries.priceToCoordinate(Number(lb.price));
        if (y == null || Number.isNaN(y)) return;
        const cullPad = isStructureLabel ? 140 : 80;
        if (x == null || Number.isNaN(x) || x < -cullPad || x > viewportW + cullPad) return;
        const pos = String(lb.position || '');
        let transform = 'translate(-50%, calc(-100% - 4px))';
        if (pos === 'below') {
            transform = 'translate(-50%, 8px)';
        } else if (pos === 'center') {
            transform = 'translate(-50%, -50%)';
        } else if (pos === 'above') {
            transform = 'translate(-50%, calc(-100% - 8px))';
        } else if (pos === 'left') {
            transform = 'translate(8px, -50%)';
        }
        const span = document.createElement('span');
        span.textContent = String(lb.text || '');
        span.style.position = 'absolute';
        span.style.left = x + 'px';
        span.style.top = y + 'px';
        span.style.transform = transform;
        span.style.fontSize = isStructureLabel
            ? (lastSmcPayload.label_size === 'normal' ? '13px' : (lastSmcPayload.label_size === 'small' ? '12px' : '11px'))
            : lsLabelFontPx(lastSmcPayload.label_size);
        span.style.fontWeight = isStructureLabel ? '700' : '600';
        span.style.lineHeight = '1.1';
        span.style.color = paCssColor(lb.color || '#ffffff');
        if (isStructureLabel) {
            span.style.textShadow = '0 0 4px rgba(0,0,0,0.95), 0 1px 2px rgba(0,0,0,0.9)';
            span.style.zIndex = '3';
            span.style.letterSpacing = '0.02em';
        }
        const bg = String(lb.background || '');
        const transparentBg = bg === '' || bg === 'transparent' || bg.toLowerCase() === '#00000000';
        if (!transparentBg) {
            span.style.background = paCssColor(bg || '#22c55e');
            span.style.padding = '2px 5px';
            span.style.borderRadius = '3px';
        }
        span.style.pointerEvents = 'none';
        span.style.whiteSpace = 'nowrap';
        fragment.appendChild(span);
    }

    /**
     * SMC Flow на Canvas2D — тот же путь, что у Binodex: clear+stroke вместо recreate DOM.
     * После подгрузки истории сегментов много; canvas держит зум плавным.
     */
    function redrawSmcFlowOverlay() {
        if (!smcFlowOverlay || !chart || !candlestickSeries) return;
        const painter = window.PlatformSmcFlowOverlay;
        if (!painter || typeof painter.paint !== 'function') {
            smcFlowOverlay.replaceChildren();
            return;
        }
        try {
            const ts = chart.timeScale();
            const enabled = !!(layerSmc && layerSmc.checked && lastSmcPayload && allChartData.length);
            painter.paint(smcFlowOverlay, lastSmcPayload, {
                enabled,
                viewportW: Math.max(1, chartWrapper?.clientWidth || 0),
                viewportH: Math.max(1, chartWrapper?.clientHeight || 0),
                timeToCoordinate: (t) => {
                    const x = ts.timeToCoordinate(Number(t));
                    return Number.isFinite(x) ? x : null;
                },
                priceToCoordinate: (p) => {
                    const y = candlestickSeries.priceToCoordinate(Number(p));
                    return Number.isFinite(y) ? y : null;
                },
                zoneEndX: (tEnd) => {
                    const x = zoneEndXClipped(ts, Number(tEnd), allChartData);
                    return Number.isFinite(x) ? x : null;
                },
            });
        } catch (e) {
            console.error('[SMC Flow] dashboard redraw', e);
        }
    }


    /** PA DGT + VP — Canvas2D. */
    function redrawPriceActionOverlay(opts) {
        const skipLiquidity = !!(opts && opts.skipLiquidity);
        const skipLabels = !!(opts && opts.skipNameLabels);
        if (!skipLiquidity) {
            redrawLiquiditySwingsOverlay();
        }
        try {
            if (!priceActionOverlay || !chart || !candlestickSeries) return;
            const painter = window.PlatformChartCanvasOverlay;
            const enabled = !!(layerPa && layerPa.checked && lastPaPayload && allChartData.length);
            if (!painter || typeof painter.paintPriceAction !== 'function') {
                priceActionOverlay.replaceChildren();
                return;
            }
            let scaleW = 0;
            try {
                if (typeof candlestickSeries.priceScale === 'function') {
                    scaleW = Number(candlestickSeries.priceScale().width()) || 0;
                }
            } catch (_) {
                scaleW = 0;
            }
            const api = buildChartCanvasCoordApi({ enabled, priceScaleWidth: scaleW });
            if (api) painter.paintPriceAction(priceActionOverlay, lastPaPayload, api);
            else priceActionOverlay.replaceChildren();
        } catch (e) {
            console.error('[PriceAction DGT] dashboard canvas redraw', e);
        } finally {
            if (!skipLabels && typeof renderAllIndicatorNameLabels === 'function') {
                renderAllIndicatorNameLabels();
            }
        }
    }


    /**
     * Pocket Option считает свечу растущей только при close > open.
     * Lightweight Charts использует close >= open, поэтому точный доджи без явного
     * per-bar цвета становится зелёным. Для равных OHLC задаём красный цвет явно,
     * чтобы dashboard совпадал с правилом и визуальным результатом PO.
     *
     * Функция возвращает исходный объект для обычных свечей: это сохраняет штатную
     * бычью/медвежью окраску библиотеки и не создаёт лишние объекты на каждом poll.
     */
    function paintPocketOptionDoji(bar) {
        if (!bar) return bar;
        const open = Number(bar.open);
        const close = Number(bar.close);
        if (!Number.isFinite(open) || !Number.isFinite(close) || open !== close) {
            return bar;
        }
        return {
            ...bar,
            color: CANDLE_DOWN_COLOR,
            borderColor: CANDLE_DOWN_COLOR,
            wickColor: CANDLE_DOWN_COLOR,
        };
    }

    /**
     * Окраска свечей DGT. На poll НЕ делаем setData всего ряда — только update хвоста;
     * иначе каждые 500ms LightweightCharts пересобирает серию и появляются «ломаные» бары.
     * fullResync: true — при первой загрузке, смене TF и выключении слоя PA.
     * Последний бар при активном tween берётся через candleSmooth.visualFor — иначе PA
     * каждый poll снэпает close и убивает плавность.
     */
    function applyPaBarColors(opts = {}) {
        const fullResync = opts && opts.fullResync === true;
        if (!candlestickSeries || !allChartData.length) return;
        const plainBar = (c) => {
            const src = candleSmooth ? candleSmooth.visualFor(c) : c;
            return paintPocketOptionDoji({
                time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
            });
        };
        const plain = allChartData.map((c) => paintPocketOptionDoji({
            time: c.time, open: c.open, high: c.high, low: c.low, close: c.close,
        }));
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        const paOn = layerPa && layerPa.checked && bc && Object.keys(bc).length > 0;
        if (!paOn) {
            if (fullResync) {
                // resyncCandlestickSeriesFromAllChartData уже сделал setData — только синхрон count.
                candleSeriesBarCount = allChartData.length;
            }
            return;
        }
        const paintBar = (c) => {
            const src = candleSmooth ? candleSmooth.visualFor(c) : c;
            const o = bc[String(c.time)];
            if (!o) {
                return paintPocketOptionDoji({
                    time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
                });
            }
            return {
                time: src.time, open: src.open, high: src.high, low: src.low, close: src.close,
                color: o.color, borderColor: o.borderColor || o.color, wickColor: o.wickColor || o.color,
            };
        };
        if (fullResync) {
            if (candleSmooth) candleSmooth.reset();
            try {
                candlestickSeries.setData(allChartData.map(paintBar));
            } catch (e) {
                try { candlestickSeries.setData(plain); } catch (e2) { console.error('[PA] fallback', e2); }
            }
            candleSeriesBarCount = allChartData.length;
            syncLastBarKeyFromData();
            if (candleSmooth && allChartData.length) {
                candleSmooth.snap(allChartData[allChartData.length - 1]);
            }
            return;
        }
        // PA-цвета уже в mapBarForCandlestickSeries; update() ломает canvas LWC v5.
        pushCandlestickSeriesIfTailChanged({ preserveRange: true });
    }

    /** Цвета DGT для одного бара — в update-пути плавной анимации. */
    function paintBarForSmooth(bar) {
        if (!bar) return bar;
        const bc = lastPaPayload && lastPaPayload.bar_colors ? lastPaPayload.bar_colors : null;
        const paOn = layerPa && layerPa.checked && bc && Object.keys(bc).length > 0;
        if (!paOn) {
            return paintPocketOptionDoji({
                time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close,
            });
        }
        const o = bc[String(bar.time)];
        if (!o) {
            return paintPocketOptionDoji({
                time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close,
            });
        }
        return {
            time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close,
            color: o.color, borderColor: o.borderColor || o.color, wickColor: o.wickColor || o.color,
        };
    }

    function updateTfOptionStyles() {
        document.querySelectorAll('.tf-option').forEach(btn => {
            const on = parseInt(btn.dataset.minutes, 10) === currentTimeframeMinutes;
            btn.classList.toggle('bg-blue-900/50', on);
            btn.classList.toggle('font-medium', on);
        });
        const cur = document.querySelector('.tf-option[data-minutes="' + currentTimeframeMinutes + '"]');
        if (tfLabelEl && cur) tfLabelEl.textContent = cur.dataset.label || currentTimeframeMinutes + 'м';
    }

    function currentInstrumentPayoutPercent() {
        if (!currentInstrument) return null;
        const span = document.querySelector(
            '.instrument-payout[data-instrument="' + CSS.escape(currentInstrument) + '"]'
        );
        if (!span || span.dataset.payout === '' || span.dataset.payout == null) return null;
        const n = parseFloat(span.dataset.payout);
        return Number.isFinite(n) ? n : null;
    }
    function syncChartTitlePayout() {
        if (!chartTitlePayoutEl) return;
        if (!currentDisplayName) {
            chartTitlePayoutEl.textContent = '';
            chartTitlePayoutEl.className = 'hidden text-sm font-medium tabular-nums shrink-0';
            return;
        }
        const n = currentInstrumentPayoutPercent();
        chartTitlePayoutEl.classList.remove('hidden');
        if (n == null) {
            chartTitlePayoutEl.textContent = '—';
            chartTitlePayoutEl.className = 'text-sm font-medium tabular-nums shrink-0 text-zinc-500';
            return;
        }
        chartTitlePayoutEl.textContent = Math.round(n) + '%';
        chartTitlePayoutEl.className = 'text-sm font-medium tabular-nums shrink-0 ' + payoutClassByValue(n);
    }
    function updateChartTitle() {
        if (!currentDisplayName) {
            chartTitle.textContent = 'Выберите инструмент';
            syncChartTitlePayout();
            return;
        }
        const cur = document.querySelector('.tf-option[data-minutes="' + currentTimeframeMinutes + '"]');
        const lab = cur && cur.dataset.label ? cur.dataset.label : (currentTimeframeMinutes + 'м');
        chartTitle.textContent = currentDisplayName + ' (' + lab + ')';
        syncChartTitlePayout();
    }

    function copyCurrentInstrumentName() {
        if (!currentDisplayName || !chartTitle) return;

        const textToCopy = currentDisplayName;
        const defaultTitle = 'Кликните, чтобы скопировать название инструмента';
        const showCopyStatus = (statusText) => {
            chartTitle.setAttribute('title', statusText);
            setTimeout(() => {
                chartTitle.setAttribute('title', defaultTitle);
            }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy)
                .then(() => showCopyStatus('Скопировано'))
                .catch(() => showCopyStatus('Не удалось скопировать'));
            return;
        }

        try {
            const textarea = document.createElement('textarea');
            textarea.value = textToCopy;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(textarea);
            showCopyStatus(ok ? 'Скопировано' : 'Не удалось скопировать');
        } catch (_) {
            showCopyStatus('Не удалось скопировать');
        }
    }

    function fetchStructures() {
        if (!currentInstrument) return;
        const wantSweepRaid = !!(layerSweepRaid && layerSweepRaid.checked);
        const wantEngulfing = !!(layerEngulfing && layerEngulfing.checked);
        const wantOb = !!(layerOb && layerOb.checked);
        const wantFvg = !!(layerFvg && layerFvg.checked);
        // Нет включённых слоёв зон — не бьём /structures каждые 15с впустую.
        if (!wantOb && !wantFvg && !wantSweepRaid && !wantEngulfing) {
            lastZones = [];
            lastSweepRaidPayload = null;
            lastEngulfingPayload = null;
            return;
        }
        if (!window.PlatformChartFetch) return;
        const params = new URLSearchParams({
            instrument: currentInstrument,
            timeframe: String(currentTimeframeMinutes)
        });
        if (wantSweepRaid) params.set('include_sweep_raid', '1');
        if (wantEngulfing) params.set('include_engulfing', '1');
        const hist = appendIndicatorHistoryParams(params);
        PlatformChartFetch.requestJson('structures', {
            key: currentInstrument + '|' + String(currentTimeframeMinutes) + '|' + (wantSweepRaid ? 'srl' : '') + (wantEngulfing ? 'eng' : '') + '|' + hist.since + '|' + hist.limit,
            url: STRUCTURES_URL + '?' + params.toString(),
            apply: (data) => {
                lastZones = data.zones || [];
                lastSweepRaidPayload = wantSweepRaid && data && data.sweep_raid_liquidity
                    ? data.sweep_raid_liquidity
                    : null;
                lastEngulfingPayload = wantEngulfing && data && data.engulfing_liquidity
                    ? data.engulfing_liquidity
                    : null;
                const needStructureOverlay = wantOb || wantFvg || wantSweepRaid || wantEngulfing;
                if (needStructureOverlay) scheduleChartDomOverlaySyncHeavy();
            },
        });
    }

    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = null;

        // HTTP poll только без live WS. При live — хвост исключительно через /chart-live.
        const pollOnce = () => {
            if (chartViewportSettling || !currentInstrument || loadingMore || !candlestickSeries) return;
            const inst = currentInstrument;
            const seq = ++pollSeq;
            fetchCandles(inst, { kind: 'poll' })
                .then(data => {
                    if (seq !== pollSeq || inst !== currentInstrument || chartViewportSettling) return;
                    const candles = data.candles || [];
                    if (candles.length === 0) return;
                    const newData = buildChartData(candles);
                    const prevDataLen = allChartData.length;
                    const prevLastT = prevDataLen ? allChartData[prevDataLen - 1].time : null;
                    const followTail = isViewNearRightEdge();
                    applyIncrementalChartUpdate(newData, { liveTail: followTail, poll: true });
                    tickCandleCloseTimer();
                    if (followTail) {
                        const appended = allChartData.length > prevDataLen
                            || (prevLastT != null && allChartData.length
                                && allChartData[allChartData.length - 1].time > prevLastT);
                        if (appended) {
                            maintainTailViewportIfFollowing();
                        }
                    }
                    // Poll-режим: на новой свече сразу обновить индикаторы (не ждать 15с).
                    const newLastT = newData.length ? newData[newData.length - 1].time : null;
                    if (prevLastT != null && newLastT != null && newLastT > prevLastT) {
                        refreshOverlaysOnNewBar();
                    }
                })
                .catch(console.error);
        };

        const schedulePoll = (ms) => {
            if (refreshInterval) clearInterval(refreshInterval);
            refreshInterval = setInterval(pollOnce, Math.max(250, ms | 0));
        };

        const stopPoll = () => {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
            }
        };

        const liveOk = !!(boot.liveEnabled && boot.liveWsUrl && boot.liveToken
            && window.PlatformChartLive && typeof window.PlatformChartLive.create === 'function');

        if (liveOk) {
            stopPoll();
            // Клиент мог уже стартовать ради payout broadcast — только донастраиваем S5.
            if (!chartLiveClient) {
                chartLiveClient = window.PlatformChartLive.create({
                    wsUrl: boot.liveWsUrl,
                    token: boot.liveToken,
                    getInstrument: () => currentInstrument,
                    getBarPeriodSec: () => timeframeSeconds(currentTimeframeMinutes),
                    getLastBar: () => (allChartData.length ? allChartData[allChartData.length - 1] : null),
                    onLiveBar: (bar) => {
                        if (chartViewportSettling || !candlestickSeries || !bar) return;
                        const prevLast = allChartData.length ? allChartData[allChartData.length - 1].time : null;
                        const followTail = isViewNearRightEdge();
                        applyIncrementalChartUpdate([bar], { liveTail: followTail, poll: true });
                        tickCandleCloseTimer();
                        if (followTail) maintainTailViewportIfFollowing();
                        // Новая свеча при live WS → оверлеи (раньше только 15с poll / смена инструмента).
                        const barT = Number(bar.time);
                        if (prevLast != null && Number.isFinite(barT) && barT > Number(prevLast)) {
                            refreshOverlaysOnNewBar();
                        }
                    },
                    onPayout: applyInstrumentPayoutLive,
                    onBotEvent: (msg) => {
                        // Лента «СДЕЛКИ PO»: полный upsert карточки (см. initDashPoTrades).
                        if (typeof window.__dashPoTradesOnBotEvent === 'function') {
                            try { window.__dashPoTradesOnBotEvent(msg); } catch (_) {}
                        }
                        // Сайдбар open_trade флаги по всем ботам пользователя.
                        if (typeof window.__dashSidebarOnBotEvent === 'function') {
                            try { window.__dashSidebarOnBotEvent(msg); } catch (_) {}
                        }
                        // Маркеры сделок на графике — только при «Торговля» (debounce).
                        if (showTrades && typeof fetchChartTrades === 'function') {
                            if (window.__dashChartTradesDebounce) clearTimeout(window.__dashChartTradesDebounce);
                            window.__dashChartTradesDebounce = setTimeout(() => {
                                try { fetchChartTrades(); } catch (_) {}
                            }, 250);
                        }
                    },
                    onConnectionChange: (ok) => {
                        // Без HTTP fallback: только reconnect + heal.
                        if (ok) stopCandleHealLoop();
                        else startCandleHealLoop();
                    },
                });
            } else {
                chartLiveClient.resubscribe();
            }
            chartLiveClient.start();
            if (chartLiveClient.isConnected()) stopCandleHealLoop();
            else startCandleHealLoop();
        } else {
            schedulePoll(500);
            startCandleHealLoop();
        }

        startOverlaysRefresh();
    }

    /**
     * Раз в OVERLAYS_REFRESH_MS подтягиваем тяжёлые оверлеи: зоны структуры и
     * индикаторы. Плюс refreshOverlaysOnNewBar() при появлении новой свечи (live/poll).
     */
    function refreshOverlaysOnNewBar() {
        if (!currentInstrument || loadingMore || !candlestickSeries) return;
        recomputeClientIndicators();
        fetchStructures();
        if (anyServerClassicLayerOn()) fetchClassicIndicators();
    }

    function startOverlaysRefresh() {
        // Редкий heal серверных classic/структур; клиентские — на каждом баре локально.
        if (overlaysRefreshInterval) clearInterval(overlaysRefreshInterval);
        overlaysRefreshInterval = setInterval(() => {
            if (!currentInstrument || loadingMore || !candlestickSeries) return;
            recomputeClientIndicators();
            fetchStructures();
            if (showTrades) fetchChartTrades();
            if (anyServerClassicLayerOn()) fetchClassicIndicators();
        }, Math.max(30000, OVERLAYS_REFRESH_MS * 2));
    }

    function stopOverlaysRefresh() {
        if (overlaysRefreshInterval) {
            clearInterval(overlaysRefreshInterval);
            overlaysRefreshInterval = null;
        }
    }

    function setLoading(show) {
        chartLoading.classList.toggle('hidden', !show);
    }

    /**
     * Загрузка свечей для графика с учётом режима запроса.
     *
     * Раньше каждый клик по инструменту тянул сразу 24 часа M1 (limit=1440, since=now-86400),
     * из-за чего сервер на каждый запрос сканировал из БД до 15000 S5-строк. Теперь окно
     * выбирается под конкретный сценарий:
     *   - 'initial' (выбор инструмента / смена TF): малое окно для мгновенного отклика;
     *   - 'more'    (ленивая догрузка влево при скролле): расширяется до MAX_WINDOW_SEC;
     *   - 'poll'    (auto-refresh каждые 500мс): узкое окно — только последние минуты,
     *               чтобы поллинг не перезатирал уже догруженную историю.
     *
     * Параметры:
     *   instrument — символ инструмента;
     *   opts.before — нижняя граница диапазона (для догрузки влево);
     *   opts.kind   — режим запроса: initial | more | poll (см. выше).
     */
    function fetchCandles(instrument, opts = {}) {
        const before = opts.before != null ? opts.before : null;
        // По умолчанию ведём себя как первичная загрузка — это безопасный fallback
        // для случаев, когда вызывающий код не указал режим явно.
        const kind = opts.kind || 'initial';

        let since;
        let limit;
        if (kind === 'more') {
            // Догрузка влево до потолка MAX_WINDOW_SEC (= retention из /settings).
            // Пользователь может пролистать всю доступную в БД историю в пределах окна.
            since = Math.floor(Date.now() / 1000) - MAX_WINDOW_SEC;
            limit = LOAD_MORE_LIMIT;
        } else if (kind === 'poll') {
            // Авто-обновление: достаточно ровно «хвоста» последних минут.
            // Минимизирует чтение из БД и payload.
            since = Math.floor(Date.now() / 1000) - POLL_WINDOW_SEC;
            limit = POLL_LIMIT;
        } else {
            // Первичный показ: INITIAL_BARS свечей выбранного TF (не «2 часа на всех»).
            since = Math.floor(Date.now() / 1000) - initialWindowSecForTf(currentTimeframeMinutes);
            limit = initialLimitForTf(currentTimeframeMinutes);
        }

        const params = new URLSearchParams({
            instrument,
            timeframe: String(currentTimeframeMinutes),
            limit: String(limit),
            since: String(since),
        });
        if (before != null) params.set('before', before);
        // Live partial для всех TF (как на странице бота): S5/S15 обновляются каждые 500ms, не раз в 5с.
        if (before == null) {
            params.set('include_partial', '1');
        }

        const url = CANDLES_URL + '?' + params.toString();
        // Отдельные каналы: poll не abort'ит initial/more и наоборот.
        // Poll coalesce по instrument|tf — не копим очередь 500мс-тиков.
        const channel = kind === 'poll' ? 'candles-poll'
            : (kind === 'more' ? 'candles-more' : 'candles-initial');
        const key = kind === 'poll'
            ? (instrument + '|' + String(currentTimeframeMinutes) + '|poll')
            : (instrument + '|' + String(currentTimeframeMinutes) + '|' + kind + '|' + String(before ?? ''));

        const finishPayload = (payload) => {
            if (!payload || typeof payload !== 'object') {
                return { candles: [] };
            }
            applyM1CountdownFromPayload(payload);
            // Микро-инструменты: подставляем 8 знаков (или иной scale) до setData/update.
            applyPriceScaleFromPayload(payload);
            if (currentTimeframeMinutes !== 1) {
                applyGenericCountdownFromPayload(payload, currentTimeframeMinutes);
            } else {
                tfCloseCountdownAnchorSec = null;
                tfCloseCountdownAnchorPerfMs = null;
            }
            return payload;
        };

        if (!window.PlatformChartFetch) {
            return fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then((r) => r.json()).then(finishPayload);
        }

        const p = PlatformChartFetch.requestJson(channel, {
            key: key,
            url: url,
            // initial всегда уникален по смыслу; poll/more coalesce.
            coalesce: kind !== 'initial',
        });
        // Coalesce poll: нет нового HTTP — отдаём пустой хвост (caller early-return).
        if (!p) {
            return Promise.resolve({ candles: [] });
        }
        return p.then((payload) => finishPayload(payload));
    }

    function buildChartData(candles) {
        return candles.map(c => ({
            time: c.time,
            open: c.open,
            high: c.high,
            low: c.low,
            close: c.close
        })).sort((a, b) => a.time - b.time);
    }

    /**
     * Подмешивает свечи из poll/initial в allChartData, не затирая историю слева.
     * Poll (POLL_WINDOW_SEC ≈ 5 мин) раньше при совпадении первого бара с oldest делал
     * allChartData = newData и через applyPaBarColors() setData() — график «схлопывался»
     * и ломался примерно через 5–10 минут простоя на одном инструменте.
     *
     * Последний бар: не series.update() сразу, а candleSmooth.toward() — close «плывёт»
     * к новой цене между poll-тиками (как на Pocket Option).
     */
    function applyIncrementalChartUpdate(newData, opts = {}) {
        if (chartViewportSettling) return;
        if (!candlestickSeries || !Array.isArray(newData) || newData.length === 0) return;

        if (allChartData.length === 0) {
            allChartData = newData;
            oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
            if (candleSmooth) candleSmooth.reset();
            candlestickSeries.setData(allChartData.map(mapBarForCandlestickSeries));
            candleSeriesBarCount = allChartData.length;
            syncLastBarKeyFromData();
            // Зафиксировать стартовую точку tween на последнем баре.
            if (candleSmooth && allChartData.length) {
                candleSmooth.snap(allChartData[allChartData.length - 1]);
            }
            recomputeClientIndicators();
            if (typeof renderChartMarkers === 'function') renderChartMarkers();
            tickCandleCloseTimer();
            applyPaBarColors({ fullResync: true });
            requestAnimationFrame(redrawPriceActionOverlay);
            return;
        }

        const prevDataLen = allChartData.length;
        const prevOldest = allChartData[0].time;
        const prevLast = allChartData[allChartData.length - 1].time;

        const byTime = new Map();
        for (const c of allChartData) byTime.set(c.time, c);
        const isPoll = opts.poll === true;
        if (isPoll) {
            // Poll окно ~5 мин: сервер ревизит закрытые бары при re-aggregate S5→M1.
            // Если merge'ить всё окно в allChartData, а в LWC пушить update() — canvas «ломается»
            // на 5–6 барах слева от хвоста. Берём только forming + только что закрытый.
            const mergeFromIdx = Math.max(0, allChartData.length - POLL_MERGE_TAIL_BARS);
            const mergeFromTime = allChartData[mergeFromIdx].time;
            for (const c of newData) {
                if (c.time >= mergeFromTime || c.time > prevLast) {
                    byTime.set(c.time, c);
                }
            }
        } else {
            for (const c of newData) byTime.set(c.time, c);
        }
        const merged = Array.from(byTime.values()).sort((a, b) => a.time - b.time);

        const prependedOlder = merged.length > 0 && merged[0].time < prevOldest;
        const newestTime = merged[merged.length - 1].time;
        const appendedNewBars = merged.length > prevDataLen;

        allChartData = merged;
        oldestLoadedTime = oldestLoadedTime == null
            ? merged[0].time
            : Math.min(oldestLoadedTime, merged[0].time);

        if (prependedOlder) {
            lastSyncedTailKey = '';
            resyncCandlestickSeriesFromAllChartData({ preserveRange: true });
            recomputeClientIndicators();
            if (typeof renderChartMarkers === 'function') renderChartMarkers();
            tickCandleCloseTimer();
            requestAnimationFrame(redrawPriceActionOverlay);
            return;
        }

        // Poll: full setData только при догрузке слева; новая свеча — update(time), не setData(~130 баров).
        pushCandlestickSeriesIfTailChanged({ preserveRange: true });
        if (appendedNewBars && tailFollowActive) {
            maintainTailViewportIfFollowing();
        }

        const barAdvanced = appendedNewBars || newestTime > prevLast;
        recomputeClientIndicators(barAdvanced ? undefined : { light: true });
        if (typeof renderChartMarkers === 'function') renderChartMarkers();
        tickCandleCloseTimer();
        if (!barAdvanced && layerPa && layerPa.checked) {
            requestAnimationFrame(redrawPriceActionOverlay);
        }
    }

    /**
     * Синхронизация HTML-оверлеев с canvas при любом изменении viewport.
     *
     * Binodex: таймер на canvas. У нас DOM — таймер в том же rAF, что зум LWC (без throttle).
     * Тяжёлые слои — trailing coalesce, чтобы не терять финальный кадр колеса.
     */
    let chartTimerOverlayRaf = null;
    let chartDomOverlayHeavyRaf = null;
    let chartDomOverlayHeavyPending = false;

    function markChartDomOverlayDirty() {
        chartDomOverlayDirty = true;
    }

    /** Периодический flush «грязных» оверлеев — attachPrimitive не трогает DOM напрямую. */
    function ensureChartDomOverlayTickLoop() {
        if (chartDomOverlayTickTimer) return;
        chartDomOverlayTickTimer = setInterval(() => {
            if (!chartDomOverlayDirty || !chart) return;
            chartDomOverlayDirty = false;
            scheduleCandleTimerSync();
            scheduleChartDomOverlaySyncHeavy();
        }, CHART_DOM_OVERLAY_TICK_MS);
    }

    function stopChartDomOverlayTickLoop() {
        if (!chartDomOverlayTickTimer) return;
        clearInterval(chartDomOverlayTickTimer);
        chartDomOverlayTickTimer = null;
        chartDomOverlayDirty = false;
    }

    /** Только позиция таймера — лёгкий rAF. */
    function scheduleCandleTimerSync() {
        if (chartTimerOverlayRaf !== null) return;
        chartTimerOverlayRaf = requestAnimationFrame(() => {
            chartTimerOverlayRaf = null;
            try {
                positionCandleTimerOverlay();
            } catch (e2) {
                console.error('[chart] candle timer overlay sync', e2);
            }
        });
    }

    /** Canvas-оверлеи отдельно от таймера. */
    let chartCanvasOverlayRaf = null;
    function scheduleCanvasOverlaysSync() {
        if (chartCanvasOverlayRaf !== null) return;
        chartCanvasOverlayRaf = requestAnimationFrame(() => {
            chartCanvasOverlayRaf = null;
            try {
                paintAllChartCanvasOverlaysFast();
            } catch (e) {
                console.error('[chart] canvas overlays sync', e);
            }
        });
    }

    /** Лёгкая синхронизация (таймер, PO-лучи). */
    function scheduleChartDomOverlaySyncLight(force) {
        const now = performance.now();
        if (!force && (now - chartDomOverlaySyncLastMs) < CHART_DOM_OVERLAY_SYNC_MIN_MS) {
            scheduleCandleTimerSync();
            return;
        }
        chartDomOverlaySyncLastMs = now;
        scheduleCandleTimerSync();
        const hasOpenRays = !!(showTrades && Array.isArray(lastOpenTrades) && lastOpenTrades.some(
            (t) => t && t.instrument === currentInstrument
        ));
        if (!hasOpenRays) return;
            try {
                renderPoOpenTradeRaysOverlay();
            } catch (ePo) {
                console.error('[chart] po open trade rays sync', ePo);
            }
    }

    /** Тяжёлая синхронизация (структуры/PA/линейка) — trailing при pan/zoom. */
    function scheduleChartDomOverlaySyncHeavy() {
        if (chartDomOverlayHeavyRaf !== null) {
            chartDomOverlayHeavyPending = true;
            return;
        }
        chartDomOverlayHeavyRaf = requestAnimationFrame(() => {
            chartDomOverlayHeavyRaf = null;
            // Canvas-слои отдельно; таймер — лёгкий sync.
            scheduleCandleTimerSync();
            scheduleCanvasOverlaysSync();
            if (CHART_HTML_TIME_RULER_ENABLED) {
                try {
                    renderChartTimeRulerOverlay();
                } catch (eRuler) {
                    console.error('[chart] time ruler overlay sync', eRuler);
                }
            }
            scheduleChartDomOverlaySyncLight(true);
            if (chartDomOverlayHeavyPending) {
                chartDomOverlayHeavyPending = false;
                scheduleChartDomOverlaySyncHeavy();
            }
        });
    }

    function bindChartViewportInteractionSync() {
        if (!chartWrapper || chartWrapper.dataset.viewportSyncBound === '1') return;
        chartWrapper.dataset.viewportSyncBound = '1';
        const onInteract = () => {
            scheduleCandleTimerSync();
            scheduleChartDomOverlaySyncHeavy();
        };
        chartWrapper.addEventListener('wheel', onInteract, { passive: true, capture: true });
        chartWrapper.addEventListener('pointermove', (e) => {
            if (e.buttons) onInteract();
        }, { passive: true });
        chartWrapper.addEventListener('pointerup', onInteract, { passive: true });
    }

    function createChartDomOverlaySyncPrimitive() {
        const paneView = {
            renderer() {
                return {
                    draw() {
                        /* Нам нужен только updateAllViews; draw оставляем пустым. */
                    },
                };
            },
        };
        const paneViewsStable = [paneView];
        return {
            updateAllViews() {
                scheduleCandleTimerSync();
                markChartDomOverlayDirty();
            },
            paneViews() {
                return paneViewsStable;
            },
        };
    }

    function renderChartTimeRulerOverlay() {
        if (!CHART_HTML_TIME_RULER_ENABLED) {
            if (chartTimeRulerOverlay) {
                chartTimeRulerOverlay.classList.add('hidden');
                chartTimeRulerOverlay.replaceChildren();
            }
            return;
        }
        const PCM = window.PlatformChartMarkers;
        if (!PCM || typeof PCM.renderTimeRulerOverlay !== 'function' || !chartTimeRulerOverlay || !chart) return;
        const b = computeChartPlotBounds();
        PCM.renderTimeRulerOverlay({
            overlayEl: chartTimeRulerOverlay,
            chart,
            candleData: allChartData,
            barSec: timeframeSeconds(currentTimeframeMinutes),
            plotW: b.plotW,
            plotH: b.vLineH,
            timeH: b.timeH,
            show: !!currentInstrument && allChartData.length > 0,
        });
    }

    /** Границы области графика для HTML-кроссхейра (основной pane + шкалы). */
    function computeChartPlotBounds() {
        const cw = chartWrapper ? chartWrapper.clientWidth : 0;
        const ch = chartWrapper ? chartWrapper.clientHeight : 0;
        let scaleW = 78;
        try {
            if (candlestickSeries && typeof candlestickSeries.priceScale === 'function') {
                const w = Number(candlestickSeries.priceScale().width());
                if (Number.isFinite(w) && w > 0) scaleW = w;
            }
        } catch (_) {}
        let timeH = 28;
        try {
            if (chart && typeof chart.timeScale === 'function') {
                const h = Number(chart.timeScale().height());
                if (Number.isFinite(h) && h > 0) timeH = h;
            }
        } catch (_) {}
        let plotH = Math.max(1, ch - timeH);
        try {
            if (chart && typeof chart.panes === 'function') {
                const panes = chart.panes();
                if (panes && panes[0] && typeof panes[0].getHeight === 'function') {
                    const ph = Number(panes[0].getHeight());
                    if (Number.isFinite(ph) && ph > 0) plotH = ph;
                }
            }
        } catch (_) {}
        const vLineH = Math.max(plotH, ch - timeH);
        return {
            plotW: Math.max(1, cw - scaleW),
            plotH,
            vLineH,
            scaleW,
            timeH,
            cw,
            ch,
        };
    }

    function hideChartCrosshairOverlay() {
        [chartCrosshairHLine, chartCrosshairVLine, chartCrosshairPrice, chartCrosshairTime].forEach((el) => {
            if (el) el.classList.add('hidden');
        });
    }

    function formatChartCrosshairPrice(price) {
        if (!Number.isFinite(Number(price))) return '';
        return Number(price).toFixed(chartPricePrecision);
    }

    function formatChartCrosshairTime(time) {
        if (time == null) return '';
        const sec = Math.floor(Number(typeof time === 'number' ? time : Number(time)));
        if (!Number.isFinite(sec)) return '';
        const TF = window.PlatformTimeFormat;
        if (TF && typeof TF.crosshairDateTime === 'function') {
            return TF.crosshairDateTime(sec);
        }
        const d = new Date(sec * 1000);
        const p = (n) => String(n).padStart(2, '0');
        return `${p(d.getUTCDate())} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`;
    }

    function chartTimezoneCreateOptions(timeScaleBase) {
        const PCM = window.PlatformChartMarkers;
        if (PCM && typeof PCM.buildChartTimezoneOptions === 'function') {
            return PCM.buildChartTimezoneOptions(timeScaleBase);
        }
        const TF = window.PlatformTimeFormat;
        if (TF && typeof TF.buildChartTimezoneOptions === 'function') {
            return TF.buildChartTimezoneOptions(timeScaleBase);
        }
        return { timeScale: timeScaleBase || {} };
    }

    function resolveChartCrosshairUnixTime(param) {
        const PCM = window.PlatformChartMarkers;
        if (!PCM || typeof PCM.resolveCrosshairUnixTime !== 'function' || !chart) {
            return param && param.time;
        }
        return PCM.resolveCrosshairUnixTime(
            param,
            chart.timeScale(),
            allChartData,
            timeframeSeconds(currentTimeframeMinutes),
            candlestickSeries,
        );
    }

    /**
     * LWC v5: y в param.point может быть относительным к pane осциллятора.
     * Переводим в абсолютные пиксели контейнера, как на bots/show.
     */
    function crosshairAbsoluteY(param) {
        const y = Number(param && param.point ? param.point.y : NaN);
        if (!Number.isFinite(y) || y < 0) return null;
        const paneIndex = Number(param.paneIndex);
        if (!Number.isFinite(paneIndex) || paneIndex <= 0 || !chart || typeof chart.panes !== 'function') {
            return y;
        }
        let offsetY = 0;
        try {
            const panes = chart.panes() || [];
            for (let i = 0; i < paneIndex && i < panes.length; i += 1) {
                const h = Number(panes[i] && typeof panes[i].getHeight === 'function' ? panes[i].getHeight() : NaN);
                if (Number.isFinite(h) && h > 0) offsetY += h;
            }
        } catch (_) {
            return y;
        }
        if (y >= offsetY - 0.5) return y;
        return offsetY + y;
    }

    /**
     * Свободный кроссхейр (CrosshairMode.Normal): линии рисуем в HTML, нативные линии LWC скрыты.
     * Раньше mode:1 (Magnet) «прилипал» к OHLC свечей.
     */
    function setupChartCrosshairOverlay() {
        if (!chartCrosshairOverlay || !chart) return;
        chartCrosshairOverlay.classList.remove('hidden');
        chart.subscribeCrosshairMove((param) => {
            if (!param || !param.point || !candlestickSeries) {
                hideChartCrosshairOverlay();
                return;
            }
            const x = Number(param.point.x);
            if (!Number.isFinite(x) || x < 0) {
                hideChartCrosshairOverlay();
                return;
            }
            const b = computeChartPlotBounds();
            if (x > b.plotW) {
                hideChartCrosshairOverlay();
                return;
            }
            const absY = crosshairAbsoluteY(param);
            if (absY == null || absY > b.vLineH) {
                hideChartCrosshairOverlay();
                return;
            }

            const paneIndex = Number(param.paneIndex);
            const onMainPane = !Number.isFinite(paneIndex) || paneIndex <= 0;

            if (chartCrosshairVLine) {
                chartCrosshairVLine.classList.remove('hidden');
                chartCrosshairVLine.style.top = '0';
                chartCrosshairVLine.style.left = x + 'px';
                chartCrosshairVLine.style.height = b.vLineH + 'px';
            }

            if (chartCrosshairHLine) {
                chartCrosshairHLine.classList.remove('hidden');
                chartCrosshairHLine.style.left = '0';
                chartCrosshairHLine.style.top = absY + 'px';
                chartCrosshairHLine.style.width = b.plotW + 'px';
            }

            if (chartCrosshairPrice) {
                if (onMainPane && absY <= b.plotH) {
                    let price = null;
                    try {
                        price = candlestickSeries.coordinateToPrice(absY);
                    } catch (_) {}
                    chartCrosshairPrice.classList.remove('hidden');
                    chartCrosshairPrice.textContent = formatChartCrosshairPrice(price);
                    chartCrosshairPrice.style.right = '0';
                    chartCrosshairPrice.style.width = b.scaleW + 'px';
                    chartCrosshairPrice.style.top = Math.max(0, Math.min(b.ch - 18, absY - 9)) + 'px';
                } else {
                    chartCrosshairPrice.classList.add('hidden');
                }
            }

            if (chartCrosshairTime) {
                chartCrosshairTime.classList.remove('hidden');
                chartCrosshairTime.textContent = formatChartCrosshairTime(resolveChartCrosshairUnixTime(param));
                const labelW = 84;
                chartCrosshairTime.style.top = b.vLineH + 'px';
                chartCrosshairTime.style.left = Math.max(0, Math.min(b.plotW - labelW, x - labelW / 2)) + 'px';
            }
        });
    }

    function createChart() {
        // Останавливаем tween предыдущего графика (смена инструмента / TF / rebuild).
        if (candleSmooth) {
            candleSmooth.reset();
            candleSmooth = null;
        }
        stopChartDomOverlayTickLoop();
        stopCandleHealLoop();
        // Снимаем markers-plugin до chart.remove(), иначе на новой серии poll снова пойдёт через setData.
        detachCandlestickMarkersPlugin();
        if (chart) chart.remove();
        chartWrapper.innerHTML = '';
        candleSeriesBarCount = 0;
        lastSyncedTailKey = '';
        lastTailSeriesUpdateMs = 0;
        chartPriceMinMove = 0.00001;
        chartWrapper.classList.remove('hidden');
        structureOverlay.classList.remove('hidden');
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.classList.remove('hidden');
        if (sweepRaidLiquidityOverlay) sweepRaidLiquidityOverlay.classList.remove('hidden');
        if (engulfingLiquidityOverlay) engulfingLiquidityOverlay.classList.remove('hidden');
        if (priceActionOverlay) priceActionOverlay.classList.remove('hidden');
        if (smcFlowOverlay) smcFlowOverlay.classList.remove('hidden');
        if (classicIndicatorLabelsEl) classicIndicatorLabelsEl.classList.remove('hidden');
        chartPlaceholder.classList.add('hidden');

        // Normal = свободное движение; линии/подписи — в #chart-crosshair-overlay (как bots/show).
        const crosshairOptions = {
            mode: LightweightCharts.CrosshairMode.Normal,
            vertLine: { visible: false, labelVisible: false },
            horzLine: { visible: false, labelVisible: false },
        };

        const timeScaleBase = {
            borderColor: '#3f3f46',
            timeVisible: true,
            // Минуты — на HTML-линейке; секунды — в кроссхейре.
            secondsVisible: false,
            // «Воздух» справа без logical range > lastIdx (иначе LWC ломает последние ~7 свечей).
            rightOffset: CHART_RIGHT_GAP_BARS,
        };
        const chartTz = chartTimezoneCreateOptions(timeScaleBase);

        chart = LightweightCharts.createChart(chartWrapper, {
            layout: {
                background: { type: 'solid', color: '#191c30' },
                textColor: '#a1a1aa',
                // Скрываем логотип TradingView в углу графика (licensing: attributionLogo).
                attributionLogo: false,
            },
            grid: { vertLines: { color: '#27272a' }, horzLines: { color: '#27272a' } },
            rightPriceScale: { borderColor: '#3f3f46', scaleMargins: { top: 0.1, bottom: 0.2 } },
            localization: chartTz.localization,
            timeScale: chartTz.timeScale,
            // Как на bots/show и в TradingView: drag мышью = скролл; ось цены = масштаб/сдвиг по цене.
            handleScroll: {
                mouseWheel: true,
                pressedMouseMove: true,
                horzTouchDrag: true,
                vertTouchDrag: true,
            },
            handleScale: {
                mouseWheel: true,
                pinch: true,
                axisPressedMouseMove: { time: true, price: true },
                // Двойной клик по правой оси — снова autoScale (подогнать цену).
                axisDoubleClickReset: { time: true, price: true },
            },
            kineticScroll: {
                mouse: true,
                touch: true,
            },
            crosshair: crosshairOptions,
            width: chartWrapper.clientWidth,
            height: chartWrapper.clientHeight
        });
        // Для Chrome MCP / e2e: доступ к IChartApi (проба дёрганья loadMore).
        window.__platformChart = chart;

        candlestickSeries = chart.addSeries(LightweightCharts.CandlestickSeries, {
            // Цвета из профиля (дефолт как на PO); wick совпадает с телом.
            upColor: CANDLE_UP_COLOR,
            downColor: CANDLE_DOWN_COLOR,
            borderVisible: false,
            wickUpColor: CANDLE_UP_COLOR,
            wickDownColor: CANDLE_DOWN_COLOR,
            priceFormat: {
                type: 'price',
                precision: 5,
                minMove: 0.00001
            }
        });
        // Плавный close последней свечи (PlatformCandleSmooth), если флаг включён.
        candleSmooth = null;
        if (CHART_LIVE_SMOOTH_CLOSE
            && window.PlatformCandleSmooth
            && typeof window.PlatformCandleSmooth.create === 'function') {
            candleSmooth = window.PlatformCandleSmooth.create({
                getSeries: () => candlestickSeries,
                paintBar: paintBarForSmooth,
                // Binodex/PO: непрерывный chase (не timed easeOut) — тики не рвут скорость.
                tauMs: 160,
                // 0 = каждый rAF; Number(x)||24 ломал CHART_SMOOTH_MAX_FPS=0 (0 falsy).
                maxFps: (() => {
                    const n = Number(boot.smoothMaxFps);
                    return Number.isFinite(n) ? Math.max(0, n) : 24;
                })(),
            });
            // Таймер над полоской: sync на каждом visual frame smooth.
            if (typeof candleSmooth.setOnVisualFrame === 'function') {
                candleSmooth.setOnVisualFrame(() => {
                    scheduleCandleTimerSync();
                });
            }
            // Для Chrome MCP / диагностики: жив ли tween.
            window.__platformCandleSmooth = candleSmooth;
        }
        // Маркеры — лениво в renderChartMarkers(); attachPrimitive на серии свечей убран (ломал LWC canvas).
        candlestickMarkers = null;
        classicLineSeries = {};
        rsiLevelLines = [];
        classicOscPaneCount = 0;
        lastClassicNameState = { overlays: [], oscillators: [] };
        if (classicIndicatorLabelsEl) {
            classicIndicatorLabelsEl.innerHTML = '';
            classicIndicatorLabelsEl.classList.remove('hidden');
        }
        if (chartCrosshairOverlay) {
            chartCrosshairOverlay.classList.remove('hidden');
        }
        // HTML-линейка секунд — только после pan/zoom (scheduleChartDomOverlaySyncHeavy), не при старте.
        if (chartTimeRulerOverlay) {
            chartTimeRulerOverlay.classList.add('hidden');
            chartTimeRulerOverlay.replaceChildren();
        }

        setupChartCrosshairOverlay();

        chart.subscribeClick((param) => {
            const pick = pickDashboardTradeFromChartClick(param);
            if (pick && pick.tradeId) goToDashboardTradeDetails(pick);
        });

        chart.timeScale().subscribeVisibleLogicalRangeChange(() => {
            const userViewportChange = viewportChangeSuppressDepth <= 0 && dataSeriesUpdateDepth <= 0;
            if (userViewportChange) {
                tailFollowActive = false;
            }
            const range = chart.timeScale().getVisibleLogicalRange();
            // Догрузка влево — только при ручном pan пользователя; иначе setData/viewport при смене пары
            // даёт range.from < 2 и график «прыгает» (loadMore + preserveRange поверх initial viewport).
            if (range && !chartViewportSettling && !loadingMore && oldestLoadedTime != null
                && range.from < 2 && userViewportChange) {
                loadMoreCandles();
            }
            // Таймер сразу; индикаторы — trailing heavy (не nested rAF).
            scheduleCandleTimerSync();
            if (userViewportChange) {
                scheduleChartDomOverlaySyncHeavy();
                if (showTrades) schedulePaperTradeDurationOverlay();
            } else {
                scheduleChartDomOverlaySyncLight(false);
            }
        });
        bindChartViewportInteractionSync();
    }

    function loadMoreCandles() {
        if (!currentInstrument || loadingMore || oldestLoadedTime == null) return;
        const sinceT = candlesSince();
        if (oldestLoadedTime <= sinceT) return;

        loadingMore = true;
        // Лёгкий prepend: один setData + logical restore в том же sync-стеке.
        // Без visibility:hidden (мигание), без setVisibleRange у края (схлопывание),
        // без второго PA setData. from<0 клампим к 0, ширину окна сохраняем.
        const inst = currentInstrument;
        fetchCandles(currentInstrument, { before: oldestLoadedTime, kind: 'more' })
            .then(data => {
                if (inst !== currentInstrument) {
                    loadingMore = false;
                    return;
                }
                const candles = data.candles || [];
                if (candles.length === 0) { loadingMore = false; return; }
                const newBars = buildChartData(candles);
                const existingTimes = new Set(allChartData.map(c => c.time));
                const toAdd = newBars.filter(c => !existingTimes.has(c.time));
                if (toAdd.length === 0) { loadingMore = false; return; }
                const added = toAdd.length;

                // Logical range ДО мутации данных — pan во время fetch учтён (снимок здесь).
                let savedLogical = null;
                try {
                    savedLogical = chart && chart.timeScale
                        ? chart.timeScale().getVisibleLogicalRange()
                        : null;
                } catch (_) {}

                allChartData = [...toAdd, ...allChartData].sort((a, b) => a.time - b.time);
                oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                if (candleSmooth) candleSmooth.cancel();
                lastSyncedTailKey = '';

                let shifted = null;
                if (savedLogical
                    && Number.isFinite(savedLogical.from)
                    && Number.isFinite(savedLogical.to)) {
                    const width = Math.max(1, savedLogical.to - savedLogical.from);
                    const baseFrom = Math.max(0, savedLogical.from) + added;
                    shifted = { from: baseFrom, to: baseFrom + width };
                }

                withSuppressedDataSeriesViewport(() => {
                    withSuppressedViewportFollow(() => {
                        if (candleSmooth) candleSmooth.reset();
                        try {
                            candlestickSeries.setData(allChartData.map(mapBarForCandlestickSeries));
                            candleSeriesBarCount = allChartData.length;
                            if (candleSmooth && allChartData.length) {
                                candleSmooth.snap(allChartData[allChartData.length - 1]);
                            }
                        } catch (e) {
                            console.error('[chart] loadMore setData', e);
                        }
                        syncLastBarKeyFromData();
                        if (shifted) {
                            try {
                                chart.timeScale().setVisibleLogicalRange(shifted);
                            } catch (_) {}
                        }
                    });
                });

                tickCandleCloseTimer();
                // Один rAF: не держим loadingMore дольше нужного (меньше «залипания» скролла).
                requestAnimationFrame(() => {
                    if (inst !== currentInstrument) return;
                    loadingMore = false;
                    redrawStructureOverlay();
                });
                // Оверлеи не в том же тике, что setData — меньше main-thread лагов.
                setTimeout(() => {
                    if (inst !== currentInstrument) return;
                    fetchStructures();
                    recomputeClientIndicators();
                    if (anyServerClassicLayerOn()) fetchClassicIndicators();
                }, 250);
            })
            .catch(() => { loadingMore = false; });
    }

    function reloadChartData() {
        if (!currentInstrument || !candlestickSeries) return;
        setLoading(true);
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
        stopOverlaysRefresh();
        stopCandleHealLoop();
        pollSeq++;
        const loadSeq = ++chartLoadSeq;
        chartViewportSettling = true;
        const inst = currentInstrument;
        // Смена таймфрейма / явный reload — это «как заново выбрали инструмент»:
        // тянем только initial-окно, остальное пользователь догрузит скроллом.
        fetchCandles(inst, { kind: 'initial' })
            .then(data => {
                if (loadSeq !== chartLoadSeq || inst !== currentInstrument) return;
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    applyInitialChartPresentation(() => {
                        if (loadSeq !== chartLoadSeq || inst !== currentInstrument) return;
                        startAutoRefresh();
                        fetchStructures();
                        recomputeClientIndicators();
                        fetchChartTrades();
                        if (anyServerClassicLayerOn()) fetchClassicIndicators();
                    });
                } else {
                    chartViewportSettling = false;
                    if (candleSmooth) candleSmooth.reset();
                    candlestickSeries.setData([]);
                    candleSeriesBarCount = 0;
                    lastZones = [];
                    structureOverlay.innerHTML = '';
                    lastPaPayload = null;
                    lastLsPayload = null;
                    lastSweepRaidPayload = null;
                    lastSmcPayload = null;
                    lastChartSignals = [];
                    lastOpenTrades = [];
                    lastBollingerPayload = null;
                    lastRsiPayload = null;
                    lastSmaPayload = null;
                    lastEmaPayload = null;
                    lastVwmaPayload = null;
                    lastAtrPayload = null;
                    lastMacdPayload = null;
                    lastStochasticPayload = null;
                    lastCciPayload = null;
                    lastKeltnerPayload = null;
                    lastEnvelopsPayload = null;
                    lastParabolicSarPayload = null;
                    lastSuperTrendPayload = null;
                    lastAdxPayload = null;
                    lastWilliamsPayload = null;
                    lastAoPayload = null;
                    lastAlligatorPayload = null;
                    lastIchimokuPayload = null;
                    lastPriceChannelPayload = null;
                    lastNrtrPayload = null;
                    lastHmaPayload = null;
                    lastAroonPayload = null;
                    if (priceActionOverlay) priceActionOverlay.innerHTML = '';
                    if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
                    if (sweepRaidLiquidityOverlay) sweepRaidLiquidityOverlay.innerHTML = '';
                    if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
                    renderChartMarkers();
                    applyClassicOsEngineSeries();
                }
                updateChartTitle();
                tickCandleCloseTimer();
                requestAnimationFrame(redrawStructureOverlay);
                setLoading(false);
            })
            .catch(() => {
                if (loadSeq !== chartLoadSeq) return;
                chartViewportSettling = false;
                setLoading(false);
                tickCandleCloseTimer();
            });
    }

    /**
     * Сохранение или сброс параметров в iframe-модали не должен делать F5.
     * Сбрасываем coalescing старых запросов и перезагружаем данные текущего
     * графика: reloadChartData() сам запрашивает все включённые classic и
     * overlay-индикаторы, включая DeMarker.
     */
    window.addEventListener('platform:indicator-settings-saved', function () {
        if (window.PlatformChartFetch) {
            window.PlatformChartFetch.invalidateAll();
        }
        reloadChartData();
    });

    function selectInstrument(instrument, displayName) {
        if (currentInstrument === instrument) return;
        // Сброс всех in-flight: иначе ответ по старой паре может перезаписать новый график.
        if (window.PlatformChartFetch) window.PlatformChartFetch.invalidateAll();
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
        stopOverlaysRefresh();
        stopCandleHealLoop();
        pollSeq++;
        const loadSeq = ++chartLoadSeq;
        chartViewportSettling = true;
        oldestLoadedTime = null;
        currentInstrument = instrument;
        currentDisplayName = displayName;
        tailFollowActive = true;
        // Запоминаем пару — при F5 вернёмся к ней, а не к первой в списке.
        saveSelectedInstrument(instrument);
        document.querySelectorAll('.instrument-btn').forEach((b) => b.classList.toggle('bg-blue-900/40', b.dataset.instrument === instrument));
        // Синхронизируем выплату/доступность кнопок CALL|PUT с выбранной парой.
        if (typeof updateManualTradePayout === 'function') updateManualTradePayout();
        lastZones = [];
        structureOverlay.innerHTML = '';
        lastPaPayload = null;
        lastLsPayload = null;
        lastSweepRaidPayload = null;
        lastSmcPayload = null;
        lastChartSignals = [];
        lastOpenTrades = [];
        lastBollingerPayload = null;
        lastRsiPayload = null;
        lastSmaPayload = null;
        lastEmaPayload = null;
        lastVwmaPayload = null;
        lastAtrPayload = null;
        lastMacdPayload = null;
        lastStochasticPayload = null;
        lastCciPayload = null;
        lastKeltnerPayload = null;
        lastEnvelopsPayload = null;
        lastParabolicSarPayload = null;
        lastSuperTrendPayload = null;
        lastAdxPayload = null;
        lastWilliamsPayload = null;
        lastAoPayload = null;
        lastAlligatorPayload = null;
        lastIchimokuPayload = null;
        lastPriceChannelPayload = null;
        lastNrtrPayload = null;
        lastHmaPayload = null;
        lastAroonPayload = null;
        if (priceActionOverlay) priceActionOverlay.innerHTML = '';
        if (liquiditySwingsOverlay) liquiditySwingsOverlay.innerHTML = '';
        if (sweepRaidLiquidityOverlay) sweepRaidLiquidityOverlay.innerHTML = '';
        if (smcFlowOverlay) smcFlowOverlay.innerHTML = '';
        updateChartTitle();
        updateTfOptionStyles();
        tickCandleCloseTimer();
        setLoading(true);
        createChart();

        // Первичная загрузка свечей при выборе инструмента: только initial-окно,
        // чтобы переключение между парами происходило быстро. Остальное
        // (старая история) подтянется по требованию через loadMoreCandles.
        fetchCandles(instrument, { kind: 'initial' })
            .then(data => {
                if (loadSeq !== chartLoadSeq || currentInstrument !== instrument) return;
                const candles = data.candles || [];
                allChartData = buildChartData(candles);
                if (allChartData.length) {
                    oldestLoadedTime = Math.min(...allChartData.map(c => c.time));
                    applyInitialChartPresentation(() => {
                        if (loadSeq !== chartLoadSeq || currentInstrument !== instrument) return;
                        // Poll только после фиксации viewport — иначе setData/preserveRange скачет график.
                        startAutoRefresh();
                        requestAnimationFrame(() => {
                            redrawStructureOverlay();
                            // Клиентские индикаторы сразу; серверные classic — при необходимости.
                            fetchStructures();
                            recomputeClientIndicators();
                            fetchChartTrades();
                            if (anyServerClassicLayerOn()) fetchClassicIndicators();
                        });
                    });
                } else {
                    chartViewportSettling = false;
                    if (refreshInterval) clearInterval(refreshInterval);
                    stopOverlaysRefresh();
                    stopCandleHealLoop();
                    renderChartMarkers();
                    applyClassicOsEngineSeries();
                }
                updateChartTitle();
                tickCandleCloseTimer();
                setLoading(false);
            })
            .catch(() => {
                if (loadSeq !== chartLoadSeq) return;
                chartViewportSettling = false;
                setLoading(false);
                if (refreshInterval) clearInterval(refreshInterval);
                stopOverlaysRefresh();
                tickCandleCloseTimer();
            });
    }

    tfBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.PlatformIndicatorsPopup) window.PlatformIndicatorsPopup.close();
        else layersPanel.classList.add('hidden');
        tfPanel.classList.toggle('hidden');
    });
    tfPanel.addEventListener('click', (e) => e.stopPropagation());
    document.querySelectorAll('.tf-option').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const m = parseInt(btn.dataset.minutes, 10);
            if (!Number.isFinite(m) || m === currentTimeframeMinutes) {
                tfPanel.classList.add('hidden');
                return;
            }
            currentTimeframeMinutes = m;
            saveSelectedTimeframe(m);
            updateTfOptionStyles();
            tfPanel.classList.add('hidden');
            // Смена TF: abort старых classic/structures и т.п., чтобы не нарисовать M5 поверх M1.
            if (window.PlatformChartFetch) window.PlatformChartFetch.invalidateAll();
            if (currentInstrument && candlestickSeries) {
                reloadChartData();
            }
        });
    });

    // Попап «Индикаторы» — Vite indicators-popup.js (не дублируем click).
    document.addEventListener('click', () => {
        tfPanel.classList.add('hidden');
    });

    layerOb.addEventListener('change', () => {
        saveLayerPrefs();
        // Включение слоя — один fetch; выключение — только перерисовка без API.
        if (layerOb.checked) fetchStructures();
        requestAnimationFrame(redrawStructureOverlay);
    });
    layerFvg.addEventListener('change', () => {
        saveLayerPrefs();
        if (layerFvg.checked) fetchStructures();
        requestAnimationFrame(redrawStructureOverlay);
    });
    if (layerPa) {
        layerPa.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerPa.checked) lastPaPayload = null;
            fetchPriceActionIndicator();
            applyPaBarColors({ fullResync: true });
            renderChartMarkers();
            requestAnimationFrame(redrawStructureOverlay);
        });
    }
    if (layerLiquidity) {
        layerLiquidity.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerLiquidity.checked) lastLsPayload = null;
            fetchLiquiditySwingsIndicator();
            requestAnimationFrame(redrawStructureOverlay);
        });
    }
    if (layerSweepRaid) {
        layerSweepRaid.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerSweepRaid.checked) {
                lastSweepRaidPayload = null;
                redrawSweepRaidLiquidityOverlay();
            }
            fetchStructures();
            renderAllIndicatorNameLabels();
        });
    }
    if (layerEngulfing) {
        layerEngulfing.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerEngulfing.checked) {
                lastEngulfingPayload = null;
                redrawEngulfingLiquidityOverlay();
            }
            fetchStructures();
            renderAllIndicatorNameLabels();
        });
    }
    if (layerSmc) {
        layerSmc.addEventListener('change', () => {
            saveLayerPrefs();
            if (!layerSmc.checked) lastSmcPayload = null;
            fetchSmcFlowIndicator();
            requestAnimationFrame(redrawPriceActionOverlay);
        });
    }
    // Classic OsEngine: клиентский subset — локально; остальные — HTTP.
    const clientClassicLayers = new Set([
        layerBollinger, layerRsi, layerSma, layerEma, layerVwma,
        layerAtr, layerMacd, layerStochastic, layerStratAssistant,
    ]);
    [
        [layerBollinger, () => { lastBollingerPayload = null; }],
        [layerRsi, () => { lastRsiPayload = null; }],
        [layerSma, () => { lastSmaPayload = null; }],
        [layerEma, () => { lastEmaPayload = null; }],
        [layerVwma, () => { lastVwmaPayload = null; }],
        [layerAtr, () => { lastAtrPayload = null; }],
        [layerMacd, () => { lastMacdPayload = null; }],
        [layerStochastic, () => { lastStochasticPayload = null; }],
        [layerCci, () => { lastCciPayload = null; }],
        [layerKeltner, () => { lastKeltnerPayload = null; }],
        [layerEnvelops, () => { lastEnvelopsPayload = null; }],
        [layerParabolicSar, () => { lastParabolicSarPayload = null; }],
        [layerSuperTrend, () => { lastSuperTrendPayload = null; }],
        [layerAdx, () => { lastAdxPayload = null; }],
        [layerWilliams, () => { lastWilliamsPayload = null; }],
        [layerAo, () => { lastAoPayload = null; }],
        [layerAlligator, () => { lastAlligatorPayload = null; }],
        [layerIchimoku, () => { lastIchimokuPayload = null; }],
        [layerPriceChannel, () => { lastPriceChannelPayload = null; }],
        [layerNrtr, () => { lastNrtrPayload = null; }],
        [layerHma, () => { lastHmaPayload = null; }],
        [layerAroon, () => { lastAroonPayload = null; }],
        [layerMomentum, () => { lastMomentumPayload = null; }],
        [layerRoc, () => { lastRocPayload = null; }],
        [layerOsma, () => { lastOsmaPayload = null; }],
        [layerTrix, () => { lastTrixPayload = null; }],
        [layerCmo, () => { lastCmoPayload = null; }],
        [layerDemarker, () => { lastDemarkerPayload = null; }],
        [layerRvi, () => { lastRviPayload = null; }],
        [layerUltimate, () => { lastUltimatePayload = null; }],
        [layerSmi, () => { lastSmiPayload = null; }],
        [layerStdDev, () => { lastStdDevPayload = null; }],
        [layerSmaChannel, () => { lastSmaChannelPayload = null; }],
        [layerIvashov, () => { lastIvashovPayload = null; }],
        [layerLinReg, () => { lastLinRegPayload = null; }],
        [layerLinRegChannel, () => { lastLinRegChannelPayload = null; }],
        [layerKalman, () => { lastKalmanPayload = null; }],
        [layerFractal, () => { lastFractalPayload = null; }],
        [layerStratAssistant, () => { /* clear в recompute */ }],
        [layerDonchian, () => { lastDonchianPayload = null; }],
        [layerStochRsi, () => { lastStochRsiPayload = null; }],
        [layerAc, () => { lastAcPayload = null; }],
        [layerBullsPower, () => { lastBullsPowerPayload = null; }],
        [layerBearsPower, () => { lastBearsPowerPayload = null; }],
        [layerDpo, () => { lastDpoPayload = null; }],
        [layerQstick, () => { lastQstickPayload = null; }],
        [layerVhFilter, () => { lastVhFilterPayload = null; }],
        [layerEfficiencyRatio, () => { lastEfficiencyRatioPayload = null; }],
        [layerRavi, () => { lastRaviPayload = null; }],
        [layerCog, () => { lastCogPayload = null; }],
        [layerPivotFloor, () => { lastPivotFloorPayload = null; }],
        [layerMassIndex, () => { lastMassIndexPayload = null; }],
        [layerNrtrWatr, () => { lastNrtrWatrPayload = null; }],
        [layerPriceChannelAdaptive, () => { lastPriceChannelAdaptivePayload = null; }],
        [layerPriceChannelOffset, () => { lastPriceChannelOffsetPayload = null; }],
        [layerParabolicBollinger, () => { lastParabolicBollingerPayload = null; }],
        [layerParabolicPriceChannel, () => { lastParabolicPriceChannelPayload = null; }],
        [layerAtrChannel, () => { lastAtrChannelPayload = null; }],
        [layerOffsetSma, () => { lastOffsetSmaPayload = null; }],
        [layerOffsetEma, () => { lastOffsetEmaPayload = null; }],
        [layerOffsetSsma, () => { lastOffsetSsmaPayload = null; }],
        [layerVolatilityAverage, () => { lastVolatilityAveragePayload = null; }],
        [layerVolatilityAverageChannel, () => { lastVolatilityAverageChannelPayload = null; }],
        [layerPriceOscillator, () => { lastPriceOscillatorPayload = null; }],
        [layerDynamicTrendDetector, () => { lastDynamicTrendDetectorPayload = null; }],
        [layerAdaptiveLookBack, () => { lastAdaptiveLookBackPayload = null; }],
        [layerLinRegChannelFast, () => { lastLinRegChannelFastPayload = null; }],
        [layerVolatilityCandles, () => { lastVolatilityCandlesPayload = null; }],
        [layerZigZag, () => { lastZigZagPayload = null; }],
        [layerVolatilityAverageTwice, () => { lastVolatilityAverageTwicePayload = null; }],
        [layerVolatilityStagesAw, () => { lastVolatilityStagesAwPayload = null; }],
    ].forEach(([el, clearFn]) => {
        if (!el) return;
        el.addEventListener('change', () => {
            saveLayerPrefs();
            if (!el.checked) clearFn();
            if (clientClassicLayers.has(el)) {
                recomputeClientIndicators();
                return;
            }
            fetchClassicIndicators();
        });
    });

    let chartWrapperLastW = 0;
    let chartWrapperLastH = 0;
    new ResizeObserver(() => {
        if (!chart || !chartWrapper.clientWidth) return;
        const w = chartWrapper.clientWidth;
        const h = chartWrapper.clientHeight;
        // Игнорируем «шумовые» resize от соседних панелей — иначе canvas LWC мигает осью времени.
        if (w === chartWrapperLastW && h === chartWrapperLastH) return;
        chartWrapperLastW = w;
        chartWrapperLastH = h;
        chart.resize(w, h);
        scheduleChartDomOverlaySyncLight(true);
        requestAnimationFrame(() => {
            redrawStructureOverlay();
            redrawPriceActionOverlay();
            positionCandleTimerOverlay();
        });
    }).observe(chartWrapper);

    if (instrumentListEl) {
        instrumentListEl.addEventListener('click', (e) => {
            const btn = e.target.closest('.instrument-btn');
            if (!btn) return;
            selectInstrument(btn.dataset.instrument, btn.dataset.displayName);
        });
    }

    if (chartTitle) {
        chartTitle.addEventListener('click', copyCurrentInstrumentName);
    }

    if (instrumentSearchInput) {
        instrumentSearchInput.addEventListener('input', () => {
            applyInstrumentFilter(instrumentSearchInput.value);
            syncSearchClearButton();
        });

        instrumentSearchInput.addEventListener('change', () => {
            const exact = findInstrumentBtnByExactName(instrumentSearchInput.value);
            if (exact) {
                exact.click();
                return;
            }
            const first = applyInstrumentFilter(instrumentSearchInput.value);
            if (first) first.click();
        });

        instrumentSearchInput.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const exact = findInstrumentBtnByExactName(instrumentSearchInput.value);
            if (exact) {
                exact.click();
                return;
            }
            const first = applyInstrumentFilter(instrumentSearchInput.value);
            if (first) first.click();
        });
    }

    if (instrumentSearchClearBtn && instrumentSearchInput) {
        instrumentSearchClearBtn.addEventListener('click', () => {
            instrumentSearchInput.value = '';
            applyInstrumentFilter('');
            syncSearchClearButton();
            instrumentSearchInput.focus();
        });
    }

    updateTfOptionStyles();
    syncSearchClearButton();

    // Переключатель «Торговля» справа в шапке: скрывает сделки на графике и в сайдбаре.
    syncShowTradesToggleUi();
    if (showTradesToggle) {
        showTradesToggle.addEventListener('change', () => {
            applyShowTradesMode(!!showTradesToggle.checked, { persist: true });
        });
    }
    // «Своб. шкала»: вертикальный pan / autoScale. По умолчанию выкл — zoom-out не прячет свечи.
    syncFreePriceScaleToggleUi();
    if (freePriceScaleToggle) {
        freePriceScaleToggle.addEventListener('change', () => {
            applyFreePriceScaleMode(!!freePriceScaleToggle.checked, { persist: true });
        });
    }
    // SSR-список мог прийти с таймерами открытых сделок — сразу прячем, если режим выкл.
    if (!showTrades) {
        hideSidebarTradeChrome();
    }

    // Восстанавливаем ТФ до выбора инструмента, чтобы initial-fetch шёл уже с нужным периодом.
    const persistedTf = loadSelectedTimeframe();
    if (persistedTf != null) {
        currentTimeframeMinutes = persistedTf;
        updateTfOptionStyles();
    }

    // Ранний WS: payout broadcast до выбора инструмента (сайдбар без 5s poll %).
    if (boot.liveEnabled && boot.liveWsUrl && boot.liveToken
        && window.PlatformChartLive && typeof window.PlatformChartLive.create === 'function') {
        if (!chartLiveClient) {
            chartLiveClient = window.PlatformChartLive.create({
                wsUrl: boot.liveWsUrl,
                token: boot.liveToken,
                getInstrument: () => currentInstrument,
                getBarPeriodSec: () => timeframeSeconds(currentTimeframeMinutes),
                getLastBar: () => (allChartData.length ? allChartData[allChartData.length - 1] : null),
                onLiveBar: (bar) => {
                    if (chartViewportSettling || !candlestickSeries || !bar) return;
                    const followTail = isViewNearRightEdge();
                    applyIncrementalChartUpdate([bar], { liveTail: followTail, poll: true });
                    tickCandleCloseTimer();
                    if (followTail) maintainTailViewportIfFollowing();
                },
                onPayout: applyInstrumentPayoutLive,
                onBotEvent: (msg) => {
                    if (typeof window.__dashPoTradesOnBotEvent === 'function') {
                        try { window.__dashPoTradesOnBotEvent(msg); } catch (_) {}
                    }
                    if (typeof window.__dashSidebarOnBotEvent === 'function') {
                        try { window.__dashSidebarOnBotEvent(msg); } catch (_) {}
                    }
                },
                onConnectionChange: (ok) => {
                    if (ok) stopCandleHealLoop();
                    else if (candlestickSeries) startCandleHealLoop();
                },
            });
        }
        chartLiveClient.start();
    }

    const instrumentsPollMs = instrumentsSidebarPollMs();
    if (instrumentsPollMs > 0) {
        instrumentListRefreshInterval = setInterval(
            refreshInstrumentSidebar,
            instrumentsPollMs
        );
    }
    sidebarTimerInterval = setInterval(() => {
        tickSidebarTradeTimers();
        tickCandleCloseTimer();
        // PO/paper-оверлеи — только при включённом режиме «Торговля» (иначе лишний DOM + дёргание).
        if (showTrades) {
            renderPoOpenTradeRaysOverlay();
            schedulePaperTradeDurationOverlay();
        }
    }, 1000);
    tickSidebarTradeTimers();
    // Правая панель CALL/PUT: слушатели + первичный расчёт выплаты.
    initManualTradePanel();
    refreshInstrumentSidebar().then(() => {
        tickCandleCloseTimer();
        // Сначала сохранённая пара, иначе первая доступная в списке.
        const persistedInstr = loadSelectedInstrument();
        let btn = null;
        if (persistedInstr) {
            document.querySelectorAll('#instrument-list .instrument-btn').forEach((b) => {
                if (!btn && b.dataset.instrument === persistedInstr) btn = b;
            });
        }
        if (!btn) btn = document.querySelector('#instrument-list .instrument-btn');
        if (btn) btn.click();
    });

    /**
     * Панель ручного входа на PO (справа от графика).
     * SSID читается только на сервере из /profile/trading; сюда не передаём.
     * Paper+mirror идут в бот «Ручная / Manual» → лента Сделки PO ниже.
     */
    function initManualTradePanel() {
        const panel = document.getElementById('manual-trade-panel');
        if (!panel) return;

        const amountEl = document.getElementById('manual-trade-amount');
        const amountHintEl = document.getElementById('manual-trade-amount-hint');
        const durationEl = document.getElementById('manual-trade-duration');
        const durationPopover = document.getElementById('manual-trade-duration-popover');
        const durHEl = document.getElementById('manual-dur-h');
        const durMEl = document.getElementById('manual-dur-m');
        const durSEl = document.getElementById('manual-dur-s');
        const buyBtn = document.getElementById('manual-trade-buy');
        const sellBtn = document.getElementById('manual-trade-sell');
        const statusEl = document.getElementById('manual-trade-status');
        const settingsBtn = document.getElementById('manual-trade-settings-btn');
        const settingsModal = document.getElementById('manual-trade-settings-modal');
        const flagWaitEl = document.getElementById('manual-flag-wait');
        const flagMgEl = document.getElementById('manual-flag-martingale');
        const flagAmountMgEl = document.getElementById('manual-flag-amount-mg');
        const settingsSaveBtn = document.getElementById('manual-settings-save');
        const settingsStatusEl = document.getElementById('manual-settings-status');

        const ssidReady = panel.dataset.ssidReady === '1';
        const orderUrl = panel.dataset.orderUrl || '';
        const settingsUrl = panel.dataset.settingsUrl || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        // Как на сервере: ManualPocketOrderService::MIN/MAX_DURATION_SEC (S3 = 3с как на PO).
        const DURATION_MIN_SEC = 3;
        const DURATION_MAX_SEC = 43200;
        // Переживают F5 в этом браузере (тумблеры шестерёнки — уже в профиле БД).
        const LS_DURATION_SEC = 'platform.manualTrade.durationSec';
        const LS_AMOUNT = 'platform.manualTrade.amount';

        let placing = false;
        let amountFromMartingale = panel.dataset.flagAmountMg === '1';
        // Текущее значение поповера в секундах (синхронизируется с полем).
        let popoverDurationSec = 300;

        function persistManualDurationSec(sec) {
            try {
                localStorage.setItem(LS_DURATION_SEC, String(clampDurationSec(sec)));
            } catch (_) { /* private mode / quota */ }
        }

        function persistManualAmount(amount) {
            if (amountFromMartingale) return;
            if (!Number.isFinite(amount) || amount < 1) return;
            try {
                localStorage.setItem(LS_AMOUNT, String(amount));
            } catch (_) { /* private mode / quota */ }
        }

        function restoreManualPanelPrefs() {
            try {
                const rawDur = localStorage.getItem(LS_DURATION_SEC);
                if (rawDur != null && rawDur !== '') {
                    const sec = parseInt(rawDur, 10);
                    if (Number.isFinite(sec)) {
                        writeDurationToInput(sec);
                        syncPopoverDigits(sec);
                    }
                }
            } catch (_) { /* ignore */ }

            // Сумму восстанавливаем только если не включён «сумма с шага МГ».
            if (!amountFromMartingale && amountEl) {
                try {
                    const rawAmt = localStorage.getItem(LS_AMOUNT);
                    const amt = rawAmt != null ? parseFloat(rawAmt) : NaN;
                    if (Number.isFinite(amt) && amt >= 1) {
                        amountEl.value = String(amt);
                    }
                } catch (_) { /* ignore */ }
            }
        }

        if (amountEl) {
            amountEl.addEventListener('input', () => {
                updateManualTradePayout();
                const amt = parseFloat(amountEl.value);
                if (Number.isFinite(amt)) persistManualAmount(amt);
            });
            amountEl.addEventListener('change', () => {
                updateManualTradePayout();
                const amt = parseFloat(amountEl.value);
                if (Number.isFinite(amt)) persistManualAmount(amt);
            });
        }

        /** Секунды → HH:MM:SS. */
        function formatDurationClock(totalSec) {
            const s = Math.max(0, Math.floor(Number(totalSec) || 0));
            const hh = Math.floor(s / 3600);
            const mm = Math.floor((s % 3600) / 60);
            const ss = s % 60;
            return String(hh).padStart(2, '0') + ':'
                + String(mm).padStart(2, '0') + ':'
                + String(ss).padStart(2, '0');
        }

        /**
         * Парс MM:SS / HH:MM:SS (+ голые секунды цифрами).
         * @returns {number|null}
         */
        function parseDurationClock(raw) {
            const text = String(raw ?? '').trim();
            if (!text) return null;
            if (/^\d+$/.test(text)) {
                const n = parseInt(text, 10);
                return Number.isFinite(n) ? n : null;
            }
            const parts = text.split(':').map((p) => p.trim());
            if (parts.length < 2 || parts.length > 3) return null;
            if (!parts.every((p) => /^\d{1,2}$/.test(p))) return null;
            const nums = parts.map((p) => parseInt(p, 10));
            let hh = 0, mm = 0, ss = 0;
            if (nums.length === 2) {
                mm = nums[0];
                ss = nums[1];
            } else {
                hh = nums[0];
                mm = nums[1];
                ss = nums[2];
            }
            if (mm > 59 || ss > 59 || hh > 12) return null;
            return hh * 3600 + mm * 60 + ss;
        }

        function clampDurationSec(sec) {
            if (!Number.isFinite(sec)) return DURATION_MIN_SEC;
            return Math.min(DURATION_MAX_SEC, Math.max(DURATION_MIN_SEC, Math.floor(sec)));
        }

        function resolveManualDurationSec() {
            return parseDurationClock(durationEl ? durationEl.value : '');
        }

        function writeDurationToInput(sec) {
            if (!durationEl) return;
            durationEl.value = formatDurationClock(clampDurationSec(sec));
        }

        function syncPopoverDigits(sec) {
            const s = clampDurationSec(sec);
            popoverDurationSec = s;
            const hh = Math.floor(s / 3600);
            const mm = Math.floor((s % 3600) / 60);
            const ss = s % 60;
            if (durHEl) durHEl.textContent = String(hh).padStart(2, '0');
            if (durMEl) durMEl.textContent = String(mm).padStart(2, '0');
            if (durSEl) durSEl.textContent = String(ss).padStart(2, '0');
            // Подсветка активного пресета.
            if (durationPopover) {
                durationPopover.querySelectorAll('[data-dur-preset]').forEach((btn) => {
                    const match = parseInt(btn.getAttribute('data-dur-preset') || '', 10) === s;
                    btn.classList.toggle('ring-1', match);
                    btn.classList.toggle('ring-blue-500', match);
                    btn.classList.toggle('text-white', match);
                    btn.classList.toggle('bg-zinc-700', match);
                });
            }
        }

        function applyDurationSec(sec, { close = false } = {}) {
            const clamped = clampDurationSec(sec);
            writeDurationToInput(clamped);
            syncPopoverDigits(clamped);
            persistManualDurationSec(clamped);
            if (close) closeDurationPopover();
        }

        function positionDurationPopover() {
            if (!durationPopover || !durationEl) return;
            const rect = durationEl.getBoundingClientRect();
            const popW = durationPopover.offsetWidth || 216;
            const popH = durationPopover.offsetHeight || 260;
            // Слева от поля (как на PO); если не влезает — прижимаем к краю.
            let left = rect.left - popW - 10;
            let top = rect.top;
            if (left < 8) left = 8;
            if (top + popH > window.innerHeight - 8) {
                top = Math.max(8, window.innerHeight - popH - 8);
            }
            durationPopover.style.left = left + 'px';
            durationPopover.style.top = top + 'px';
        }

        function isDurationPopoverOpen() {
            return durationPopover && !durationPopover.classList.contains('hidden');
        }

        function openDurationPopover() {
            if (!durationPopover) return;
            const parsed = resolveManualDurationSec();
            const sec = parsed != null ? clampDurationSec(parsed) : 300;
            syncPopoverDigits(sec);
            durationPopover.classList.remove('hidden');
            positionDurationPopover();
        }

        function closeDurationPopover() {
            if (durationPopover) durationPopover.classList.add('hidden');
        }

        if (durationEl) {
            durationEl.addEventListener('focus', () => openDurationPopover());
            durationEl.addEventListener('click', () => openDurationPopover());
            durationEl.addEventListener('blur', () => {
                // Не закрываем сразу: клик по степперам уводит фокус с input.
                const sec = resolveManualDurationSec();
                if (sec != null && sec >= DURATION_MIN_SEC && sec <= DURATION_MAX_SEC) {
                    writeDurationToInput(sec);
                    syncPopoverDigits(sec);
                    persistManualDurationSec(sec);
                }
            });
            durationEl.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    closeDurationPopover();
                    durationEl.blur();
                }
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    const sec = resolveManualDurationSec();
                    if (sec != null) applyDurationSec(sec, { close: true });
                }
            });
        }

        if (durationPopover) {
            durationPopover.addEventListener('mousedown', (ev) => {
                // Не даём input потерять «сессию» до обработки клика по кнопке.
                ev.preventDefault();
            });
            durationPopover.addEventListener('click', (ev) => {
                const stepBtn = ev.target.closest('[data-dur-step]');
                if (stepBtn) {
                    const unit = stepBtn.getAttribute('data-dur-step');
                    const delta = parseInt(stepBtn.getAttribute('data-dur-delta') || '0', 10);
                    let next = popoverDurationSec;
                    if (unit === 'h') next += delta * 3600;
                    else if (unit === 'm') next += delta * 60;
                    else if (unit === 's') next += delta;
                    applyDurationSec(next);
                    return;
                }
                const presetBtn = ev.target.closest('[data-dur-preset]');
                if (presetBtn) {
                    const sec = parseInt(presetBtn.getAttribute('data-dur-preset') || '', 10);
                    if (Number.isFinite(sec)) applyDurationSec(sec, { close: true });
                }
            });
        }

        document.addEventListener('mousedown', (ev) => {
            if (!isDurationPopoverOpen()) return;
            const t = ev.target;
            if (durationPopover && durationPopover.contains(t)) return;
            if (durationEl && (t === durationEl || durationEl.contains(t))) return;
            closeDurationPopover();
        });
        window.addEventListener('resize', () => {
            if (isDurationPopoverOpen()) positionDurationPopover();
        });
        window.addEventListener('scroll', () => {
            if (isDurationPopoverOpen()) positionDurationPopover();
        }, true);

        function applyAmountMode() {
            if (!amountEl) return;
            const mg = parseFloat(panel.dataset.martingaleAmount || '1');
            if (amountFromMartingale) {
                amountEl.value = Number.isFinite(mg) ? String(mg) : '1';
                amountEl.readOnly = true;
                if (amountHintEl) amountHintEl.classList.remove('hidden');
            } else {
                amountEl.readOnly = false;
                if (amountHintEl) amountHintEl.classList.add('hidden');
            }
            updateManualTradePayout();
        }

        function openSettingsModal() {
            if (!settingsModal) return;
            if (flagWaitEl) flagWaitEl.checked = panel.dataset.flagWait === '1';
            if (flagMgEl) flagMgEl.checked = panel.dataset.flagMartingale === '1';
            if (flagAmountMgEl) flagAmountMgEl.checked = panel.dataset.flagAmountMg === '1';
            if (settingsStatusEl) {
                settingsStatusEl.classList.add('hidden');
                settingsStatusEl.textContent = '';
            }
            settingsModal.classList.remove('hidden');
        }

        function closeSettingsModal() {
            if (settingsModal) settingsModal.classList.add('hidden');
        }

        async function saveSettings() {
            if (!settingsUrl || !settingsSaveBtn) return;
            settingsSaveBtn.disabled = true;
            try {
                const res = await fetch(settingsUrl, {
                    method: 'PUT',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        manual_respect_wait_previous: !!(flagWaitEl && flagWaitEl.checked),
                        manual_advance_martingale: !!(flagMgEl && flagMgEl.checked),
                        manual_amount_from_martingale: !!(flagAmountMgEl && flagAmountMgEl.checked),
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    throw new Error(data.message || data.error || ('HTTP ' + res.status));
                }
                panel.dataset.flagWait = data.manual_respect_wait_previous ? '1' : '0';
                panel.dataset.flagMartingale = data.manual_advance_martingale ? '1' : '0';
                panel.dataset.flagAmountMg = data.manual_amount_from_martingale ? '1' : '0';
                if (data.martingale_amount != null) {
                    panel.dataset.martingaleAmount = String(data.martingale_amount);
                }
                amountFromMartingale = !!data.manual_amount_from_martingale;
                applyAmountMode();
                // Вернуть время (и сумму, если не режим МГ) из localStorage.
                restoreManualPanelPrefs();
                closeSettingsModal();
            } catch (e) {
                if (settingsStatusEl) {
                    settingsStatusEl.classList.remove('hidden');
                    settingsStatusEl.className = 'text-[11px] leading-snug text-rose-300';
                    settingsStatusEl.textContent = e && e.message ? e.message : 'Не удалось сохранить';
                }
            } finally {
                settingsSaveBtn.disabled = false;
            }
        }

        if (settingsBtn) settingsBtn.addEventListener('click', openSettingsModal);
        if (settingsSaveBtn) settingsSaveBtn.addEventListener('click', saveSettings);
        if (settingsModal) {
            settingsModal.querySelectorAll('[data-manual-settings-dismiss]').forEach((el) => {
                el.addEventListener('click', closeSettingsModal);
            });
            document.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape' && !settingsModal.classList.contains('hidden')) {
                    closeSettingsModal();
                }
            });
        }

        async function placeSide(side) {
            if (placing) return;
            if (!ssidReady) {
                showManualTradeStatus(false, 'SSID не готов — откройте Профиль → Торговля.');
                return;
            }
            if (!currentInstrument) {
                showManualTradeStatus(false, 'Сначала выберите инструмент слева.');
                return;
            }
            let amount = amountEl ? parseFloat(amountEl.value) : NaN;
            // На всякий случай синхронизируем с шагом МГ, если тумблер включён.
            if (amountFromMartingale) {
                const mg = parseFloat(panel.dataset.martingaleAmount || '');
                if (Number.isFinite(mg) && mg >= 1) amount = mg;
            }
            const duration = resolveManualDurationSec();
            if (!Number.isFinite(amount) || amount < 1) {
                showManualTradeStatus(false, 'Укажите сумму ставки (минимум $1).');
                return;
            }
            if (duration == null || !Number.isFinite(duration)) {
                showManualTradeStatus(false, 'Введите время как MM:SS или HH:MM:SS (например 00:05:00).');
                return;
            }
            if (duration < DURATION_MIN_SEC || duration > DURATION_MAX_SEC) {
                showManualTradeStatus(false, 'Время экспирации: от 00:00:03 до 12:00:00.');
                return;
            }

            placing = true;
            setManualTradeBusy(true);
            closeDurationPopover();
            showManualTradeStatus(null, 'Отправка ордера на Pocket Option…');

            try {
                const res = await fetch(orderUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        instrument: currentInstrument,
                        side: side,
                        amount: amount,
                        duration: duration,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (data.ok) {
                    const oid = data.order_id ? (' #' + data.order_id) : '';
                    const acct = data.is_demo ? 'DEMO' : 'REAL';
                    showManualTradeStatus(true, (side === 'CALL' ? 'Купить' : 'Продать')
                        + ': ордер отправлен (' + acct + ')' + oid + '.');
                    // Сразу обновить ленту Сделки PO (paper+mirror уже в БД).
                    if (typeof window.refreshDashPoTrades === 'function') {
                        window.refreshDashPoTrades();
                    }
                    // Сразу подтянуть open_trades → лучи/маркеры на графике (не ждать poll).
                    if (typeof fetchChartTrades === 'function') {
                        fetchChartTrades();
                    }
                } else {
                    let err = data.error || data.message || '';
                    if (!err && data.errors && typeof data.errors === 'object') {
                        err = Object.values(data.errors).flat().join(' ');
                    }
                    if (!err) err = 'Не удалось открыть сделку (HTTP ' + res.status + ').';
                    showManualTradeStatus(false, err);
                }
            } catch (e) {
                showManualTradeStatus(false, 'Сеть/прокси недоступны: ' + (e && e.message ? e.message : 'ошибка'));
            } finally {
                placing = false;
                setManualTradeBusy(false);
            }
        }

        function setManualTradeBusy(busy) {
            if (buyBtn) buyBtn.disabled = busy || !ssidReady;
            if (sellBtn) sellBtn.disabled = busy || !ssidReady;
            if (amountEl) amountEl.disabled = busy;
            if (durationEl) durationEl.disabled = busy;
        }

        function showManualTradeStatus(ok, message) {
            if (!statusEl) return;
            statusEl.classList.remove('hidden', 'border-emerald-800', 'bg-emerald-950/40', 'text-emerald-300',
                'border-rose-800', 'bg-rose-950/40', 'text-rose-300',
                'border-zinc-700', 'bg-zinc-900/50', 'text-zinc-300');
            if (ok === true) {
                statusEl.classList.add('border-emerald-800', 'bg-emerald-950/40', 'text-emerald-300');
            } else if (ok === false) {
                statusEl.classList.add('border-rose-800', 'bg-rose-950/40', 'text-rose-300');
            } else {
                statusEl.classList.add('border-zinc-700', 'bg-zinc-900/50', 'text-zinc-300');
            }
            statusEl.textContent = message || '';
        }

        if (buyBtn) buyBtn.addEventListener('click', () => placeSide('CALL'));
        if (sellBtn) sellBtn.addEventListener('click', () => placeSide('PUT'));

        applyAmountMode();
        // После дефолтов с сервера — время/сумма с прошлого визита (localStorage).
        restoreManualPanelPrefs();
        updateManualTradePayout();
    }

    /** Пересчёт % и $ выплаты по currentInstrument + сумме в панели. */
    function updateManualTradePayout() {
        const pctEl = document.getElementById('manual-trade-payout-pct');
        const totalEl = document.getElementById('manual-trade-payout-total');
        const profitEl = document.getElementById('manual-trade-profit-usd');
        const amountEl = document.getElementById('manual-trade-amount');
        if (!pctEl || !totalEl || !profitEl) return;

        const amount = amountEl ? parseFloat(amountEl.value) : NaN;
        const amt = Number.isFinite(amount) && amount > 0 ? amount : 0;

        let payout = null;
        if (currentInstrument) {
            const span = document.querySelector(
                '.instrument-payout[data-instrument="' + CSS.escape(currentInstrument) + '"]'
            );
            if (span && span.dataset.payout !== '' && span.dataset.payout != null) {
                const n = parseFloat(span.dataset.payout);
                if (Number.isFinite(n)) payout = n;
            }
        }

        if (payout == null) {
            pctEl.textContent = '—';
            profitEl.textContent = '+$0.00';
            totalEl.textContent = '+$0.00';
            return;
        }

        const profit = amt * (payout / 100);
        const total = amt + profit;
        pctEl.textContent = '+' + Math.round(payout) + '%';
        profitEl.textContent = '+$' + profit.toFixed(2);
        totalEl.textContent = '+$' + total.toFixed(2);
    }

    // -------------------------------------------------------------------------
    // Правая колонка: компактная лента зеркалимых на Pocket Option сделок.
    // Источник: initial GET + полный WS bot_event.trade; HTTP heal редко (live).
    // Клик по карточке переключает график на инструмент сделки (selectInstrument).
    // -------------------------------------------------------------------------
    (function initDashPoTrades() {
        const listEl = document.getElementById('dash-po-trades-list');
        const metaEl = document.getElementById('dash-po-trades-meta');
        if (!listEl || !metaEl || !LIVE_TRADES_URL) return;

        let pollTimer = null;
        let timerTick = null;
        let lastRenderedIds = '';
        /** @type {Map<number, object>} кэш карточек (id → trade), окно ~2ч */
        const tradesById = new Map();
        const myUserId = Number(boot.userId || 0);
        const liveWsOk = !!(boot.liveEnabled && boot.liveWsUrl && boot.liveToken);

        function escapeHtml(s) {
            return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function sideLabel(side) {
            const raw = String(side ?? '').toUpperCase();
            if (raw === 'PUT') return 'ВНИЗ';
            if (raw === 'CALL') return 'ВВЕРХ';
            return side ?? '—';
        }

        function sideTone(side) {
            const raw = String(side ?? '').toUpperCase();
            if (raw === 'CALL') return 'text-emerald-400';
            if (raw === 'PUT') return 'text-rose-400';
            return 'text-zinc-300';
        }

        // Компактный бейдж результата (paper или PO).
        function resultBadge(result, title) {
            const r = String(result ?? '').toLowerCase();
            const tip = title ? ` title="${escapeHtml(title).replace(/"/g, '&quot;')}"` : '';
            if (r === 'win') return `<span class="text-emerald-400"${tip}>win</span>`;
            if (r === 'loss') return `<span class="text-rose-400"${tip}>loss</span>`;
            if (r === 'draw') return `<span class="text-amber-400"${tip}>draw</span>`;
            if (r === 'pending' || r === '') return `<span class="text-zinc-500"${tip}>…</span>`;
            return `<span class="text-zinc-400"${tip}>${escapeHtml(r)}</span>`;
        }

        // Итог на PO: учитываем assume_not_opened / ожидание successcloseOrder.
        function poBadge(t) {
            const src = t.mirror_po_result_source ? String(t.mirror_po_result_source) : '';
            const mirrorStatus = t.mirror_status ? String(t.mirror_status) : '';
            if (src === 'po_assume_not_opened' || mirrorStatus === 'assumed_not_opened') {
                return '<span class="text-amber-400" title="PO: открытие не подтверждено">?</span>';
            }
            if (src === 'paper_fallback') {
                return '<span class="text-amber-400" title="PO-итог не получен">err</span>';
            }
            if (!t.mirror_po_result) {
                if (t.mirror_status === 'executed' && t.status === 'open') {
                    return '<span class="text-zinc-500" title="Ждём итог PO">…</span>';
                }
                return '<span class="text-zinc-600">—</span>';
            }
            return resultBadge(t.mirror_po_result, 'Итог Pocket Option');
        }

        function secondsToClock(total) {
            const s = Math.max(0, Math.floor(total));
            const m = Math.floor(s / 60);
            const r = s % 60;
            return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
        }

        // Таймер до экспирации для open; для закрытых — короткий статус.
        function timeLeftHtml(t) {
            if (t.status !== 'open') {
                return '<span class="text-zinc-600">готово</span>';
            }
            if (!t.expiry_ts) return '<span class="text-zinc-600">—</span>';
            const left = t.expiry_ts - serverUnixNow();
            if (left <= 0) return '<span class="text-amber-400 font-mono">00:00</span>';
            return '<span class="text-cyan-300 font-mono tabular-nums">' + secondsToClock(left) + '</span>';
        }

        // Реально ушло на PO: markMirror('executed') или settlement assumed_not_opened.
        function isMirroredToPo(t) {
            const st = String(t.mirror_status ?? '').toLowerCase();
            return st === 'executed' || st === 'assumed_not_opened';
        }

        function displayNameForInstrument(instrument) {
            const key = String(instrument ?? '');
            if (!key) return key;
            const btn = document.querySelector(
                '.instrument-btn[data-instrument="' + CSS.escape(key) + '"]'
            );
            return (btn && btn.dataset.displayName) ? btn.dataset.displayName : key;
        }

        function tradesListSorted() {
            return Array.from(tradesById.values()).sort((a, b) => {
                const ca = String(a.created_at || a.entry_time || '');
                const cb = String(b.created_at || b.entry_time || '');
                return cb.localeCompare(ca);
            });
        }

        function pruneOldTrades() {
            const cutoffMs = Date.now() - 2 * 3600 * 1000;
            for (const [id, t] of tradesById) {
                const raw = t.created_at || t.entry_time;
                if (!raw) continue;
                const ts = Date.parse(String(raw).replace(' ', 'T') + 'Z');
                if (Number.isFinite(ts) && ts < cutoffMs) tradesById.delete(id);
            }
        }

        function updateMeta() {
            const mirrored = tradesListSorted().filter(isMirroredToPo);
            const openCnt = mirrored.filter((t) => t.status === 'open').length;
            metaEl.textContent = mirrored.length
                ? `${mirrored.length} · open ${openCnt}`
                : 'пусто';
        }

        function renderFromCache() {
            pruneOldTrades();
            renderCards(tradesListSorted());
            updateMeta();
        }

        function renderCards(trades) {
            // Ограничиваем высоту колонки: свежие сверху (API уже latest).
            const mirrored = (trades || []).filter(isMirroredToPo).slice(0, 40);
            const idsKey = mirrored.map((t) =>
                [t.id, t.status, t.paper_result || t.result, t.mirror_po_result || '', t.mirror_status || ''].join(':')
            ).join('|');

            if (idsKey === lastRenderedIds && listEl.querySelector('[data-trade-id]')) {
                tickTimers(listEl);
                return;
            }
            lastRenderedIds = idsKey;

            if (!mirrored.length) {
                listEl.innerHTML = '<div class="px-3 py-6 text-center text-[11px] text-zinc-500">Нет зеркалимых сделок за 2 ч</div>';
                return;
            }

            listEl.innerHTML = mirrored.map((t) => {
                const side = String(t.side ?? '').toUpperCase();
                const isOpen = t.status === 'open';
                const instrument = String(t.instrument ?? '');
                const amount = t.entry_amount != null ? String(t.entry_amount) : '—';
                const bot = escapeHtml(t.bot_name || '—');
                const paper = resultBadge(t.paper_result ?? t.result, 'Paper');
                const po = poBadge(t);
                const ring = isOpen ? 'ring-1 ring-inset ring-sky-800/50' : '';
                return `
                    <button type="button"
                        data-trade-id="${escapeHtml(t.id)}"
                        data-instrument="${escapeHtml(instrument)}"
                        data-expiry-ts="${t.expiry_ts || ''}"
                        data-status="${escapeHtml(t.status || '')}"
                        class="dash-po-trade-card w-full text-left px-3 py-2.5 hover:bg-zinc-800/60 transition ${ring} ${currentInstrument === instrument ? 'bg-blue-950/30' : ''}"
                        title="Открыть ${escapeHtml(instrument)} на графике">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-[12px] font-semibold text-zinc-100">${escapeHtml(instrument)}</p>
                                <p class="mt-0.5 truncate text-[10px] text-zinc-500">${bot}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-[11px] font-semibold tabular-nums ${sideTone(side)}">${sideLabel(side)}</p>
                                <p class="mt-0.5 text-[11px] font-mono tabular-nums text-zinc-200">$${escapeHtml(amount)}</p>
                            </div>
                        </div>
                        <div class="mt-1.5 flex items-center justify-between gap-2 text-[10px]">
                            <span class="dash-po-timer">${timeLeftHtml(t)}</span>
                            <span class="tabular-nums text-zinc-500">P ${paper} · PO ${po}</span>
                        </div>
                    </button>`;
            }).join('');
        }

        function tickTimers(root) {
            root.querySelectorAll('.dash-po-trade-card[data-status="open"]').forEach((card) => {
                const timerEl = card.querySelector('.dash-po-timer');
                if (!timerEl) return;
                const ts = Number(card.dataset.expiryTs || 0);
                if (!ts) {
                    timerEl.innerHTML = '<span class="text-zinc-600">—</span>';
                    return;
                }
                const left = ts - serverUnixNow();
                if (left <= 0) {
                    timerEl.innerHTML = '<span class="text-amber-400 font-mono">00:00</span>';
                    return;
                }
                timerEl.innerHTML = '<span class="text-cyan-300 font-mono tabular-nums">' + secondsToClock(left) + '</span>';
            });
        }

        listEl.addEventListener('click', (ev) => {
            const card = ev.target.closest('.dash-po-trade-card');
            if (!card) return;
            const instrument = card.dataset.instrument;
            if (!instrument) return;
            selectInstrument(instrument, displayNameForInstrument(instrument));
            listEl.querySelectorAll('.dash-po-trade-card').forEach((el) => {
                el.classList.toggle('bg-blue-950/30', el === card);
            });
        });

        function replaceCacheFromServer(all) {
            tradesById.clear();
            (all || []).forEach((t) => {
                const id = Number(t.id);
                if (id) tradesById.set(id, t);
            });
            renderFromCache();
        }

        /**
         * WS bot_event с полной карточкой: upsert в кэш (фильтр по user_id).
         */
        function applyBotTradePush(msg) {
            if (!msg || typeof msg !== 'object') return;
            if (myUserId > 0 && Number(msg.user_id || 0) !== myUserId) return;
            const card = msg.trade;
            if (!card || typeof card !== 'object') return;
            const id = Number(card.id);
            if (!id) return;
            tradesById.set(id, card);
            renderFromCache();
        }

        // Точка входа для PlatformChartLive.onBotEvent (см. create ниже).
        window.__dashPoTradesOnBotEvent = applyBotTradePush;

        async function refreshOnce() {
            if (document.hidden) return;
            try {
                const res = await fetch(LIVE_TRADES_URL, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (window.PlatformServerClock) PlatformServerClock.sync(data.server_time);
                replaceCacheFromServer(data.trades || []);
            } catch (_) {
                metaEl.textContent = 'ошибка…';
            }
        }

        window.refreshDashPoTrades = refreshOnce;

        function stopPoll() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        function startPoll() {
            stopPoll();
            // Live WS: редкий heal; без live — прежний 8с poll.
            let ms;
            if (liveWsOk) {
                ms = Number(boot.liveTradesHealMsLive);
                if (!Number.isFinite(ms) || ms < 0) ms = 60000;
                if (ms === 0) return; // только initial + push
                ms = Math.max(15000, ms);
            } else {
                ms = Math.max(2000, Number(boot.liveTradesPollMs) || 8000);
            }
            pollTimer = setInterval(refreshOnce, ms);
        }

        function syncPollToVisibility() {
            if (document.hidden) {
                stopPoll();
                return;
            }
            startPoll();
            refreshOnce();
        }

        refreshOnce();
        startPoll();
        // Countdown на open-карточках без HTTP poll.
        timerTick = setInterval(() => tickTimers(listEl), 1000);
        document.addEventListener('visibilitychange', syncPollToVisibility);
        window.addEventListener('beforeunload', () => {
            stopPoll();
            if (timerTick) clearInterval(timerTick);
        });
    })();

})();

export {};
