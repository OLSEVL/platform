{{--
  Несколько экземпляров классических индикаторов на графике бота.
  Требует: PLATFORM_CHART_INDICATOR_INSTANCES, PLATFORM_CHART_INSTANCE_RENDER_SPECS.
--}}
@php
    use App\Support\ChartIndicatorInstanceRegistry;
    $instanceRenderSpecs = ChartIndicatorInstanceRegistry::renderSpecsForJs();
    $instanceTypeKeys = ChartIndicatorInstanceRegistry::supportedHelpKeys();
@endphp
<script>
(function () {
    const BOT_CHART_INSTANCES_URL = @json(route('bots.chart-instances.index', ['bot' => $bot->id ?? 0]));
    const BOT_CHART_INSTANCES_STORE_URL = @json(route('bots.chart-instances.store', ['bot' => $bot->id ?? 0]));
    const BOT_CHART_INSTANCE_ITEM_URL = @json(url('/bots/'.($bot->id ?? 0).'/chart-instances/__ID__'));
    const INSTANCE_TYPE_KEYS = @json($instanceTypeKeys);
    const RENDER_SPECS = @json($instanceRenderSpecs);

    /** @type {Array<{id:number,type:string,title:string,visible:boolean,settings_url:string}>} */
    let instances = Array.isArray(window.PLATFORM_CHART_INDICATOR_INSTANCES)
        ? window.PLATFORM_CHART_INDICATOR_INSTANCES.slice()
        : [];

    /** id → payload с /indicators/data */
    const instancePayloads = new Map();

    function csrfToken() {
        const el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
    }

    function notifyChartRefresh() {
        window.dispatchEvent(new CustomEvent('platform:chart-instances-changed'));
    }

    function visibleInstanceIds() {
        return instances.filter((i) => i.visible).map((i) => i.id);
    }

    function hasVisibleInstances() {
        return visibleInstanceIds().length > 0;
    }

    function findInstance(id) {
        return instances.find((i) => Number(i.id) === Number(id)) || null;
    }

    function isSupportedType(type) {
        return INSTANCE_TYPE_KEYS.indexOf(type) >= 0;
    }

    function renderInstanceRows() {
        INSTANCE_TYPE_KEYS.forEach(function (type) {
            const box = document.querySelector('[data-chart-instance-list="' + type + '"]');
            if (!box) return;
            box.innerHTML = '';
            instances.filter((i) => i.type === type).forEach(function (inst) {
                const row = document.createElement('div');
                row.className = 'ml-6 mt-1 flex flex-wrap items-center gap-2 rounded border border-zinc-700/80 bg-zinc-900/50 px-2 py-1.5 text-xs';
                row.dataset.instanceId = String(inst.id);

                const label = document.createElement('label');
                label.className = 'flex min-w-0 flex-1 items-center gap-2 cursor-pointer';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'rounded border-zinc-600 bg-zinc-800 text-blue-500 shrink-0';
                cb.checked = !!inst.visible;
                cb.addEventListener('change', function () {
                    toggleVisibility(inst.id, cb.checked);
                });
                const span = document.createElement('span');
                span.className = 'truncate text-zinc-300';
                span.textContent = inst.title || (type.toUpperCase() + ' #' + inst.id);
                label.appendChild(cb);
                label.appendChild(span);

                const gear = document.createElement('a');
                gear.href = inst.settings_url || '#';
                gear.className = 'inline-flex h-7 w-7 items-center justify-center rounded border border-zinc-700 text-zinc-400 hover:bg-zinc-800';
                gear.title = 'Настройки';
                gear.setAttribute('data-indicator-settings-modal', '');
                gear.setAttribute('data-indicator-settings-title', inst.title || type.toUpperCase());
                gear.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>';

                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'inline-flex h-7 w-7 items-center justify-center rounded border border-rose-900/60 text-rose-400 hover:bg-rose-950/50';
                del.title = 'Удалить экземпляр';
                del.textContent = '✕';
                del.addEventListener('click', function () {
                    removeInstance(inst.id);
                });

                row.appendChild(label);
                row.appendChild(gear);
                row.appendChild(del);
                box.appendChild(row);
            });
        });
    }

    function appendFetchParams(params) {
        visibleInstanceIds().forEach(function (id) {
            params.append('instances[]', String(id));
        });
    }

    function applyPayloads(data) {
        instancePayloads.clear();
        const rows = (data && Array.isArray(data.indicator_instances)) ? data.indicator_instances : [];
        rows.forEach(function (row) {
            if (row && row.id != null) {
                instancePayloads.set(Number(row.id), row);
            }
        });
    }

    function clearPayloads() {
        instancePayloads.clear();
    }

    function mapPoints(data) {
        return (Array.isArray(data) ? data : []).map(function (p) {
            return { time: p.time, value: p.value };
        });
    }

    function makeLabel(id, payload) {
        return {
            name: payload.title || ('#' + id),
            color: '#f4f4f5',
            layerId: 'inst-' + id,
            settingsUrl: (findInstance(id) || {}).settings_url || null,
        };
    }

    function collectLabelsByPlacement(placement) {
        const out = [];
        instancePayloads.forEach(function (payload, id) {
            const spec = RENDER_SPECS[payload.type] || {};
            const pl = payload.placement || spec.placement || 'overlay';
            if (pl === placement) {
                out.push(makeLabel(id, payload));
            }
        });
        return out;
    }

    function collectOverlayLabels() {
        return collectLabelsByPlacement('overlay');
    }

    function collectOscillatorLabels() {
        return collectLabelsByPlacement('oscillator');
    }

    /** Рисует один экземпляр на указанной pane. */
    function renderInstancePayload(prefix, payload, ctx) {
        const st = payload.style || {};
        const ser = payload.series || {};
        const render = payload.render || (RENDER_SPECS[payload.type] || {}).render || 'single';
        const w = st.line_width || 1;
        const paneIndex = ctx.paneIndex != null ? ctx.paneIndex : 0;
        const chart = ctx.chart;
        const LineSeries = ctx.LineSeries;
        const HistogramSeries = ctx.HistogramSeries;
        const classicLineSeries = ctx.classicLineSeries || {};
        const addMainLine = ctx.addMainLine;

        const addLine = function (key, data, color, width, opts) {
            if (!Array.isArray(data) || !data.length || !chart || !LineSeries) return null;
            const s = chart.addSeries(LineSeries, Object.assign({
                color: color || '#22c55e',
                lineWidth: width || w,
                priceLineVisible: false,
                lastValueVisible: opts && opts.lastValueVisible != null ? opts.lastValueVisible : false,
                crosshairMarkerVisible: false,
            }, opts || {}), paneIndex);
            s.setData(mapPoints(data));
            classicLineSeries[key] = s;
            return s;
        };

        const addViaMain = function (key, data, color, width) {
            if (typeof addMainLine === 'function') {
                addMainLine(key, data, color, width || w);
                return;
            }
            addLine(key, data, color, width);
        };

        if (render === 'single') {
            const sk = payload.series_key || (RENDER_SPECS[payload.type] || {}).series_key || Object.keys(ser)[0];
            addViaMain(prefix, ser[sk], st.color, w);
            return;
        }

        if (render === 'bands3') {
            addViaMain(prefix + '_up', ser.up, st.color_up, w);
            addViaMain(prefix + '_mid', ser.mid, st.color_mid, w);
            addViaMain(prefix + '_down', ser.down, st.color_down, w);
            return;
        }

        if (render === 'bands2') {
            addViaMain(prefix + '_up', ser.up, st.color_up, w);
            addViaMain(prefix + '_down', ser.down, st.color_down, w);
            return;
        }

        if (render === 'bands3_center') {
            addViaMain(prefix + '_center', ser.center, st.color_center, w);
            addViaMain(prefix + '_up', ser.up, st.color_up, w);
            addViaMain(prefix + '_down', ser.down, st.color_down, w);
            return;
        }

        if (render === 'points') {
            const data = ser[payload.series_key || 'sar'] || ser.sar;
            if (!Array.isArray(data) || !data.length) return;
            const s = chart.addSeries(LineSeries, {
                color: st.color || '#1e90ff',
                lineWidth: 1,
                lineVisible: false,
                pointMarkersVisible: true,
                pointMarkersRadius: st.point_radius || 2,
                priceLineVisible: false,
                lastValueVisible: false,
                crosshairMarkerVisible: false,
            }, paneIndex);
            s.setData(mapPoints(data));
            classicLineSeries[prefix] = s;
            return;
        }

        if (render === 'fractal') {
            ['up', 'down'].forEach(function (side) {
                const data = ser[side];
                if (!Array.isArray(data) || !data.length) return;
                const s = chart.addSeries(LineSeries, {
                    color: st['color_' + side] || st.color || '#00bfff',
                    lineWidth: 1,
                    lineVisible: false,
                    pointMarkersVisible: true,
                    pointMarkersRadius: st.point_radius || 3,
                    priceLineVisible: false,
                    lastValueVisible: false,
                    crosshairMarkerVisible: false,
                }, paneIndex);
                s.setData(mapPoints(data));
                classicLineSeries[prefix + '_' + side] = s;
            });
            return;
        }

        if (render === 'supertrend') {
            addViaMain(prefix + '_center', ser.center, st.color_center, w);
            addViaMain(prefix + '_upper', ser.upper, st.color_upper, w);
            addViaMain(prefix + '_lower', ser.lower, st.color_lower, w);
            return;
        }

        if (render === 'alligator') {
            addViaMain(prefix + '_jaw', ser.jaw, st.color_jaw, w);
            addViaMain(prefix + '_teeth', ser.teeth, st.color_teeth, w);
            addViaMain(prefix + '_lips', ser.lips, st.color_lips, w);
            return;
        }

        if (render === 'ichimoku') {
            addViaMain(prefix + '_tenkan', ser.tenkan, st.color_tenkan, w);
            addViaMain(prefix + '_kijun', ser.kijun, st.color_kijun, w);
            addViaMain(prefix + '_senkou_a', ser.senkou_a, st.color_senkou_a, w);
            addViaMain(prefix + '_senkou_b', ser.senkou_b, st.color_senkou_b, w);
            return;
        }

        // DeMarker — фиксированная шкала 0..1 и уровни overbought/oversold (как legacy layer-demarker).
        if (render === 'demarker') {
            const data = ser.dem || ser[payload.series_key] || [];
            let demLine = null;
            if (Array.isArray(data) && data.length) {
                demLine = addLine(prefix, data, st.color, w, {
                    lastValueVisible: true,
                    baseLineVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                    autoscaleInfoProvider: function () {
                        return { priceRange: { minValue: 0, maxValue: 1 } };
                    },
                });
            }
            const levels = payload.levels || {};
            try {
                if (demLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function'
                    && Array.isArray(ctx.rsiLevelLines)) {
                    ctx.rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(demLine, levels));
                }
            } catch (_) {}
            // Маркеры входов на линии экземпляра DeMarker (Dual DeMarker / strong_trend).
            try {
                const instId = String(prefix).replace(/^inst_/, '');
                const PCM = window.PlatformChartMarkers;
                if (demLine && PCM && typeof PCM.createPluginForSeries === 'function') {
                    if (!window.__demarkerInstanceMarkers) {
                        window.__demarkerInstanceMarkers = new Map();
                    }
                    window.__demarkerInstanceMarkers.set(Number(instId), PCM.createPluginForSeries(demLine, []));
                }
            } catch (_) {}
            return;
        }

        // RSI — шкала 0..100 и уровни (как legacy layer-rsi).
        if (render === 'rsi') {
            const data = ser.rsi || ser[payload.series_key] || [];
            let rsiLine = null;
            if (Array.isArray(data) && data.length) {
                rsiLine = addLine(prefix, data, st.color, w, {
                    lastValueVisible: true,
                    baseLineVisible: false,
                    priceFormat: { type: 'price', precision: 0, minMove: 1 },
                    autoscaleInfoProvider: function () {
                        return { priceRange: { minValue: 0, maxValue: 100 } };
                    },
                });
                try {
                    if (rsiLine && typeof rsiLine.priceScale === 'function') {
                        rsiLine.priceScale().applyOptions({
                            scaleMargins: { top: 0.08, bottom: 0.08 },
                            borderVisible: false,
                            entireTextOnly: true,
                        });
                    }
                } catch (_) {}
            }
            const levels = payload.levels || {};
            try {
                if (rsiLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function'
                    && Array.isArray(ctx.rsiLevelLines)) {
                    ctx.rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(rsiLine, levels));
                }
            } catch (_) {}
            return;
        }

        // CCI — autoscale + уровни и нулевая линия.
        if (render === 'cci') {
            const data = ser.cci || ser[payload.series_key] || [];
            let cciLine = null;
            if (Array.isArray(data) && data.length) {
                cciLine = addLine(prefix, data, st.color, w, {
                    lastValueVisible: true,
                    baseLineVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                });
            }
            const levels = payload.levels || {};
            try {
                if (cciLine && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function'
                    && Array.isArray(ctx.rsiLevelLines)) {
                    ctx.rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(cciLine, levels));
                }
                if (cciLine && typeof cciLine.createPriceLine === 'function' && Array.isArray(ctx.rsiLevelLines)) {
                    ctx.rsiLevelLines.push(cciLine.createPriceLine({
                        price: 0,
                        color: 'rgba(161,161,170,0.55)',
                        lineWidth: 1,
                        lineStyle: 2,
                        axisLabelVisible: true,
                        title: '0',
                    }));
                }
            } catch (_) {}
            return;
        }

        // Williams %R — шкала −100..0.
        if (render === 'williams') {
            const data = ser.wr || ser[payload.series_key] || [];
            let wrLine = null;
            if (Array.isArray(data) && data.length) {
                wrLine = addLine(prefix, data, st.color, w, {
                    lastValueVisible: true,
                    baseLineVisible: false,
                    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                    autoscaleInfoProvider: function () {
                        return { priceRange: { minValue: -100, maxValue: 0 } };
                    },
                });
            }
            const levels = payload.levels || {};
            try {
                if (wrLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function'
                    && Array.isArray(ctx.rsiLevelLines)) {
                    ctx.rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(wrLine, levels));
                }
            } catch (_) {}
            return;
        }

        if (render === 'osc_line') {
            const sk = payload.series_key || Object.keys(ser)[0];
            // series_key в реестре может не совпадать с ключом в payload.series — берём первый массив.
            const data = ser[sk] || ser[Object.keys(ser)[0]];
            addLine(prefix, data, st.color, w, { lastValueVisible: true });
            return;
        }

        if (render === 'stochastic') {
            const kLine = addLine(prefix + '_k', ser.k, st.color_k || st.color, w, {
                lastValueVisible: true,
                priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
                autoscaleInfoProvider: function () {
                    return { priceRange: { minValue: 0, maxValue: 100 } };
                },
            });
            addLine(prefix + '_d', ser.d, st.color_d || '#8b0000', w, { lastValueVisible: false });
            const levels = payload.levels || {};
            try {
                if (kLine && levels.show && window.PlatformChartMarkers
                    && typeof window.PlatformChartMarkers.attachRsiLevelLines === 'function'
                    && Array.isArray(ctx.rsiLevelLines)) {
                    ctx.rsiLevelLines.push(...window.PlatformChartMarkers.attachRsiLevelLines(kLine, levels));
                }
            } catch (_) {}
            return;
        }

        if (render === 'macd') {
            addLine(prefix + '_line', ser.macd, st.color, w, { lastValueVisible: true });
            addLine(prefix + '_signal', ser.signal, st.color_signal || '#ff6347', w, { lastValueVisible: false });
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
                    hs.setData(ser.histogram.map(function (p) {
                        return {
                            time: p.time,
                            value: p.value,
                            color: Number(p.value) >= 0 ? (st.color_histogram || '#006400') : (st.color_histogram_neg || '#f43f5e'),
                        };
                    }));
                    classicLineSeries[prefix + '_hist'] = hs;
                } catch (e) {
                    console.error('[chart-instances] MACD hist', e);
                }
            }
            return;
        }

        if (render === 'adx') {
            addLine(prefix + '_adx', ser.adx, st.color_adx, w, { lastValueVisible: true });
            addLine(prefix + '_plus', ser.plus, st.color_plus, w, { lastValueVisible: false });
            addLine(prefix + '_minus', ser.minus, st.color_minus, w, { lastValueVisible: false });
            return;
        }

        if (render === 'aroon') {
            addLine(prefix + '_up', ser.up, st.color_up, w, { lastValueVisible: true });
            addLine(prefix + '_down', ser.down, st.color_down, w, { lastValueVisible: false });
            return;
        }

        if (render === 'histogram') {
            const sk = payload.series_key || Object.keys(ser)[0];
            const data = ser[sk];
            if (!HistogramSeries || !Array.isArray(data) || !data.length) return;
            try {
                const hs = chart.addSeries(HistogramSeries, {
                    priceLineVisible: false,
                    lastValueVisible: false,
                    baseLineVisible: true,
                    baseLineColor: 'rgba(161,161,170,0.45)',
                    baseLineWidth: 1,
                    baseLineStyle: 2,
                }, paneIndex);
                const pos = st.color_histogram || '#006400';
                const neg = st.color_histogram_neg || '#f43f5e';
                hs.setData(data.map(function (p) {
                    return { time: p.time, value: p.value, color: Number(p.value) >= 0 ? pos : neg };
                }));
                classicLineSeries[prefix + '_hist'] = hs;
            } catch (e) {
                console.error('[chart-instances] histogram', e);
            }
            return;
        }

        if (render === 'ao') {
            if (HistogramSeries && Array.isArray(ser.ao) && ser.ao.length) {
                try {
                    const hs = chart.addSeries(HistogramSeries, {
                        priceLineVisible: false,
                        lastValueVisible: false,
                        baseLineVisible: true,
                        baseLineColor: 'rgba(161,161,170,0.45)',
                    }, paneIndex);
                    hs.setData(ser.ao.map(function (p) {
                        return {
                            time: p.time,
                            value: p.value,
                            color: Number(p.value) >= 0 ? (st.color_up || '#006400') : (st.color_down || '#8b0000'),
                        };
                    }));
                    classicLineSeries[prefix + '_ao'] = hs;
                } catch (e) {
                    console.error('[chart-instances] AO', e);
                }
            }
            return;
        }

        if (render === 'ravi') {
            addLine(prefix + '_ravi', ser.ravi, st.color_ravi, w, { lastValueVisible: true });
            addLine(prefix + '_up', ser.up_line, st.color_up_line, w);
            addLine(prefix + '_down', ser.down_line, st.color_down_line, w);
            return;
        }

        if (render === 'cog') {
            addLine(prefix + '_cog', ser.cog, st.color_cog, w, { lastValueVisible: true });
            addLine(prefix + '_prev', ser.prev_cog, st.color_prev_cog, w);
            return;
        }

        if (render === 'mass_index') {
            addLine(prefix + '_mi', ser.mi, st.color_mi, w, { lastValueVisible: true });
            addLine(prefix + '_lvl_up', ser.level_up, st.color_level_up, w);
            addLine(prefix + '_lvl_down', ser.level_down, st.color_level_down, w);
            return;
        }

        if (render === 'smi') {
            addLine(prefix + '_smi', ser.smi, st.color, w, { lastValueVisible: true });
            addLine(prefix + '_signal', ser.signal, st.color_signal, w);
            return;
        }

        if (render === 'ivashov') {
            addLine(prefix + '_range', ser.range, st.color, w, { lastValueVisible: true });
            addLine(prefix + '_avg', ser.avg, st.color_avg, w);
            return;
        }

        if (render === 'pivot_floor') {
            ['pivot', 'r1', 'r2', 's1', 's2'].forEach(function (k) {
                if (ser[k]) addViaMain(prefix + '_' + k, ser[k], st['color_' + k] || st.color, w);
            });
            return;
        }

        if (render === 'volatility_average_channel' || render === 'volatility_average_twice' || render === 'volatility_stages_aw') {
            Object.keys(ser).forEach(function (k) {
                if (Array.isArray(ser[k]) && ser[k].length) {
                    addLine(prefix + '_' + k, ser[k], st['color_' + k] || st.color, w, { lastValueVisible: k === 'volatility' || k === 'sma_fast' });
                }
            });
            return;
        }

        // Fallback: все массивы series — отдельные линии.
        Object.keys(ser).forEach(function (k) {
            if (Array.isArray(ser[k]) && ser[k].length && typeof ser[k][0] === 'object') {
                addLine(prefix + '_' + k, ser[k], st['color_' + k] || st.color, w);
            }
        });
    }

    /** Линии экземпляров на основной pane (overlay). */
    function renderMainOverlays(addMainLine, chart, LineSeries, classicLineSeries) {
        instancePayloads.forEach(function (payload, id) {
            const spec = RENDER_SPECS[payload.type] || {};
            const placement = payload.placement || spec.placement || 'overlay';
            if (placement !== 'overlay') return;
            renderInstancePayload('inst_' + id, payload, {
                addMainLine: addMainLine,
                chart: chart,
                LineSeries: LineSeries,
                classicLineSeries: classicLineSeries,
                paneIndex: 0,
            });
        });
    }

    /** Экземпляры-осцилляторы — отдельная pane на каждый экземпляр. */
    function renderOscillators(ctx) {
        if (!ctx || !ctx.chart) return;
        instancePayloads.forEach(function (payload, id) {
            const spec = RENDER_SPECS[payload.type] || {};
            const placement = payload.placement || spec.placement || 'overlay';
            if (placement !== 'oscillator') return;
            let paneIndex = 1;
            let pane = null;
            try {
                pane = ctx.chart.addPane(false);
                if (pane && typeof pane.setStretchFactor === 'function') {
                    pane.setStretchFactor(0.28);
                }
                paneIndex = Math.max(1, ctx.chart.panes().length - 1);
                if (ctx.classicOscPaneCount != null) {
                    ctx.classicOscPaneCount += 1;
                }
                // Тёмный фон pane — как у legacy DeMarker/RSI.
                try {
                    if (pane && typeof pane.attachPrimitive === 'function'
                        && window.PlatformChartMarkers
                        && typeof window.PlatformChartMarkers.createPaneSolidBackdrop === 'function') {
                        pane.attachPrimitive(window.PlatformChartMarkers.createPaneSolidBackdrop('#0e1018'));
                    }
                } catch (_) {}
            } catch (e) {
                console.error('[chart-instances] addPane', id, e);
            }
            renderInstancePayload('inst_' + id, payload, Object.assign({}, ctx, { paneIndex: paneIndex, pane: pane }));
        });
    }

    /** @deprecated используйте renderMainOverlays */
    function renderLines(addMainLine) {
        renderMainOverlays(addMainLine);
    }

    async function createInstance(type) {
        if (!isSupportedType(type)) return;
        const res = await fetch(BOT_CHART_INSTANCES_STORE_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ type: type }),
        });
        if (!res.ok) {
            console.error('[chart-instances] create failed', res.status);
            return;
        }
        const data = await res.json();
        if (data && data.instance) {
            instances.push(data.instance);
            renderInstanceRows();
            notifyChartRefresh();
        }
    }

    async function toggleVisibility(id, visible) {
        const inst = findInstance(id);
        if (!inst) return;
        if (!!inst.visible === !!visible) {
            return;
        }
        inst.visible = !!visible;
        const url = BOT_CHART_INSTANCE_ITEM_URL.replace('__ID__', String(id));
        try {
            await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ visible: !!visible }),
            });
        } catch (e) {
            console.error('[chart-instances] visibility', e);
        }
        notifyChartRefresh();
    }

    function setVisibleById(id, visible) {
        const inst = findInstance(id);
        if (!inst) return;
        inst.visible = !!visible;
        const row = document.querySelector('[data-chart-instance-list] [data-instance-id="' + id + '"]');
        const cb = row ? row.querySelector('input[type="checkbox"]') : null;
        if (cb) cb.checked = !!visible;
        toggleVisibility(id, visible);
    }

    async function removeInstance(id) {
        const url = BOT_CHART_INSTANCE_ITEM_URL.replace('__ID__', String(id));
        const res = await fetch(url, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!res.ok) {
            console.error('[chart-instances] delete failed', res.status);
            return;
        }
        instances = instances.filter((i) => Number(i.id) !== Number(id));
        instancePayloads.delete(Number(id));
        renderInstanceRows();
        notifyChartRefresh();
    }

    async function refreshFromServer() {
        try {
            const res = await fetch(BOT_CHART_INSTANCES_URL, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (data && Array.isArray(data.instances)) {
                instances = data.instances;
                window.PLATFORM_CHART_INDICATOR_INSTANCES = instances.slice();
                renderInstanceRows();
            }
        } catch (e) {
            console.error('[chart-instances] refresh', e);
        }
    }

    document.querySelectorAll('[data-chart-instance-add]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const type = btn.getAttribute('data-chart-instance-add');
            if (type && isSupportedType(type)) {
                createInstance(type);
            }
        });
    });

    window.addEventListener('platform:indicator-settings-saved', function () {
        refreshFromServer().then(notifyChartRefresh);
    });

    renderInstanceRows();

    window.PlatformChartIndicatorInstances = {
        visibleInstanceIds,
        hasVisibleInstances,
        appendFetchParams,
        applyPayloads,
        clearPayloads,
        renderLines,
        renderMainOverlays,
        renderOscillators,
        collectOverlayLabels,
        collectOscillatorLabels,
        refreshFromServer,
        setVisibleById,
        forEachPayload: function (cb) {
            instancePayloads.forEach(cb);
        },
        getPayload: function (id) {
            return instancePayloads.get(Number(id)) || null;
        },
    };
})();
</script>
