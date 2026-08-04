{{--
  JS для широкого попапа «Индикаторы» + детальной справки (? / клик по описанию).
  Требует: #layers-dropdown-btn, #layers-dropdown-panel, #indicator-help-modal, #indicator-help-catalog.
  Переменные layersBtn / layersPanel / tfPanel должны быть объявлены на странице.
--}}
<script>
(function () {
    const layersBtnEl = document.getElementById('layers-dropdown-btn');
    const layersPanelEl = document.getElementById('layers-dropdown-panel');
    const helpModal = document.getElementById('indicator-help-modal');
    const helpCatalogEl = document.getElementById('indicator-help-catalog');
    let helpCatalog = {};
    try {
        helpCatalog = helpCatalogEl ? JSON.parse(helpCatalogEl.textContent || '{}') : {};
    } catch (_) {
        helpCatalog = {};
    }

    /** Общая «рамка» SVG-схемы (тёмный фон, как на графике). */
    function svgFrame(inner, w, h) {
        w = w || 320;
        h = h || 160;
        return (
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + w + ' ' + h + '" class="w-full h-auto rounded-lg border border-zinc-700 bg-[#12141a]" role="img">' +
            '<rect width="' + w + '" height="' + h + '" fill="#12141a"/>' +
            inner +
            '</svg>'
        );
    }

    /** Мини-свечи (упрощённо). */
    function candles(points) {
        // points: [{x, o, c, h, l}]
        return points.map(function (p) {
            var up = p.c >= p.o;
            var color = up ? '#34d399' : '#f87171';
            var bodyTop = Math.min(p.o, p.c);
            var bodyH = Math.max(2, Math.abs(p.c - p.o));
            return (
                '<line x1="' + p.x + '" y1="' + p.h + '" x2="' + p.x + '" y2="' + p.l + '" stroke="' + color + '" stroke-width="1.5"/>' +
                '<rect x="' + (p.x - 4) + '" y="' + bodyTop + '" width="8" height="' + bodyH + '" fill="' + color + '" rx="1"/>'
            );
        }).join('');
    }

    /**
     * Набор учебных схем. type → SVG.
     * Подписи на схеме короткие и на русском.
     */
    const DIAGRAM_SVG = {
        channel_bounce: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Цена гуляет в «коридоре»</text>' +
                '<path d="M20 50 H300" stroke="#60a5fa" stroke-width="1.5" stroke-dasharray="4 3"/>' +
                '<path d="M20 80 H300" stroke="#94a3b8" stroke-width="1.5"/>' +
                '<path d="M20 110 H300" stroke="#60a5fa" stroke-width="1.5" stroke-dasharray="4 3"/>' +
                '<text x="248" y="46" fill="#60a5fa" font-size="10">верх</text>' +
                '<text x="248" y="76" fill="#94a3b8" font-size="10">середина</text>' +
                '<text x="248" y="106" fill="#60a5fa" font-size="10">низ</text>' +
                candles([
                    {x: 40, o: 78, c: 70, h: 66, l: 84},
                    {x: 70, o: 72, c: 88, h: 64, l: 92},
                    {x: 100, o: 86, c: 98, h: 82, l: 108},
                    {x: 130, o: 100, c: 108, h: 96, l: 112},
                    {x: 160, o: 106, c: 92, h: 88, l: 110},
                    {x: 190, o: 94, c: 78, h: 74, l: 100},
                    {x: 220, o: 80, c: 70, h: 66, l: 88}
                ]) +
                '<path d="M220 70 L235 55" stroke="#fbbf24" stroke-width="2" marker-end="url(#a)"/>' +
                '<text x="170" y="140" fill="#fbbf24" font-size="11">отскок от низа ↑</text>' +
                '<defs><marker id="a" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto"><path d="M0,0 L6,3 L0,6 Z" fill="#fbbf24"/></marker></defs>'
            );
        },
        channel_breakout: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Цена вышла из коридора</text>' +
                '<path d="M20 55 H220" stroke="#60a5fa" stroke-width="1.5" stroke-dasharray="4 3"/>' +
                '<path d="M20 85 H220" stroke="#94a3b8" stroke-width="1.5"/>' +
                '<path d="M20 115 H220" stroke="#60a5fa" stroke-width="1.5" stroke-dasharray="4 3"/>' +
                candles([
                    {x: 40, o: 90, c: 82, h: 78, l: 96},
                    {x: 70, o: 84, c: 88, h: 76, l: 94},
                    {x: 100, o: 86, c: 78, h: 74, l: 92},
                    {x: 130, o: 80, c: 70, h: 66, l: 86},
                    {x: 160, o: 68, c: 52, h: 48, l: 74},
                    {x: 190, o: 54, c: 40, h: 36, l: 60},
                    {x: 230, o: 42, c: 32, h: 28, l: 48},
                    {x: 260, o: 34, c: 28, h: 24, l: 42}
                ]) +
                '<text x="200" y="148" fill="#34d399" font-size="11">пробой вверх → движение сильнее</text>'
            );
        },
        ma_trend: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Средняя линия = «средняя температура» цены</text>' +
                '<path d="M30 120 C80 110, 120 95, 160 70 S240 40, 290 28" fill="none" stroke="#38bdf8" stroke-width="2.5"/>' +
                candles([
                    {x: 50, o: 118, c: 108, h: 102, l: 122},
                    {x: 90, o: 110, c: 98, h: 92, l: 116},
                    {x: 130, o: 100, c: 82, h: 76, l: 108},
                    {x: 170, o: 84, c: 68, h: 62, l: 90},
                    {x: 210, o: 70, c: 52, h: 46, l: 78},
                    {x: 250, o: 54, c: 40, h: 34, l: 62}
                ]) +
                '<text x="200" y="70" fill="#38bdf8" font-size="10">средняя</text>' +
                '<text x="40" y="148" fill="#34d399" font-size="11">цена выше средней → чаще рост</text>'
            );
        },
        oscillator: function () {
            return svgFrame(
                '<text x="12" y="16" fill="#a1a1aa" font-size="11">Отдельная шкала «насколько перегрета» цена</text>' +
                '<rect x="20" y="28" width="280" height="100" rx="4" fill="#181b22" stroke="#3f3f46"/>' +
                '<path d="M20 48 H300" stroke="#f87171" stroke-width="1" stroke-dasharray="3 3"/>' +
                '<path d="M20 108 H300" stroke="#34d399" stroke-width="1" stroke-dasharray="3 3"/>' +
                '<text x="248" y="44" fill="#f87171" font-size="10">дорого</text>' +
                '<text x="248" y="120" fill="#34d399" font-size="10">дёшево</text>' +
                '<path d="M30 90 C70 95, 100 70, 130 55 S180 40, 210 48 S260 90, 290 100" fill="none" stroke="#a78bfa" stroke-width="2.5"/>' +
                '<circle cx="130" cy="55" r="4" fill="#fbbf24"/>' +
                '<text x="20" y="148" fill="#fbbf24" font-size="11">у зоны «дорого» часто ждут разворот вниз</text>',
                320, 160
            );
        },
        histogram: function () {
            return svgFrame(
                '<text x="12" y="16" fill="#a1a1aa" font-size="11">Столбики: сила толчка вверх или вниз</text>' +
                '<path d="M20 90 H300" stroke="#52525b" stroke-width="1"/>' +
                [[40, 40, '#34d399'], [70, 28, '#34d399'], [100, 12, '#34d399'], [130, -10, '#f87171'], [160, -30, '#f87171'], [190, -18, '#f87171'], [220, 8, '#34d399'], [250, 26, '#34d399'], [280, 36, '#34d399']].map(function (b) {
                    var x = b[0], h = b[1], c = b[2];
                    if (h >= 0) {
                        return '<rect x="' + (x - 8) + '" y="' + (90 - h) + '" width="16" height="' + h + '" fill="' + c + '" rx="1"/>';
                    }
                    return '<rect x="' + (x - 8) + '" y="90" width="16" height="' + (-h) + '" fill="' + c + '" rx="1"/>';
                }).join('') +
                '<text x="36" y="148" fill="#34d399" font-size="11">зелёные ↑</text>' +
                '<text x="140" y="148" fill="#f87171" font-size="11">красные ↓</text>' +
                '<text x="220" y="148" fill="#a1a1aa" font-size="11">смена цвета = смена сил</text>'
            );
        },
        order_block: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Цветной прямоугольник = «зона интереса»</text>' +
                '<rect x="70" y="70" width="90" height="36" fill="#065f46" fill-opacity="0.55" stroke="#34d399" stroke-width="1.5"/>' +
                '<text x="82" y="92" fill="#a7f3d0" font-size="10">зелёная зона</text>' +
                candles([
                    {x: 40, o: 100, c: 88, h: 84, l: 108},
                    {x: 100, o: 90, c: 78, h: 72, l: 98},
                    {x: 130, o: 80, c: 58, h: 52, l: 88},
                    {x: 160, o: 60, c: 48, h: 42, l: 68},
                    {x: 200, o: 52, c: 70, h: 46, l: 76},
                    {x: 230, o: 68, c: 82, h: 62, l: 88},
                    {x: 260, o: 80, c: 74, h: 68, l: 90}
                ]) +
                '<path d="M250 74 L235 88" stroke="#fbbf24" stroke-width="2"/>' +
                '<text x="150" y="148" fill="#fbbf24" font-size="11">цена вернулась в зону и отскочила</text>'
            );
        },
        fvg_gap: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">«Дыра» между свечами после резкого рывка</text>' +
                candles([
                    {x: 60, o: 110, c: 100, h: 96, l: 116},
                    {x: 120, o: 70, c: 50, h: 44, l: 78},
                    {x: 180, o: 52, c: 40, h: 36, l: 60}
                ]) +
                '<rect x="78" y="78" width="70" height="18" fill="#7c3aed" fill-opacity="0.35" stroke="#a78bfa" stroke-width="1.5" stroke-dasharray="3 2"/>' +
                '<text x="86" y="91" fill="#ddd6fe" font-size="10">дыра</text>' +
                '<path d="M220 70 C240 85, 250 95, 260 105" fill="none" stroke="#fbbf24" stroke-width="2" stroke-dasharray="4 3"/>' +
                '<text x="40" y="148" fill="#fbbf24" font-size="11">позже цена часто «заглядывает» закрыть дыру</text>'
            );
        },
        trailing: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Точки/линия «следуют» за ценой как страховочный трос</text>' +
                candles([
                    {x: 40, o: 110, c: 98, h: 92, l: 116},
                    {x: 80, o: 100, c: 84, h: 78, l: 108},
                    {x: 120, o: 86, c: 70, h: 64, l: 94},
                    {x: 160, o: 72, c: 58, h: 52, l: 80},
                    {x: 200, o: 60, c: 78, h: 54, l: 86},
                    {x: 240, o: 76, c: 96, h: 70, l: 102},
                    {x: 280, o: 94, c: 108, h: 88, l: 114}
                ]) +
                '<circle cx="40" cy="122" r="3" fill="#38bdf8"/><circle cx="80" cy="112" r="3" fill="#38bdf8"/><circle cx="120" cy="100" r="3" fill="#38bdf8"/><circle cx="160" cy="88" r="3" fill="#38bdf8"/>' +
                '<circle cx="200" cy="48" r="3" fill="#f472b6"/><circle cx="240" cy="58" r="3" fill="#f472b6"/><circle cx="280" cy="70" r="3" fill="#f472b6"/>' +
                '<text x="30" y="148" fill="#38bdf8" font-size="10">под ценой = рост</text>' +
                '<text x="170" y="148" fill="#f472b6" font-size="10">над ценой = падение</text>'
            );
        },
        cloud: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">«Облако» — зона, где тренд обычно сильнее</text>' +
                '<path d="M40 100 C90 90, 140 70, 200 60 S280 50, 300 48 L300 110 C260 100, 200 115, 140 120 S70 118, 40 115 Z" fill="#0ea5e9" fill-opacity="0.25" stroke="#38bdf8" stroke-width="1"/>' +
                candles([
                    {x: 70, o: 120, c: 108, h: 102, l: 126},
                    {x: 120, o: 110, c: 90, h: 84, l: 118},
                    {x: 170, o: 92, c: 70, h: 64, l: 100},
                    {x: 220, o: 72, c: 50, h: 44, l: 80},
                    {x: 270, o: 52, c: 38, h: 32, l: 60}
                ]) +
                '<text x="40" y="148" fill="#67e8f9" font-size="11">цена над облаком → чаще смотрят вверх</text>'
            );
        },
        squeeze: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Сначала коридор узкий (тишина), потом резкий рывок</text>' +
                '<path d="M20 70 C80 78, 120 80, 150 78 S200 50, 280 30" fill="none" stroke="#60a5fa" stroke-width="1.5"/>' +
                '<path d="M20 100 C80 95, 120 94, 150 96 S200 120, 280 140" fill="none" stroke="#60a5fa" stroke-width="1.5"/>' +
                '<text x="70" y="88" fill="#a1a1aa" font-size="10">сжатие</text>' +
                '<text x="220" y="88" fill="#fbbf24" font-size="10">рывок →</text>' +
                candles([
                    {x: 50, o: 88, c: 84, h: 80, l: 92},
                    {x: 90, o: 86, c: 90, h: 82, l: 94},
                    {x: 130, o: 88, c: 86, h: 82, l: 92},
                    {x: 180, o: 84, c: 60, h: 54, l: 90},
                    {x: 220, o: 62, c: 42, h: 36, l: 70},
                    {x: 260, o: 44, c: 30, h: 24, l: 52}
                ])
            );
        },
        zigzag: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Ломаная соединяет важные вершины и низины</text>' +
                '<path d="M40 110 L90 50 L150 100 L210 40 L270 90" fill="none" stroke="#fbbf24" stroke-width="2.5"/>' +
                '<circle cx="40" cy="110" r="4" fill="#f87171"/><circle cx="90" cy="50" r="4" fill="#34d399"/><circle cx="150" cy="100" r="4" fill="#f87171"/><circle cx="210" cy="40" r="4" fill="#34d399"/><circle cx="270" cy="90" r="4" fill="#f87171"/>' +
                '<text x="30" y="148" fill="#a1a1aa" font-size="11">помогает увидеть «волны», без мелкого шума</text>'
            );
        },
        levels: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Горизонтальные линии = «пол» и «потолок»</text>' +
                '<path d="M20 50 H300" stroke="#f87171" stroke-width="1.5"/>' +
                '<path d="M20 110 H300" stroke="#34d399" stroke-width="1.5"/>' +
                '<text x="240" y="44" fill="#f87171" font-size="10">потолок</text>' +
                '<text x="248" y="124" fill="#34d399" font-size="10">пол</text>' +
                candles([
                    {x: 60, o: 90, c: 78, h: 70, l: 98},
                    {x: 100, o: 80, c: 60, h: 52, l: 88},
                    {x: 140, o: 62, c: 72, h: 54, l: 80},
                    {x: 180, o: 70, c: 88, h: 64, l: 96},
                    {x: 220, o: 86, c: 96, h: 78, l: 104},
                    {x: 260, o: 94, c: 82, h: 74, l: 100}
                ]) +
                '<text x="40" y="148" fill="#fbbf24" font-size="11">у пола часто отскок вверх, у потолка — вниз</text>'
            );
        },
        volatility: function () {
            return svgFrame(
                '<text x="12" y="16" fill="#a1a1aa" font-size="11">Линия «нервозности» рынка (не направление!)</text>' +
                '<rect x="20" y="28" width="280" height="100" rx="4" fill="#181b22" stroke="#3f3f46"/>' +
                '<path d="M30 100 C70 95, 100 90, 130 70 S180 40, 220 45 S270 80, 290 55" fill="none" stroke="#fb923c" stroke-width="2.5"/>' +
                '<text x="40" y="148" fill="#fb923c" font-size="11">линия выросла = рынок шумный, свечи «скачут»</text>'
            );
        },
        dual_ma: function () {
            return svgFrame(
                '<text x="12" y="18" fill="#a1a1aa" font-size="11">Две средние: быстрая и медленная</text>' +
                '<path d="M30 100 C90 90, 150 70, 220 50 S290 35, 300 30" fill="none" stroke="#38bdf8" stroke-width="2"/>' +
                '<path d="M30 115 C100 108, 160 95, 230 75 S290 55, 300 50" fill="none" stroke="#f472b6" stroke-width="2"/>' +
                '<text x="210" y="42" fill="#38bdf8" font-size="10">быстрая</text>' +
                '<text x="210" y="78" fill="#f472b6" font-size="10">медленная</text>' +
                '<circle cx="160" cy="78" r="5" fill="#fbbf24"/>' +
                '<text x="40" y="148" fill="#fbbf24" font-size="11">пересечение часто = смена настроения</text>'
            );
        },
    };

    function renderDiagrams(diagrams) {
        const wrap = document.getElementById('indicator-help-diagrams-wrap');
        const box = document.getElementById('indicator-help-diagrams');
        if (!wrap || !box) return;
        box.innerHTML = '';
        const list = Array.isArray(diagrams) ? diagrams : [];
        if (!list.length) {
            wrap.classList.add('hidden');
            return;
        }
        wrap.classList.remove('hidden');
        list.forEach(function (d) {
            const type = (d && d.type) || '';
            const caption = (d && d.caption) || '';
            const factory = DIAGRAM_SVG[type];
            const card = document.createElement('figure');
            card.className = 'rounded-lg bg-zinc-900/40 p-2';
            if (typeof factory === 'function') {
                card.innerHTML = factory();
            } else {
                card.innerHTML = '<div class="text-xs text-zinc-500 p-4">Схема недоступна</div>';
            }
            if (caption) {
                const fig = document.createElement('figcaption');
                fig.className = 'mt-2 text-[11px] leading-snug text-zinc-400';
                fig.textContent = caption;
                card.appendChild(fig);
            }
            box.appendChild(card);
        });
    }

    function openLayersPopup() {
        if (!layersPanelEl) return;
        layersPanelEl.classList.remove('hidden');
        document.documentElement.classList.add('overflow-hidden');
    }

    function closeLayersPopup() {
        if (!layersPanelEl) return;
        layersPanelEl.classList.add('hidden');
        if (!helpModal || helpModal.classList.contains('hidden')) {
            document.documentElement.classList.remove('overflow-hidden');
        }
    }

    function openHelp(key) {
        const item = helpCatalog[key];
        if (!item || !helpModal) return;
        const titleEl = document.getElementById('indicator-help-title');
        const groupEl = document.getElementById('indicator-help-group');
        const showsEl = document.getElementById('indicator-help-shows');
        const howEl = document.getElementById('indicator-help-how');
        const examplesEl = document.getElementById('indicator-help-examples');
        if (titleEl) titleEl.textContent = item.title || key;
        if (groupEl) groupEl.textContent = item.group || '';
        if (showsEl) showsEl.textContent = item.shows || '';
        if (howEl) howEl.textContent = item.how || '';
        if (examplesEl) {
            examplesEl.innerHTML = '';
            (item.examples || []).forEach(function (ex) {
                const li = document.createElement('li');
                li.textContent = ex;
                examplesEl.appendChild(li);
            });
        }
        renderDiagrams(item.diagrams || []);
        helpModal.classList.remove('hidden');
        document.documentElement.classList.add('overflow-hidden');
    }

    function closeHelp() {
        if (!helpModal) return;
        helpModal.classList.add('hidden');
        if (!layersPanelEl || layersPanelEl.classList.contains('hidden')) {
            document.documentElement.classList.remove('overflow-hidden');
        }
    }

    window.PlatformIndicatorsPopup = {
        open: openLayersPopup,
        close: closeLayersPopup,
        openHelp,
        closeHelp,
        isOpen: function () { return layersPanelEl && !layersPanelEl.classList.contains('hidden'); },
    };

    if (layersBtnEl && layersPanelEl) {
        layersBtnEl.addEventListener('click', function (e) {
            e.stopPropagation();
            if (typeof tfPanel !== 'undefined' && tfPanel) tfPanel.classList.add('hidden');
            if (layersPanelEl.classList.contains('hidden')) openLayersPopup();
            else closeLayersPopup();
        });

        layersPanelEl.querySelectorAll('[data-layers-overlay-close]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                closeLayersPopup();
            });
        });

        const card = layersPanelEl.querySelector('[data-layers-popup-card]');
        if (card) {
            card.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        // И «?», и клик по короткому описанию.
        layersPanelEl.querySelectorAll('[data-indicator-help]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openHelp(btn.getAttribute('data-indicator-help') || '');
            });
        });
    }

    if (helpModal) {
        helpModal.querySelectorAll('[data-help-overlay-close]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                closeHelp();
            });
        });
        const helpCard = helpModal.querySelector('[data-help-popup-card]');
        if (helpCard) {
            helpCard.addEventListener('click', function (e) { e.stopPropagation(); });
        }
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (helpModal && !helpModal.classList.contains('hidden')) {
            closeHelp();
            return;
        }
        if (layersPanelEl && !layersPanelEl.classList.contains('hidden')) {
            closeLayersPopup();
        }
    });
})();
</script>
