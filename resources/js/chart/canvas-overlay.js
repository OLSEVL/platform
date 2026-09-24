/**
 * Общий Canvas2D-оверлей для DOM-тяжёлых слоёв графика.
 *
 * Цель: как Binodex — один canvas на слой, clear+stroke на zoom/pan,
 * без innerHTML='' и сотен createElement (OB/FVG, PA/VP, Liquidity, LLM).
 *
 * API: PlatformChartCanvasOverlay.{ begin, paintStructure, paintLiquidity, paintPriceAction, paintLlmLevels, clear }
 */
(function attachPlatformChartCanvasOverlay(global) {
    if (global.PlatformChartCanvasOverlay) {
        return;
    }

    function cssColor(raw, fallback) {
        const s = String(raw || '').trim();
        if (!s) return fallback || 'rgba(255,255,255,0.8)';
        if (s.startsWith('#') || s.startsWith('rgb') || s.startsWith('hsl')) return s;
        return fallback || s;
    }

    /** Разбор "1px solid rgba(...)" / "1px dashed #abc" → { width, style, color }. */
    function parseBorder(raw) {
        const s = String(raw || '').trim();
        if (!s || s === 'none') return null;
        const m = s.match(/^(\d+(?:\.\d+)?)px\s+(solid|dashed|dotted)\s+(.+)$/i);
        if (!m) return { width: 1, style: 'solid', color: s };
        return { width: Number(m[1]) || 1, style: m[2].toLowerCase(), color: m[3].trim() };
    }

    function ensureCanvas(host, dataKey) {
        const key = dataKey || 'chart-overlay-canvas';
        let canvas = host.querySelector('canvas[data-' + key + '="1"]');
        if (!canvas) {
            // data-chart-overlay-canvas → dataset.chartOverlayCanvas
            host.replaceChildren();
            canvas = document.createElement('canvas');
            canvas.setAttribute('data-' + key, '1');
            canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;display:block;';
            host.appendChild(canvas);
        }
        return canvas;
    }

    function fitCanvas(canvas, cssW, cssH) {
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        const w = Math.max(1, Math.floor(cssW * dpr));
        const h = Math.max(1, Math.floor(cssH * dpr));
        if (canvas.width !== w || canvas.height !== h) {
            canvas.width = w;
            canvas.height = h;
        }
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        return ctx;
    }

    /**
     * Подготовить ctx для host. enabled=false → очистить и вернуть null.
     * @returns {{ ctx: CanvasRenderingContext2D, w: number, h: number }|null}
     */
    function begin(host, api, dataKey) {
        if (!host) return null;
        if (!api || !api.enabled) {
            host.replaceChildren();
            return null;
        }
        const w = Math.max(1, Number(api.viewportW) || host.clientWidth || 1);
        const h = Math.max(1, Number(api.viewportH) || host.clientHeight || 1);
        const canvas = ensureCanvas(host, dataKey || 'chart-overlay-canvas');
        const ctx = fitCanvas(canvas, w, h);
        ctx.clearRect(0, 0, w, h);
        return { ctx, w, h };
    }

    function clear(host) {
        if (host) host.replaceChildren();
    }

    function strokeSegment(ctx, x1, y1, x2, y2, color, width, style) {
        if (![x1, y1, x2, y2].every(Number.isFinite)) return;
        const st = String(style || 'solid').toLowerCase();
        ctx.beginPath();
        ctx.strokeStyle = color;
        ctx.lineWidth = Math.max(1, Number(width) || 1);
        ctx.lineCap = 'round';
        if (st === 'dotted') {
            ctx.setLineDash([1, Math.max(2, ctx.lineWidth * 2)]);
        } else if (st === 'dashed') {
            ctx.setLineDash([Math.max(4, ctx.lineWidth * 3), Math.max(3, ctx.lineWidth * 2)]);
        } else {
            ctx.setLineDash([]);
        }
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();
        ctx.setLineDash([]);
    }

    function fillZone(ctx, x1, y1, x2, y2, fill, borderRaw) {
        if (![x1, y1, x2, y2].every(Number.isFinite)) return;
        if (Math.abs(x2 - x1) < 1 && Math.abs(y2 - y1) < 1) return;
        const left = Math.min(x1, x2);
        const top = Math.min(y1, y2);
        const ww = Math.max(1, Math.abs(x2 - x1));
        const hh = Math.max(1, Math.abs(y2 - y1));
        if (fill && fill !== 'transparent') {
            ctx.fillStyle = cssColor(fill);
            ctx.fillRect(left, top, ww, hh);
        }
        const b = parseBorder(borderRaw);
        if (b) {
            strokeSegment(ctx, left, top, left + ww, top, b.color, b.width, b.style);
            strokeSegment(ctx, left, top + hh, left + ww, top + hh, b.color, b.width, b.style);
            strokeSegment(ctx, left, top, left, top + hh, b.color, b.width, b.style);
            strokeSegment(ctx, left + ww, top, left + ww, top + hh, b.color, b.width, b.style);
        }
    }

    function drawLabel(ctx, text, x, y, opts) {
        const t = String(text || '');
        if (!t || ![x, y].every(Number.isFinite)) return;
        const o = opts || {};
        const fontSize = o.fontSize || 11;
        ctx.font = `${o.fontWeight || '600'} ${fontSize}px ui-sans-serif, system-ui, sans-serif`;
        ctx.textBaseline = 'middle';
        const metrics = ctx.measureText(t);
        const tw = metrics.width;
        const th = fontSize + 4;
        let ax = x;
        let ay = y;
        const pos = String(o.position || '');
        if (pos === 'below') {
            ay = y + th / 2 + 6;
            ctx.textAlign = 'center';
        } else if (pos === 'left') {
            ax = x + 8;
            ctx.textAlign = 'left';
        } else if (pos === 'right') {
            ctx.textAlign = 'right';
        } else if (pos === 'zone') {
            ax = x + 4;
            ay = y + th / 2 + 2;
            ctx.textAlign = 'left';
        } else {
            ay = y - th / 2 - 4;
            ctx.textAlign = 'center';
        }
        if (o.background) {
            const padX = 5;
            const padY = 2;
            let bx = ax;
            if (ctx.textAlign === 'center') bx = ax - tw / 2;
            else if (ctx.textAlign === 'right') bx = ax - tw;
            ctx.fillStyle = o.background;
            ctx.beginPath();
            if (typeof ctx.roundRect === 'function') {
                ctx.roundRect(bx - padX, ay - th / 2 - padY, tw + padX * 2, th + padY * 2, 3);
            } else {
                ctx.rect(bx - padX, ay - th / 2 - padY, tw + padX * 2, th + padY * 2);
            }
            ctx.fill();
        }
        if (o.shadow) {
            ctx.shadowColor = 'rgba(0,0,0,0.9)';
            ctx.shadowBlur = 3;
        }
        ctx.fillStyle = o.color || '#fff';
        ctx.fillText(t, ax, ay);
        ctx.shadowBlur = 0;
    }

    const ZONE_PAINT = {
        order_block_bull: { background: 'rgba(33, 87, 243, 0.2)', border: '1px solid rgba(33, 87, 243, 0.85)' },
        order_block_bull_break: { background: 'rgba(255, 17, 0, 0.2)', border: '1px dashed rgba(255, 17, 0, 0.85)' },
        order_block_bear: { background: 'rgba(255, 93, 0, 0.2)', border: '1px solid rgba(255, 93, 0, 0.85)' },
        order_block_bear_break: { background: 'rgba(12, 181, 26, 0.2)', border: '1px dashed rgba(12, 181, 26, 0.85)' },
        imbalance_bull: { background: 'rgba(14, 165, 233, 0.2)', border: '1px solid rgba(2, 132, 199, 0.45)' },
        imbalance_bear: { background: 'rgba(14, 165, 233, 0.2)', border: '1px solid rgba(2, 132, 199, 0.45)' },
        default: { background: 'rgba(156, 163, 175, 0.15)', border: '1px solid rgba(107, 114, 128, 0.35)' },
    };

    /**
     * OB / FVG зоны.
     * api: { enabled, viewportW/H, timeToCoordinate, priceToCoordinate, zoneEndX, lastBarTime, showOb, showFvg }
     */
    function paintStructure(host, zones, api) {
        const session = begin(host, api, 'structure-canvas');
        if (!session) return { nodes: 0, drawn: 0 };
        const { ctx, w } = session;
        const list = Array.isArray(zones) ? zones : [];
        const lastBarTime = Number(api.lastBarTime) || 0;
        const showOb = api.showOb !== false;
        const showFvg = api.showFvg !== false;
        const t2x = api.timeToCoordinate;
        const p2y = api.priceToCoordinate;
        const zoneEndX = api.zoneEndX || t2x;
        const pad = 80;
        let drawn = 0;
        for (let i = 0; i < list.length; i++) {
            const z = list[i];
            if (!z || typeof z !== 'object') continue;
            const typ = String(z.type || '');
            if (typ.startsWith('order_block') && !showOb) continue;
            if (typ.startsWith('imbalance') && !showFvg) continue;
            if (lastBarTime && lastBarTime < Number(z.time_start)) continue;
            const tEnd = z.time_end != null ? Number(z.time_end) : lastBarTime;
            const x1 = t2x(Number(z.time_start));
            const x2 = zoneEndX(tEnd);
            const y1 = p2y(Number(z.price_high));
            const y2 = p2y(Number(z.price_low));
            if (![x1, x2, y1, y2].every(Number.isFinite)) continue;
            if (Math.abs(x2 - x1) < 1) continue;
            if (Math.max(x1, x2) < -pad || Math.min(x1, x2) > w + pad) continue;
            const paint = ZONE_PAINT[typ] || ZONE_PAINT.default;
            fillZone(ctx, x1, y1, x2, y2, z.fill || paint.background, z.border || paint.border);
            if (z.label && typ.startsWith('order_block')) {
                drawLabel(ctx, z.label, Math.min(x1, x2), Math.min(y1, y2), {
                    position: 'zone',
                    color: z.label_color || '#f4f4f5',
                    fontSize: 10,
                    fontWeight: '600',
                    shadow: true,
                });
            }
            drawn++;
        }
        return { nodes: 1, drawn };
    }

    /**
     * Liquidity Swings / SweepRaid / Engulfing — boxes + horiz lines + labels.
     */
    function paintLiquidity(host, payload, api) {
        const session = begin(host, api, 'liquidity-canvas');
        if (!session) return { nodes: 0, drawn: 0 };
        const { ctx, w } = session;
        if (!payload || typeof payload !== 'object') return { nodes: 1, drawn: 0 };
        const t2x = api.timeToCoordinate;
        const p2y = api.priceToCoordinate;
        const lastBarTime = Number(api.lastBarTime) || 0;
        const pad = 80;
        let drawn = 0;

        const boxes = Array.isArray(payload.boxes) ? payload.boxes : [];
        for (let i = 0; i < boxes.length; i++) {
            const z = boxes[i];
            if (!z || lastBarTime < Number(z.time_start)) continue;
            const tEnd = Math.min(lastBarTime, Number(z.time_end));
            const x1 = t2x(Number(z.time_start));
            const x2 = t2x(tEnd);
            const y1 = p2y(Number(z.price_high));
            const y2 = p2y(Number(z.price_low));
            if (![x1, x2, y1, y2].every(Number.isFinite)) continue;
            if (Math.max(x1, x2) < -pad || Math.min(x1, x2) > w + pad) continue;
            fillZone(ctx, x1, y1, x2, y2, z.fill || 'rgba(239,68,68,0.25)', z.border);
            drawn++;
        }

        const lines = Array.isArray(payload.lines) ? payload.lines : [];
        for (let i = 0; i < lines.length; i++) {
            const ln = lines[i];
            if (!ln || typeof ln !== 'object') continue;
            const x1 = t2x(Number(ln.time_start));
            const x2 = t2x(Number(ln.time_end));
            const y = p2y(Number(ln.price));
            if (![x1, x2, y].every(Number.isFinite)) continue;
            if (Math.max(x1, x2) < -pad || Math.min(x1, x2) > w + pad) continue;
            strokeSegment(
                ctx, x1, y, x2, y,
                cssColor(ln.color, '#ef4444'),
                ln.width || 1,
                ln.style,
            );
            drawn++;
        }

        const labels = Array.isArray(payload.labels) ? payload.labels : [];
        const labelSize = payload.label_size;
        let fontSize = 9;
        if (labelSize === 'small') fontSize = 11;
        else if (labelSize === 'normal') fontSize = 13;
        for (let i = 0; i < labels.length; i++) {
            const lb = labels[i];
            if (!lb || typeof lb !== 'object') continue;
            const x = t2x(Number(lb.time));
            const y = p2y(Number(lb.price));
            if (![x, y].every(Number.isFinite)) continue;
            if (x < -80 || x > w + 80) continue;
            drawLabel(ctx, lb.text, x, y, {
                position: lb.position,
                color: cssColor(lb.color, '#fff'),
                fontSize,
                fontWeight: '600',
            });
            drawn++;
        }
        return { nodes: 1, drawn };
    }

    /**
     * Price Action DGT: horiz lines + volume profile bars.
     * api.priceScaleWidth — ширина шкалы цен для якоря VP.
     */
    function paintPriceAction(host, payload, api) {
        const session = begin(host, api, 'pa-canvas');
        if (!session) return { nodes: 0, drawn: 0 };
        const { ctx, w } = session;
        if (!payload || typeof payload !== 'object') return { nodes: 1, drawn: 0 };
        const t2x = api.timeToCoordinate;
        const p2y = api.priceToCoordinate;
        const pad = 80;
        let drawn = 0;

        const lines = Array.isArray(payload.lines) ? payload.lines : [];
        for (let i = 0; i < lines.length; i++) {
            const ln = lines[i];
            if (!ln || typeof ln !== 'object') continue;
            const x1 = t2x(Number(ln.time_start));
            const x2 = t2x(Number(ln.time_end));
            const y = p2y(Number(ln.price));
            if (![x1, x2, y].every(Number.isFinite)) continue;
            if (Math.max(x1, x2) < -pad || Math.min(x1, x2) > w + pad) continue;
            strokeSegment(ctx, x1, y, x2, y, cssColor(ln.color, '#94a3b8'), ln.width || 1, ln.style);
            drawn++;
        }

        const vp = payload.volume_profile || {};
        const boxes = Array.isArray(vp.boxes) ? vp.boxes : [];
        const vpPanelMaxHistPx = 100;
        const vpPanelMaxDomPx = 26;
        const vpPanelGapPx = 2;
        let scaleW = Number(api.priceScaleWidth);
        if (!Number.isFinite(scaleW) || scaleW < 0) scaleW = 0;
        const effectiveScale = scaleW > 0 ? scaleW : 78;
        const anchorRight = w - effectiveScale - 1;
        if (!(anchorRight > 8)) {
            return { nodes: 1, drawn };
        }

        let boxCount = 0;
        let vpDividerY1 = null;
        let vpDividerY2 = null;

        for (let i = 0; i < boxes.length; i++) {
            if (boxCount > 400) break;
            const b = boxes[i];
            if (!b || typeof b !== 'object') continue;

            if (b.kind === 'vp_hist' && boxes[i + 1] && boxes[i + 1].kind === 'vp_dom') {
                const domBox = boxes[i + 1];
                const y1 = p2y(Number(b.price_high));
                const y2 = p2y(Number(b.price_low));
                if (![y1, y2].every(Number.isFinite)) {
                    i += 1;
                    continue;
                }
                const wrH = typeof b.width_ratio === 'number' ? b.width_ratio : 0.5;
                const spanHist = Math.max(8, Math.min(vpPanelMaxHistPx, wrH * (vpPanelMaxHistPx + 8)));
                const sr = typeof domBox.strength_ratio === 'number' ? domBox.strength_ratio : 0;
                const spanDom = Math.max(4, Math.min(vpPanelMaxDomPx, 5 + sr * (vpPanelMaxDomPx - 2)));
                const top = Math.min(y1, y2);
                const hh = Math.max(1, Math.abs(y2 - y1));
                const leftDom = anchorRight - spanDom;
                const leftHist = leftDom - vpPanelGapPx - spanHist;
                ctx.globalAlpha = 0.78;
                ctx.fillStyle = cssColor(b.background, 'rgba(59,130,246,0.5)');
                ctx.fillRect(leftHist, top, spanHist, hh);
                ctx.globalAlpha = 0.88;
                ctx.fillStyle = cssColor(domBox.background, 'rgba(239,68,68,0.5)');
                ctx.fillRect(leftDom, top, spanDom, hh);
                ctx.globalAlpha = 1;
                if (vpDividerY1 == null) {
                    vpDividerY1 = top;
                    vpDividerY2 = top + hh;
                } else {
                    vpDividerY1 = Math.min(vpDividerY1, top);
                    vpDividerY2 = Math.max(vpDividerY2, top + hh);
                }
                boxCount += 2;
                drawn += 2;
                i += 1;
                continue;
            }

            if (b.kind === 'vp_hist' || b.kind === 'vp_dom') {
                const y1 = p2y(Number(b.price_high));
                const y2 = p2y(Number(b.price_low));
                if (![y1, y2].every(Number.isFinite)) continue;
                const wr = typeof b.width_ratio === 'number' ? b.width_ratio : 0.5;
                const spanMax = b.kind === 'vp_dom' ? vpPanelMaxDomPx : vpPanelMaxHistPx;
                const span = Math.max(6, Math.min(spanMax, wr * (spanMax + 6)));
                const top = Math.min(y1, y2);
                const hh = Math.max(1, Math.abs(y2 - y1));
                ctx.globalAlpha = b.kind === 'vp_dom' ? 0.88 : 0.78;
                ctx.fillStyle = cssColor(b.background, 'rgba(59,130,246,0.5)');
                ctx.fillRect(anchorRight - span, top, span, hh);
                ctx.globalAlpha = 1;
                boxCount += 1;
                drawn++;
                continue;
            }

            const x1 = t2x(Number(b.time_start));
            const x2 = t2x(Number(b.time_end));
            const yTop = p2y(Number(b.price_high));
            const yBot = p2y(Number(b.price_low));
            if (![x1, x2, yTop, yBot].every(Number.isFinite)) continue;

            if (b.kind === 'vp_frame') {
                const panelW = vpPanelMaxHistPx + vpPanelGapPx + vpPanelMaxDomPx + 4;
                const yA = Math.min(yTop, yBot);
                const yB = Math.max(yTop, yBot);
                fillZone(
                    ctx,
                    anchorRight - panelW,
                    yA,
                    anchorRight,
                    yB,
                    'transparent',
                    '1px dashed ' + (b.border || 'rgba(156,163,175,0.35)'),
                );
            } else {
                fillZone(
                    ctx, x1, yTop, x2, yBot,
                    b.background,
                    '1px solid rgba(255,255,255,0.08)',
                );
            }
            boxCount++;
            drawn++;
        }

        if (vpDividerY1 != null && vpDividerY2 != null && vpDividerY2 > vpDividerY1) {
            const splitX = anchorRight - vpPanelMaxDomPx - vpPanelGapPx * 0.5;
            strokeSegment(ctx, splitX, vpDividerY1, splitX, vpDividerY2, 'rgba(226,232,240,0.4)', 1, 'solid');
        }
        return { nodes: 1, drawn };
    }

    function llmZoneFill(timeframe) {
        const tf = String(timeframe || '').toUpperCase();
        if (tf === 'H1') return 'rgba(168, 85, 247, 0.18)';
        if (tf === 'M15') return 'rgba(59, 130, 246, 0.16)';
        if (tf === 'M5') return 'rgba(34, 197, 94, 0.14)';
        if (tf === 'M1') return 'rgba(245, 158, 11, 0.14)';
        return 'rgba(148, 163, 184, 0.14)';
    }

    /** LLM analyst levels/zones — full-width bands + labels. */
    function paintLlmLevels(host, payload, api) {
        const session = begin(host, api, 'llm-levels-canvas');
        if (!session) return { nodes: 0, drawn: 0 };
        const { ctx, w, h } = session;
        if (!payload || typeof payload !== 'object') return { nodes: 1, drawn: 0 };
        const p2y = api.priceToCoordinate;
        let drawn = 0;
        const labelLanes = new Map();

        const zones = Array.isArray(payload.zones) ? payload.zones : [];
        for (let i = 0; i < zones.length; i++) {
            const z = zones[i];
            if (!z || typeof z !== 'object') continue;
            const y1 = p2y(Number(z.price_high));
            const y2 = p2y(Number(z.price_low));
            if (![y1, y2].every(Number.isFinite)) continue;
            fillZone(ctx, 0, y1, w, y2, llmZoneFill(z.timeframe), null);
            strokeSegment(ctx, 0, Math.min(y1, y2), w, Math.min(y1, y2), 'rgba(148,163,184,0.35)', 1, 'dashed');
            strokeSegment(ctx, 0, Math.max(y1, y2), w, Math.max(y1, y2), 'rgba(148,163,184,0.35)', 1, 'dashed');
            if (z.label) {
                const mid = (y1 + y2) / 2;
                const bucket = Math.round(mid / 14);
                let top = Math.max(2, Math.min(h - 14, mid));
                const prev = labelLanes.get(bucket);
                if (prev != null && Math.abs(prev - top) < 12) top = Math.max(2, top - 12);
                labelLanes.set(bucket, top);
                drawLabel(ctx, z.label, w - 6, top, {
                    position: 'right',
                    color: '#cbd5e1',
                    background: 'rgba(9,9,11,0.72)',
                    fontSize: 10,
                });
            }
            drawn++;
        }

        const levels = Array.isArray(payload.levels) ? payload.levels : [];
        for (let i = 0; i < levels.length; i++) {
            const ln = levels[i];
            if (!ln || typeof ln !== 'object') continue;
            const y = p2y(Number(ln.price));
            if (!Number.isFinite(y)) continue;
            const kind = String(ln.kind || '').toLowerCase();
            let color = ln.color;
            if (!color) {
                if (kind === 'pivot') color = '#fbbf24';
                else if (kind === 'support') color = '#22c55e';
                else if (kind === 'resistance') color = '#ef4444';
                else if (kind === 'entry') color = '#38bdf8';
                else color = '#94a3b8';
            }
            const style = ln.style || (kind === 'pivot' ? 'solid' : 'dashed');
            const lw = kind === 'pivot' ? 2 : (Number(ln.width) || 1);
            strokeSegment(ctx, 0, y, w, y, cssColor(color, '#94a3b8'), lw, style);
            const label = ln.label || ((ln.timeframe ? ln.timeframe + ' ' : '') + (ln.kind || 'level'));
            if (label) {
                const bucket = Math.round(y / 14);
                let top = Math.max(2, Math.min(h - 14, y - 8));
                const prev = labelLanes.get(bucket);
                if (prev != null && Math.abs(prev - top) < 12) top = Math.max(2, top - 12);
                labelLanes.set(bucket, top);
                drawLabel(ctx, label, w - 6, top, {
                    position: 'right',
                    color: cssColor(color, '#e2e8f0'),
                    background: 'rgba(9,9,11,0.72)',
                    fontSize: 10,
                });
            }
            drawn++;
        }

        const entry = (payload.entry && typeof payload.entry === 'object') ? payload.entry : null;
        if (entry && Number.isFinite(Number(entry.price))) {
            const y = p2y(Number(entry.price));
            if (Number.isFinite(y)) {
                const side = entry.side ? String(entry.side).toUpperCase() : '';
                const color = side === 'CALL' || side === 'BUY'
                    ? '#22c55e'
                    : (side === 'PUT' || side === 'SELL' ? '#ef4444' : '#38bdf8');
                strokeSegment(ctx, 0, y, w, y, color, 2, 'solid');
                const entryLabel = entry.label || ('entry' + (side ? ' ' + side : ''));
                drawLabel(ctx, entryLabel, w - 6, y, {
                    position: 'right',
                    color,
                    background: 'rgba(9,9,11,0.72)',
                    fontSize: 10,
                });
                drawn++;
            }
        }
        return { nodes: 1, drawn };
    }

    global.PlatformChartCanvasOverlay = {
        begin,
        clear,
        paintStructure,
        paintLiquidity,
        paintPriceAction,
        paintLlmLevels,
        cssColor,
        strokeSegment,
        fillZone,
        drawLabel,
    };
})(typeof window !== 'undefined' ? window : globalThis);
