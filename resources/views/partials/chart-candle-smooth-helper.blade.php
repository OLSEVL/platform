<script>
/**
 * Плавная анимация close последней свечи (как на Pocket Option).
 *
 * Lightweight Charts при series.update() ставит OHLC мгновенно — получается «ступенька».
 * Этот helper держит целевой бар из poll/ingest и на requestAnimationFrame
 * плавно ведёт displayClose → target.close (easing), каждый кадр делая update().
 *
 * high/low расширяются сразу по целевым данным (+ чтобы wick не отрезал анимируемый close).
 * open не трогаем. Смена time бара — мгновенный snap (новый бар без tween).
 */
(function attachPlatformCandleSmooth(global) {
    if (global.PlatformCandleSmooth) {
        return;
    }

    function easeOutCubic(t) {
        const x = Math.min(1, Math.max(0, t));
        return 1 - Math.pow(1 - x, 3);
    }

    /**
     * options.getSeries — () => candlestickSeries
     * options.paintBar — optional (bar) => bar with DGT colors
     * options.durationMs — длина tween (по умолчанию 200)
     */
    function create(options) {
        const getSeries = options && options.getSeries;
        const paintBar = (options && options.paintBar) || ((b) => b);
        const durationMs = Math.max(40, Number(options && options.durationMs) || 200);

        let rafId = null;
        let barTime = null;
        /** Целевой OHLC с сервера. */
        let target = null;
        /** Текущий отображаемый close (между тиками). */
        let displayClose = null;
        let fromClose = null;
        let animStartPerf = 0;

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

        function composeDisplayBar() {
            if (!target) return null;
            const close = displayClose != null ? displayClose : target.close;
            // Wick всегда покрывает и целевые экстремумы, и текущий анимируемый close.
            const high = Math.max(target.high, target.open, close);
            const low = Math.min(target.low, target.open, close);
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
                return true;
            } catch (e) {
                // Не валим график: следующий poll/setData восстановит.
                console.error('[candle-smooth] update', e);
                return false;
            }
        }

        function frame(now) {
            rafId = null;
            if (!target) return;

            const elapsed = now - animStartPerf;
            const t = durationMs > 0 ? elapsed / durationMs : 1;
            const e = easeOutCubic(t);
            const start = fromClose != null ? fromClose : target.close;
            displayClose = start + (target.close - start) * e;
            pushToSeries(composeDisplayBar());

            if (t < 1) {
                rafId = requestAnimationFrame(frame);
            } else {
                // Фиксируем точный target.close (без погрешности float lerp).
                displayClose = target.close;
                pushToSeries({
                    time: target.time,
                    open: target.open,
                    high: target.high,
                    low: target.low,
                    close: target.close,
                });
            }
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
            fromClose = bar.close;
            return pushToSeries(target);
        }

        /**
         * Плавно довести последнюю свечу к новому OHLC.
         * Если time сменился — snap (рождение нового бара).
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

            // Старт lerp от текущего визуального close (не от старого target).
            fromClose = displayClose != null ? displayClose : bar.close;
            // high/low уже в target — сразу расширяем wick на первом кадре.
            animStartPerf = performance.now();

            // Если цена не изменилась — только обновить wick/open без анимации.
            if (Math.abs(fromClose - bar.close) < 1e-12) {
                displayClose = bar.close;
                cancel();
                return pushToSeries(target);
            }

            if (rafId == null) {
                rafId = requestAnimationFrame(frame);
            }
            return true;
        }

        /**
         * Для applyPaBarColors: пока идёт tween последней свечи,
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
            fromClose = null;
        }

        return {
            toward: toward,
            snap: snap,
            cancel: cancel,
            reset: reset,
            visualFor: visualFor,
            isAnimating: function () { return rafId != null; },
        };
    }

    global.PlatformCandleSmooth = { create: create };
})(window);
</script>
