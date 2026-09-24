/**
 * Liquidity Swings — клиентский пересчёт (как TradingView / LuxAlgo).
 * Зеркало App\Services\Indicators\LiquiditySwingsIndicatorService:
 * swing high/low, полупрозрачные зоны, горизонтали и подписи объёма.
 * Вход: OHLC-ряды с графика (allChartData), без /indicators/data.
 *
 * Ограничения относительно Pine (как в PHP):
 * - Intrabar Precision и request.security_lower_tf() не поддерживаются.
 * - Детекция пивотов — симметричное окно (ta.pivothigh / ta.pivotlow).
 */
(function attachPlatformLiquiditySwings(global) {
    if (global.PlatformLiquiditySwings) return;

    /** Дефолты совпадают с inputs Pine (length=14, фильтр Count, порог 0). */
    const DEFAULT_PARAMS = {
        length: 14,
        /** 'wick' = Wick Extremity; 'full' = Full Range */
        area: 'wick',
        /** 'count' | 'volume' — что сравниваем с filter_value для отображения зоны */
        filter_by: 'count',
        filter_value: 0.0,
        show_swing_high: true,
        show_swing_low: true,
        color_high_line: '#ef4444',
        color_high_fill: 'rgba(239,68,68,0.38)',
        color_low_line: '#14b8a6',
        color_low_fill: 'rgba(20,184,166,0.38)',
        /** tiny | small | normal — размер подписей объёма (Labels Size в Pine). */
        label_size: 'tiny',
    };

    function normalizeLabelSize(raw) {
        const s = String(raw || '').toLowerCase().trim();
        return s === 'tiny' || s === 'small' || s === 'normal' ? s : 'tiny';
    }

    /**
     * ta.pivothigh: бар i-len — локальный максимум в окне [i-2*len … i].
     * @param {number[]} high
     * @param {number} i
     * @param {number} len
     */
    function pivotHighConfirmed(high, i, len) {
        const p = i - len;
        const n = high.length;
        if (p < len || p + len >= n) return false;
        const pv = high[p];
        for (let k = p - len; k <= p + len; k++) {
            if (k !== p && high[k] > pv) return false;
        }
        return true;
    }

    /**
     * ta.pivotlow: бар i-len — локальный минимум в окне [i-2*len … i].
     * @param {number[]} low
     * @param {number} i
     * @param {number} len
     */
    function pivotLowConfirmed(low, i, len) {
        const p = i - len;
        const n = low.length;
        if (p < len || p + len >= n) return false;
        const pv = low[p];
        for (let k = p - len; k <= p + len; k++) {
            if (k !== p && low[k] < pv) return false;
        }
        return true;
    }

    /**
     * Финализация бокса: индексы → time_start / time_end.
     * @returns {{time_start:number,time_end:number,price_high:number,price_low:number,fill:string,border:string}}
     */
    function finalizeBox(box, idxTime, fill) {
        const t0 = idxTime(box.leftIdx | 0);
        const t1 = idxTime(box.rightIdx | 0);
        return {
            time_start: Math.min(t0, t1),
            time_end: Math.max(t0, t1),
            price_high: Number(box.price_high),
            price_low: Number(box.price_low),
            fill,
            border: 'none',
        };
    }

    /**
     * Финализация горизонтали: dashed → style Dashed|Solid.
     * @returns {{time_start:number,time_end:number,price:number,color:string,style:string,width:number}}
     */
    function finalizeLine(ln, idxTime, color) {
        const t0 = idxTime(ln.fromIdx | 0);
        const t1 = idxTime(ln.toIdx | 0);
        return {
            time_start: Math.min(t0, t1),
            time_end: Math.max(t0, t1),
            price: Number(ln.price),
            color,
            style: ln.dashed ? 'Dashed' : 'Solid',
            width: 1,
        };
    }

    /**
     * Формат объёма как в PHP formatVolume (B/M/k / целое / 4 знака).
     * number_format(..., 2) + rtrim('0') + rtrim('.').
     */
    function formatVolume(v) {
        const av = Math.abs(v);
        const trim2 = (x) => {
            // Аналог rtrim(rtrim(number_format(x, 2, '.', ''), '0'), '.')
            let s = (Math.round(x * 100) / 100).toFixed(2);
            s = s.replace(/0+$/, '');
            s = s.replace(/\.$/, '');
            return s;
        };
        if (av >= 1e9) return trim2(v / 1e9) + 'B';
        if (av >= 1e6) return trim2(v / 1e6) + 'M';
        if (av >= 1e3) return trim2(v / 1e3) + 'k';
        if (av >= 1) return String(Math.round(v) | 0);
        // sprintf('%.4f') + rtrim zeros/dot
        let s = Number(v).toFixed(4);
        s = s.replace(/0+$/, '');
        s = s.replace(/\.$/, '');
        return s;
    }

    /**
     * @param {Array<{time:number,open:number,high:number,low:number,close:number,volume?:number}>} rows
     * @param {object|null} params
     * @returns {{boxes:object[],lines:object[],labels:object[],label_size?:string}}
     */
    function compute(rows, params) {
        const p = Object.assign({}, DEFAULT_PARAMS, params || {});
        const labelSize = normalizeLabelSize(p.label_size);
        const len = Math.max(1, Number(p.length) | 0);
        const areaWick = String(p.area || 'wick') !== 'full';
        const filterByVolume = String(p.filter_by || 'count') === 'volume';
        const fv = Number(p.filter_value);
        const showTop = !!p.show_swing_high;
        const showBtm = !!p.show_swing_low;
        const cHighLine = String(p.color_high_line);
        const cHighFill = String(p.color_high_fill);
        const cLowLine = String(p.color_low_line);
        const cLowFill = String(p.color_low_fill);

        const n = Array.isArray(rows) ? rows.length : 0;
        // Как в PHP: при коротком ряде без label_size (совпадение JSON).
        if (n < len * 2 + 1) {
            return { boxes: [], lines: [], labels: [] };
        }

        const time = new Array(n);
        const open = new Array(n);
        const high = new Array(n);
        const low = new Array(n);
        const close = new Array(n);
        const volume = new Array(n);
        for (let i = 0; i < n; i++) {
            const r = rows[i];
            time[i] = Math.trunc(Number(r.time));
            open[i] = Number(r.open);
            high[i] = Number(r.high);
            low[i] = Number(r.low);
            close[i] = Number(r.close);
            volume[i] = Number(r.volume != null ? r.volume : 0);
        }

        const boxesOut = [];
        const linesOut = [];
        const labelsOut = [];

        // --- Состояние swing high (как var в Pine) ---
        let phTop = null;
        let phBtm = null;
        let phX1 = 0;
        let phCrossed = false;
        let phCount = 0;
        let phVol = 0.0;
        let phTargetPrev = 0.0;

        let openPhBox = null;
        let openPhLine = null;
        /** @type {{time:number,price:number,text:string,color:string,position:string}|null} */
        let pendingPhLabel = null;

        // --- Состояние swing low ---
        let plTop = null;
        let plBtm = null;
        let plX1 = 0;
        let plCrossed = false;
        let plCount = 0;
        let plVol = 0.0;
        let plTargetPrev = 0.0;

        let openPlBox = null;
        let openPlLine = null;
        /** @type {{time:number,price:number,text:string,color:string,position:string}|null} */
        let pendingPlLabel = null;

        const idxTime = (idx) => {
            const clamped = Math.max(0, Math.min(n - 1, idx | 0));
            // Math.trunc: unix time не обрезать через |0 (знаковый 32-bit).
            return Math.trunc(time[clamped]);
        };

        for (let i = 0; i < n; i++) {
            const ph = showTop && pivotHighConfirmed(high, i, len);
            const pl = showBtm && pivotLowConfirmed(low, i, len);

            // get_counts(ph, ...) — при новом пивоте обнуляем счётчики ДО обновления уровней.
            if (ph) {
                phCount = 0;
                phVol = 0.0;
            } else if (i >= len && phTop !== null && phBtm !== null) {
                const idx = i - len;
                if (idx >= 0 && idx < n) {
                    // Pine: low[length] < top and high[length] > btm
                    if (low[idx] < phTop && high[idx] > phBtm) {
                        phCount++;
                        phVol += volume[idx];
                    }
                }
            }

            if (ph && showTop) {
                const piv = i - len;
                phTop = high[piv];
                phBtm = areaWick ? Math.max(close[piv], open[piv]) : low[piv];
                phX1 = piv;
                phCrossed = false;

                if (openPhBox !== null) {
                    boxesOut.push(finalizeBox(openPhBox, idxTime, cHighFill));
                    openPhBox = null;
                }
                if (openPhLine !== null) {
                    linesOut.push(finalizeLine(openPhLine, idxTime, cHighLine));
                    openPhLine = null;
                }
                if (pendingPhLabel !== null) {
                    labelsOut.push(pendingPhLabel);
                    pendingPhLabel = null;
                }
            } else if (phTop !== null) {
                phCrossed = phCrossed || close[i] > phTop;
            }

            const phTarget = filterByVolume ? phVol : phCount;
            const phCrossUp = phTargetPrev <= fv && phTarget > fv;

            if (showTop && phTop !== null && phBtm !== null) {
                const topPx = Math.max(phTop, phBtm);
                const btmPx = Math.min(phTop, phBtm);

                if (phCrossUp) {
                    openPhBox = {
                        leftIdx: phX1,
                        rightIdx: Math.min(phX1 + phCount, i, n - 1),
                        price_high: topPx,
                        price_low: btmPx,
                        fill: cHighFill,
                    };
                    pendingPhLabel = {
                        time: idxTime(phX1),
                        price: phTop,
                        text: formatVolume(phVol),
                        color: cHighLine,
                        position: 'above',
                    };
                } else if (openPhBox !== null && phTarget > fv) {
                    openPhBox.rightIdx = Math.min(phX1 + phCount, i, n - 1);
                    openPhBox.price_high = topPx;
                    openPhBox.price_low = btmPx;
                }

                if (phTarget > fv) {
                    if (openPhLine === null) {
                        openPhLine = {
                            fromIdx: phX1,
                            toIdx: Math.min(i, n - 1),
                            price: phTop,
                            dashed: phCrossed,
                        };
                    } else {
                        openPhLine.toIdx = Math.min(i, n - 1);
                        openPhLine.price = phTop;
                        openPhLine.dashed = phCrossed;
                    }
                    if (pendingPhLabel !== null) {
                        pendingPhLabel.text = formatVolume(phVol);
                        pendingPhLabel.price = phTop;
                    }
                } else if (openPhLine !== null) {
                    linesOut.push(finalizeLine(openPhLine, idxTime, cHighLine));
                    openPhLine = null;
                }
            }

            phTargetPrev = phTarget;

            // --- Swing low (зеркально) ---
            if (pl) {
                plCount = 0;
                plVol = 0.0;
            } else if (i >= len && plTop !== null && plBtm !== null) {
                const idx = i - len;
                if (idx >= 0 && idx < n) {
                    if (low[idx] < plTop && high[idx] > plBtm) {
                        plCount++;
                        plVol += volume[idx];
                    }
                }
            }

            if (pl && showBtm) {
                const piv = i - len;
                plBtm = low[piv];
                plTop = areaWick ? Math.min(close[piv], open[piv]) : high[piv];
                plX1 = piv;
                plCrossed = false;

                if (openPlBox !== null) {
                    boxesOut.push(finalizeBox(openPlBox, idxTime, cLowFill));
                    openPlBox = null;
                }
                if (openPlLine !== null) {
                    linesOut.push(finalizeLine(openPlLine, idxTime, cLowLine));
                    openPlLine = null;
                }
                if (pendingPlLabel !== null) {
                    labelsOut.push(pendingPlLabel);
                    pendingPlLabel = null;
                }
            } else if (plBtm !== null) {
                plCrossed = plCrossed || close[i] < plBtm;
            }

            const plTarget = filterByVolume ? plVol : plCount;
            const plCrossUp = plTargetPrev <= fv && plTarget > fv;

            if (showBtm && plTop !== null && plBtm !== null) {
                const topPx = Math.max(plTop, plBtm);
                const btmPx = Math.min(plTop, plBtm);

                if (plCrossUp) {
                    openPlBox = {
                        leftIdx: plX1,
                        rightIdx: Math.min(plX1 + plCount, i, n - 1),
                        price_high: topPx,
                        price_low: btmPx,
                        fill: cLowFill,
                    };
                    pendingPlLabel = {
                        time: idxTime(plX1),
                        price: plBtm,
                        text: formatVolume(plVol),
                        color: cLowLine,
                        position: 'below',
                    };
                } else if (openPlBox !== null && plTarget > fv) {
                    openPlBox.rightIdx = Math.min(plX1 + plCount, i, n - 1);
                    openPlBox.price_high = topPx;
                    openPlBox.price_low = btmPx;
                }

                if (plTarget > fv) {
                    if (openPlLine === null) {
                        openPlLine = {
                            fromIdx: plX1,
                            toIdx: Math.min(i, n - 1),
                            price: plBtm,
                            dashed: plCrossed,
                        };
                    } else {
                        openPlLine.toIdx = Math.min(i, n - 1);
                        openPlLine.price = plBtm;
                        openPlLine.dashed = plCrossed;
                    }
                    if (pendingPlLabel !== null) {
                        pendingPlLabel.text = formatVolume(plVol);
                        pendingPlLabel.price = plBtm;
                    }
                } else if (openPlLine !== null) {
                    linesOut.push(finalizeLine(openPlLine, idxTime, cLowLine));
                    openPlLine = null;
                }
            }

            plTargetPrev = plTarget;
        }

        if (openPhBox !== null) boxesOut.push(finalizeBox(openPhBox, idxTime, cHighFill));
        if (openPhLine !== null) linesOut.push(finalizeLine(openPhLine, idxTime, cHighLine));
        if (openPlBox !== null) boxesOut.push(finalizeBox(openPlBox, idxTime, cLowFill));
        if (openPlLine !== null) linesOut.push(finalizeLine(openPlLine, idxTime, cLowLine));
        if (pendingPhLabel !== null) labelsOut.push(pendingPhLabel);
        if (pendingPlLabel !== null) labelsOut.push(pendingPlLabel);

        // Кап как в PHP — не более 320 объектов каждого типа.
        const cap = 320;
        const sliceTail = (arr) => (arr.length > cap ? arr.slice(-cap) : arr);

        return {
            boxes: sliceTail(boxesOut),
            lines: sliceTail(linesOut),
            labels: sliceTail(labelsOut),
            label_size: labelSize,
        };
    }

    global.PlatformLiquiditySwings = {
        DEFAULT_PARAMS,
        compute,
    };
})(typeof window !== 'undefined' ? window : globalThis);
