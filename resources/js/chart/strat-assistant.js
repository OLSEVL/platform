/**
 * Strat Assistant — клиентский пересчёт (как TradingView).
 * Зеркало App\Services\Indicators\StratAssistantIndicatorService:
 * типы 1 / 2u / 2d / 3 и комбо 312 / 212C / 212R / 22R / 32.
 * Вход: OHLC-ряды с графика (allChartData), без /indicators/data.
 */
(function attachPlatformStratAssistant(global) {
    if (global.PlatformStratAssistant) return;

    const TYPE_INSIDE = '1';
    const TYPE_TWO_UP = '2u';
    const TYPE_TWO_DOWN = '2d';
    const TYPE_OUTSIDE = '3';

    const DEFAULT_PARAMS = {
        show_types: true,
        show_combos: true,
        color_inside: '#a1a1aa',
        color_two_up: '#22c55e',
        color_two_down: '#ef4444',
        color_outside: '#eab308',
        color_combo_bull: '#38bdf8',
        color_combo_bear: '#f472b6',
    };

    function classifyBar(curr, prev) {
        const high = Number(curr.high);
        const low = Number(curr.low);
        const prevHigh = Number(prev.high);
        const prevLow = Number(prev.low);
        const brokeHigh = high > prevHigh;
        const brokeLow = low < prevLow;
        if (brokeHigh && brokeLow) return TYPE_OUTSIDE;
        if (brokeHigh) return TYPE_TWO_UP;
        if (brokeLow) return TYPE_TWO_DOWN;
        return TYPE_INSIDE;
    }

    /** Приоритет комбо: 312 → 212 → 22R → 32 (одно на закрывающий бар). */
    function comboAt(types, i) {
        const c = types[i];
        const p1 = i >= 1 ? types[i - 1] : null;
        const p2 = i >= 2 ? types[i - 2] : null;

        if (p2 === TYPE_OUTSIDE && p1 === TYPE_INSIDE && c === TYPE_TWO_UP) {
            return { text: '312', dir: 'up' };
        }
        if (p2 === TYPE_OUTSIDE && p1 === TYPE_INSIDE && c === TYPE_TWO_DOWN) {
            return { text: '312', dir: 'down' };
        }
        if (p2 === TYPE_TWO_UP && p1 === TYPE_INSIDE && c === TYPE_TWO_UP) {
            return { text: '212C', dir: 'up' };
        }
        if (p2 === TYPE_TWO_DOWN && p1 === TYPE_INSIDE && c === TYPE_TWO_DOWN) {
            return { text: '212C', dir: 'down' };
        }
        if (p2 === TYPE_TWO_UP && p1 === TYPE_INSIDE && c === TYPE_TWO_DOWN) {
            return { text: '212R', dir: 'down' };
        }
        if (p2 === TYPE_TWO_DOWN && p1 === TYPE_INSIDE && c === TYPE_TWO_UP) {
            return { text: '212R', dir: 'up' };
        }
        if (p1 === TYPE_TWO_UP && c === TYPE_TWO_DOWN) {
            return { text: '22R', dir: 'down' };
        }
        if (p1 === TYPE_TWO_DOWN && c === TYPE_TWO_UP) {
            return { text: '22R', dir: 'up' };
        }
        if (p1 === TYPE_OUTSIDE && c === TYPE_TWO_UP) {
            return { text: '32', dir: 'up' };
        }
        if (p1 === TYPE_OUTSIDE && c === TYPE_TWO_DOWN) {
            return { text: '32', dir: 'down' };
        }
        return null;
    }

    function typeMarker(type, time, p, comboOccupiedOpposite) {
        if (type === TYPE_TWO_UP) {
            return {
                time,
                position: comboOccupiedOpposite ? 'belowBar' : 'aboveBar',
                color: String(p.color_two_up),
                shape: 'arrowUp',
                text: '2',
            };
        }
        if (type === TYPE_TWO_DOWN) {
            return {
                time,
                position: comboOccupiedOpposite ? 'aboveBar' : 'belowBar',
                color: String(p.color_two_down),
                shape: 'arrowDown',
                text: '2',
            };
        }
        if (type === TYPE_OUTSIDE) {
            return {
                time,
                position: comboOccupiedOpposite ? 'belowBar' : 'aboveBar',
                color: String(p.color_outside),
                shape: 'square',
                text: '3',
            };
        }
        return {
            time,
            position: comboOccupiedOpposite ? 'aboveBar' : 'belowBar',
            color: String(p.color_inside),
            shape: 'circle',
            text: '1',
        };
    }

    /**
     * @param {Array<{time:number,high:number,low:number}>} rows
     * @param {object|null} params
     * @returns {{id:string,title:string,markers:object[],meta:object}}
     */
    function compute(rows, params) {
        const p = Object.assign({}, DEFAULT_PARAMS, params || {});
        const n = Array.isArray(rows) ? rows.length : 0;
        const types = new Array(n).fill(null);
        for (let i = 1; i < n; i++) {
            types[i] = classifyBar(rows[i], rows[i - 1]);
        }

        const markers = [];
        let comboCount = 0;
        const showTypes = !!p.show_types;
        const showCombos = !!p.show_combos;

        for (let i = 1; i < n; i++) {
            const type = types[i];
            if (typeof type !== 'string') continue;
            const time = Number(rows[i].time) | 0;
            const combo = showCombos ? comboAt(types, i) : null;
            if (combo) {
                comboCount++;
                const bull = combo.dir === 'up';
                markers.push({
                    time,
                    position: bull ? 'aboveBar' : 'belowBar',
                    color: bull ? String(p.color_combo_bull) : String(p.color_combo_bear),
                    shape: bull ? 'arrowUp' : 'arrowDown',
                    text: combo.text,
                });
            }
            if (!showTypes) continue;
            markers.push(typeMarker(type, time, p, combo !== null));
        }

        return {
            id: 'tv.strat_assistant',
            title: 'Strat Assistant',
            markers,
            meta: {
                bars: n,
                typed: Math.max(0, n - 1),
                combos: comboCount,
            },
        };
    }

    global.PlatformStratAssistant = {
        DEFAULT_PARAMS,
        classifyBar,
        compute,
    };
})(typeof window !== 'undefined' ? window : globalThis);
