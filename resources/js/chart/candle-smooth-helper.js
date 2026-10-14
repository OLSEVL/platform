/**
 * Плавное отображение close последней свечи (как Binodex / Pocket Option).
 *
 * Lightweight Charts ставит OHLC мгновенно → «ступенька» на каждом тике.
 * Хелпер хранит серверный target и на rAF непрерывно догоняет displayClose
 * экспоненциальным chase (без рестарта timed-tween на каждый тик — иначе
 * easeOut снова рвёт скорость и свеча дёргается).
 *
 * high/low тоже chase'атся, чтобы фитиль не прыгал отдельно от тела.
 * open не трогаем. Смена time бара — мгновенный snap.
 */
(function attachPlatformCandleSmooth(global) {
    if (global.PlatformCandleSmooth) {
        return;
    }

    /**
     * options.getSeries — () => candlestickSeries
     * options.paintBar — optional (bar) => bar with DGT colors
     * options.tauMs — постоянная времени chase (мс); меньше = быстрее догон
     * options.durationMs — устар. алиас tauMs (совместимость create-вызовов)
     * options.maxFps — потолок series.update/сек (PR3; 0 = каждый rAF)
     */
    function create(options) {
        const getSeries = options && options.getSeries;
        const paintBar = (options && options.paintBar) || ((b) => b);
        const tauRaw = options && options.tauMs != null
            ? options.tauMs
            : (options && options.durationMs);
        // ~160мс: успевает «съесть» паузу между WS-тиками (~250мс) без рывка.
        const tauMs = Math.max(40, Number(tauRaw) || 160);
        const maxFps = Math.max(0, Number(options && options.maxFps) || 0);
        const minFrameGapMs = maxFps > 0 ? (1000 / maxFps) : 0;

        let rafId = null;
        let barTime = null;
        /** Целевой OHLC с сервера (истина). */
        let target = null;
        /** Отображаемый OHLC (chase к target). */
        let displayClose = null;
        let displayHigh = null;
        let displayLow = null;
        let lastFramePerf = 0;
        let lastPushPerf = 0;
        /** Колбэк: таймер свечи едет вместе с полоской last price. */
        let onVisualFrame = null;

        function series() {
            try {
                return typeof getSeries === 'function' ? getSeries() : null;
            } catch (_) {
                return null;
            }
        }

        function cancel() {
            if (rafId != null) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }
        }

        function getDisplayClose() {
            if (displayClose != null && Number.isFinite(displayClose)) return displayClose;
            if (target && Number.isFinite(target.close)) return target.close;
            return null;
        }

        function setOnVisualFrame(cb) {
            onVisualFrame = typeof cb === 'function' ? cb : null;
        }

        function notifyVisualFrame() {
            if (!onVisualFrame) return;
            try {
                onVisualFrame(getDisplayClose());
            } catch (e) {
                console.error('[candle-smooth] onVisualFrame', e);
            }
        }

        function composeDisplayBar() {
            if (!target) return null;
            const close = displayClose != null ? displayClose : target.close;
            const highRaw = displayHigh != null ? displayHigh : target.high;
            const lowRaw = displayLow != null ? displayLow : target.low;
            // Wick всегда покрывает open и текущий displayClose.
            const high = Math.max(highRaw, target.open, close);
            const low = Math.min(lowRaw, target.open, close);
            return {
                time: target.time,
                open: target.open,
                high: high,
                low: low,
                close: close,
            };
        }

        function pushToSeries(bar) {
            const s = series();
            if (!s || !bar) return false;
            try {
                s.update(paintBar(bar));
                notifyVisualFrame();
                return true;
            } catch (e) {
                console.error('[candle-smooth] update', e);
                return false;
            }
        }

        /** Порог «догнали» — относительный к цене, чтобы не крутить rAF вечно. */
        function settleEps() {
            const px = Math.abs(Number(target && target.close) || 1);
            return Math.max(1e-10, px * 1e-7);
        }

        function ensureLoop() {
            if (rafId == null) {
                lastFramePerf = performance.now();
                rafId = requestAnimationFrame(frame);
            }
        }

        function frame(now) {
            rafId = null;
            if (!target) return;

            const dt = Math.max(0, Math.min(48, now - (lastFramePerf || now)));
            lastFramePerf = now;
            // Экспоненциальный chase: скорость непрерывна при смене target (в отличие от easeOut-рестарта).
            const alpha = 1 - Math.exp(-dt / tauMs);

            const prevClose = displayClose != null ? displayClose : target.close;
            displayClose = prevClose + (target.close - prevClose) * alpha;

            const wantHigh = Math.max(target.high, target.open, target.close);
            const wantLow = Math.min(target.low, target.open, target.close);
            const prevHigh = displayHigh != null ? displayHigh : wantHigh;
            const prevLow = displayLow != null ? displayLow : wantLow;
            displayHigh = prevHigh + (wantHigh - prevHigh) * alpha;
            displayLow = prevLow + (wantLow - prevLow) * alpha;

            const eps = settleEps();
            const settled =
                Math.abs(target.close - displayClose) <= eps
                && Math.abs(wantHigh - displayHigh) <= eps
                && Math.abs(wantLow - displayLow) <= eps;

            if (settled) {
                displayClose = target.close;
                displayHigh = wantHigh;
                displayLow = wantLow;
                lastPushPerf = now;
                pushToSeries({
                    time: target.time,
                    open: target.open,
                    high: wantHigh,
                    low: wantLow,
                    close: target.close,
                });
                return;
            }

            const duePush = minFrameGapMs <= 0 || (now - lastPushPerf) >= minFrameGapMs;
            if (duePush) {
                lastPushPerf = now;
                pushToSeries(composeDisplayBar());
            } else {
                notifyVisualFrame();
            }

            rafId = requestAnimationFrame(frame);
        }

        /** Мгновенно показать бар (новый бар / fullResync / смена инструмента). */
        function snap(bar) {
            if (!bar || bar.time == null) return false;
            cancel();
            barTime = bar.time;
            target = {
                time: bar.time,
                open: bar.open,
                high: bar.high,
                low: bar.low,
                close: bar.close,
            };
            displayClose = bar.close;
            displayHigh = Math.max(bar.high, bar.open, bar.close);
            displayLow = Math.min(bar.low, bar.open, bar.close);
            return pushToSeries({
                time: bar.time,
                open: bar.open,
                high: displayHigh,
                low: displayLow,
                close: bar.close,
            });
        }

        /**
         * Обновить целевой OHLC. Chase продолжается без рестарта анимации —
         * новый тик только двигает target, display догоняет гладко.
         */
        function toward(bar) {
            if (!bar || bar.time == null) return false;

            if (barTime == null || bar.time !== barTime) {
                return snap(bar);
            }

            target = {
                time: bar.time,
                open: bar.open,
                high: bar.high,
                low: bar.low,
                close: bar.close,
            };

            if (displayClose == null || !Number.isFinite(displayClose)) {
                displayClose = bar.close;
            }
            if (displayHigh == null || !Number.isFinite(displayHigh)) {
                displayHigh = Math.max(bar.high, bar.open, bar.close);
            }
            if (displayLow == null || !Number.isFinite(displayLow)) {
                displayLow = Math.min(bar.low, bar.open, bar.close);
            }

            // Уже на цели — один push wick/open без цикла.
            const eps = settleEps();
            const wantHigh = Math.max(bar.high, bar.open, bar.close);
            const wantLow = Math.min(bar.low, bar.open, bar.close);
            if (
                Math.abs(displayClose - bar.close) < eps
                && Math.abs(displayHigh - wantHigh) < eps
                && Math.abs(displayLow - wantLow) < eps
            ) {
                displayClose = bar.close;
                displayHigh = wantHigh;
                displayLow = wantLow;
                cancel();
                return pushToSeries({
                    time: bar.time,
                    open: bar.open,
                    high: wantHigh,
                    low: wantLow,
                    close: bar.close,
                });
            }

            ensureLoop();
            return true;
        }

        /**
         * Для applyPaBarColors: пока идёт chase последней свечи,
         * не затираем анимируемый close «серверным» значением.
         */
        function visualFor(bar) {
            if (!bar || !target || bar.time !== barTime) {
                return bar;
            }
            const composed = composeDisplayBar();
            return composed || bar;
        }

        function reset() {
            cancel();
            barTime = null;
            target = null;
            displayClose = null;
            displayHigh = null;
            displayLow = null;
        }

        return {
            toward: toward,
            snap: snap,
            cancel: cancel,
            reset: reset,
            visualFor: visualFor,
            getDisplayClose: getDisplayClose,
            setOnVisualFrame: setOnVisualFrame,
            isAnimating: function () { return rafId != null; },
        };
    }

    global.PlatformCandleSmooth = { create: create };
})(window);
