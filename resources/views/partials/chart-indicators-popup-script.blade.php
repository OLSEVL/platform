{{--
  JS для широкого попапа «Индикаторы» + детальной справки (?).
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

    function openLayersPopup() {
        if (!layersPanelEl) return;
        layersPanelEl.classList.remove('hidden');
        document.documentElement.classList.add('overflow-hidden');
    }

    function closeLayersPopup() {
        if (!layersPanelEl) return;
        layersPanelEl.classList.add('hidden');
        // Не снимаем overflow, если открыта справка.
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
            (item.examples || []).forEach((ex) => {
                const li = document.createElement('li');
                li.textContent = ex;
                examplesEl.appendChild(li);
            });
        }
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

    // Экспорт для страниц, где уже есть layersBtn/layersPanel.
    window.PlatformIndicatorsPopup = {
        open: openLayersPopup,
        close: closeLayersPopup,
        openHelp,
        closeHelp,
        isOpen: () => layersPanelEl && !layersPanelEl.classList.contains('hidden'),
    };

    if (layersBtnEl && layersPanelEl) {
        layersBtnEl.addEventListener('click', (e) => {
            e.stopPropagation();
            if (typeof tfPanel !== 'undefined' && tfPanel) tfPanel.classList.add('hidden');
            if (layersPanelEl.classList.contains('hidden')) openLayersPopup();
            else closeLayersPopup();
        });

        layersPanelEl.querySelectorAll('[data-layers-overlay-close]').forEach((el) => {
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                closeLayersPopup();
            });
        });

        const card = layersPanelEl.querySelector('[data-layers-popup-card]');
        if (card) {
            card.addEventListener('click', (e) => e.stopPropagation());
        }

        layersPanelEl.querySelectorAll('[data-indicator-help]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                openHelp(btn.getAttribute('data-indicator-help') || '');
            });
        });
    }

    if (helpModal) {
        helpModal.querySelectorAll('[data-help-overlay-close]').forEach((el) => {
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                closeHelp();
            });
        });
        const helpCard = helpModal.querySelector('[data-help-popup-card]');
        if (helpCard) {
            helpCard.addEventListener('click', (e) => e.stopPropagation());
        }
    }

    document.addEventListener('keydown', (e) => {
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
