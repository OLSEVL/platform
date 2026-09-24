/**
 * SMC Flow → один Canvas2D вместо сотен DOM-div.
 *
 * Почему: после подгрузки истории payload даёт сотни/тысячи сегментов UT/Hull.
 * DOM-оверлей (innerHTML='' + createElement на каждый отрезок) на каждом zoom/pan
 * даёт long tasks и лаг. Binodex рисует индикаторы на canvas — зум «бесплатный».
 *
 * API: PlatformSmcFlowOverlay.paint(host, payload, api)
 */
(function attachPlatformSmcFlowOverlay(global) {
    if (global.PlatformSmcFlowOverlay) {
        return;
    }

    function cssColor(raw, fallback) {
        const s = String(raw || '').trim();
        if (!s) return fallback || 'rgba(255,255,255,0.8)';
        if (s.startsWith('#') || s.startsWith('rgb') || s.startsWith('hsl')) return s;
        return fallback || s;
    }

    function isStructureLine(ln) {
        if (!ln || typeof ln !== 'object') return false;
        const kind = String(ln.kind || '');
        if (kind === 'mxwll_main_leg') return true;
        const st = String(ln.style || 'Solid').toLowerCase();
        const p0 = Number(ln.price_start ?? ln.price);
        const p1 = Number(ln.price_end ?? ln.price);
        return st === 'dashed' && Math.abs(p0 - p1) < 1e-10;
    }

    function isStructureLabel(lb) {
        if (!lb || typeof lb !== 'object') return false;
        return String(lb.kind || '') === 'mxwll_structure'
            || /^(I-)?(BoS|CHoCH)$/.test(String(lb.text || ''));
    }

    function ensureCanvas(host) {
        let canvas = host.querySelector('canvas[data-smc-flow-canvas="1"]');
        if (!canvas) {
            host.replaceChildren();
            canvas = document.createElement('canvas');
            canvas.dataset.smcFlowCanvas = '1';
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

    function strokeSegment(ctx, x1, y1, x2, y2, color, width, style) {
        if (![x1, y1, x2, y2].every(Number.isFinite)) return;
        const st = String(style || 'Solid').toLowerCase();
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

    function drawLabel(ctx, text, x, y, opts) {
        const t = String(text || '');
        if (!t || ![x, y].every(Number.isFinite)) return;
        const fontSize = opts.fontSize || 11;
        const fontWeight = opts.fontWeight || '600';
        ctx.font = `${fontWeight} ${fontSize}px ui-sans-serif, system-ui, sans-serif`;
        ctx.textBaseline = 'middle';
        const metrics = ctx.measureText(t);
        const tw = metrics.width;
        const th = fontSize + 4;
        let ax = x;
        let ay = y;
        const pos = String(opts.position || '');
        if (pos === 'below') {
            ay = y + th / 2 + 6;
            ctx.textAlign = 'center';
        } else if (pos === 'center') {
            ctx.textAlign = 'center';
        } else if (pos === 'left') {
            ax = x + 8;
            ctx.textAlign = 'left';
        } else {
            // above (default)
            ay = y - th / 2 - 4;
            ctx.textAlign = 'center';
        }
        if (opts.background) {
            const padX = 5;
            const padY = 2;
            const bx = ctx.textAlign === 'left' ? ax : ax - tw / 2;
            ctx.fillStyle = opts.background;
            ctx.beginPath();
            if (typeof ctx.roundRect === 'function') {
                ctx.roundRect(bx - padX, ay - th / 2 - padY, tw + padX * 2, th + padY * 2, 3);
            } else {
                ctx.rect(bx - padX, ay - th / 2 - padY, tw + padX * 2, th + padY * 2);
            }
            ctx.fill();
        }
        if (opts.shadow) {
            ctx.shadowColor = 'rgba(0,0,0,0.9)';
            ctx.shadowBlur = 4;
        }
        ctx.fillStyle = opts.color || '#fff';
        ctx.fillText(t, ax, ay);
        ctx.shadowBlur = 0;
    }

    /**
     * @param {HTMLElement} host
     * @param {object|null} payload
     * @param {{
     *   enabled: boolean,
     *   timeToCoordinate: (t:number)=>number|null,
     *   priceToCoordinate: (p:number)=>number|null,
     *   zoneEndX: (t:number)=>number|null,
     *   viewportW: number,
     *   viewportH: number,
     * }} api
     */
    function paint(host, payload, api) {
        if (!host) return { nodes: 0, drawn: 0 };
        if (!api || !api.enabled || !payload || typeof payload !== 'object') {
            host.replaceChildren();
            return { nodes: 0, drawn: 0 };
        }
        const cssW = Math.max(1, Number(api.viewportW) || host.clientWidth || 1);
        const cssH = Math.max(1, Number(api.viewportH) || host.clientHeight || 1);
        const canvas = ensureCanvas(host);
        const ctx = fitCanvas(canvas, cssW, cssH);
        ctx.clearRect(0, 0, cssW, cssH);

        const areas = Array.isArray(payload.areas) ? payload.areas : [];
        const lines = Array.isArray(payload.lines) ? payload.lines : [];
        const labels = Array.isArray(payload.labels) ? payload.labels : [];
        const structureLines = [];
        const otherLines = [];
        for (let i = 0; i < lines.length; i++) {
            const ln = lines[i];
            if (isStructureLine(ln)) structureLines.push(ln);
            else otherLines.push(ln);
        }

        // Лимит как в DOM-пути, но canvas тянет больше без лагов — оставляем запас.
        const maxRenderable = Math.max(400, Math.min(2500, Math.floor(cssW * 1.8)));
        let drawn = 0;
        const pad = 80;
        const t2x = api.timeToCoordinate;
        const p2y = api.priceToCoordinate;
        const zoneEndX = api.zoneEndX || t2x;

        for (let i = 0; i < areas.length && drawn < maxRenderable; i++) {
            const ar = areas[i];
            if (!ar || typeof ar !== 'object') continue;
            const x1 = t2x(Number(ar.time_start));
            const x2 = zoneEndX(Number(ar.time_end));
            const y1 = p2y(Number(ar.price_high));
            const y2 = p2y(Number(ar.price_low));
            if (![x1, x2, y1, y2].every(Number.isFinite)) continue;
            if (Math.abs(x2 - x1) < 1) continue;
            if (Math.max(x1, x2) < -pad || Math.min(x1, x2) > cssW + pad) continue;
            ctx.fillStyle = cssColor(ar.fill, 'rgba(41,98,255,0.24)');
            ctx.fillRect(
                Math.min(x1, x2),
                Math.min(y1, y2),
                Math.max(1, Math.abs(x2 - x1)),
                Math.max(1, Math.abs(y2 - y1)),
            );
            drawn++;
        }

        function paintLine(ln) {
            if (!ln || typeof ln !== 'object') return false;
            const x1 = t2x(Number(ln.time_start));
            const x2 = t2x(Number(ln.time_end));
            const y1 = p2y(Number(ln.price_start ?? ln.price));
            const y2 = p2y(Number(ln.price_end ?? ln.price));
            if (![x1, x2, y1, y2].every(Number.isFinite)) return false;
            if (Math.max(x1, x2) < -pad || Math.min(x1, x2) > cssW + pad) return false;
            strokeSegment(
                ctx,
                x1,
                y1,
                x2,
                y2,
                cssColor(ln.color, '#22c55e'),
                ln.width || 2,
                ln.style,
            );
            return true;
        }

        for (let i = 0; i < structureLines.length; i++) {
            if (paintLine(structureLines[i])) drawn++;
        }
        for (let i = 0; i < otherLines.length && drawn < maxRenderable; i++) {
            if (paintLine(otherLines[i])) drawn++;
        }

        const labelSize = payload.label_size;
        for (let i = 0; i < labels.length; i++) {
            const lb = labels[i];
            if (!lb || typeof lb !== 'object') continue;
            const struct = isStructureLabel(lb);
            const x = t2x(Number(lb.time));
            const y = p2y(Number(lb.price));
            if (!Number.isFinite(y)) continue;
            const cull = struct ? 140 : 80;
            if (!Number.isFinite(x) || x < -cull || x > cssW + cull) continue;
            const bgRaw = String(lb.background || '');
            const transparentBg = bgRaw === '' || bgRaw === 'transparent' || bgRaw.toLowerCase() === '#00000000';
            let fontSize = 11;
            if (struct) {
                fontSize = labelSize === 'normal' ? 13 : (labelSize === 'small' ? 12 : 11);
            } else if (labelSize === 'normal') {
                fontSize = 12;
            } else if (labelSize === 'large') {
                fontSize = 13;
            }
            drawLabel(ctx, lb.text, x, y, {
                position: lb.position,
                color: cssColor(lb.color, '#ffffff'),
                background: transparentBg ? null : cssColor(bgRaw || '#22c55e'),
                fontSize,
                fontWeight: struct ? '700' : '600',
                shadow: struct,
            });
            drawn++;
        }

        return { nodes: 1, drawn };
    }

    global.PlatformSmcFlowOverlay = { paint };
})(typeof window !== 'undefined' ? window : globalThis);
