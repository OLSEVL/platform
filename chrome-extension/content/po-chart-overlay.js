/**
 * Оверлей маркеров CALL/PUT поверх canvas-графика Pocket Option.
 *
 * Координаты цены/времени графика PO недоступны из DOM — ставим маркер
 * у «живого» края (справа), CALL выше середины, PUT ниже. pointer-events:none,
 * чтобы клики по графику/кнопкам PO не перехватывались.
 *
 * Вход:
 *  - chrome.runtime message { type: 'po:draw-marker', side, ts, confidence, ... }
 *  - window CustomEvent 'platform-signals-marker' (из MAIN world после inject)
 */
(function () {
  if (window.__platformSignalsChartOverlay) {
    return;
  }
  window.__platformSignalsChartOverlay = true;

  const HOST_ID = 'ps-chart-overlay-host';
  const STYLE_ID = 'ps-chart-overlay-style';
  /** Сколько последних маркеров держим на графике. */
  const MAX_MARKERS = 5;
  /** Период подгонки оверлея под canvas (resize / смена layout PO). */
  const SYNC_MS = 400;

  /** @type {boolean} */
  let enabled = true;
  /** @type {number|null} */
  let syncTimer = null;
  /** @type {number} */
  let markerSeq = 0;

  // chrome.* есть только в контексте расширения; guard — чтобы smoke/отладка не валили скрипт.
  try {
    if (chrome.storage && chrome.storage.sync && typeof chrome.storage.sync.get === 'function') {
      chrome.storage.sync.get({ chartMarkersEnabled: true }, (cfg) => {
        enabled = !cfg || cfg.chartMarkersEnabled !== false;
        if (!enabled) {
          clearMarkers();
        }
      });
    }
    if (chrome.storage && chrome.storage.onChanged && typeof chrome.storage.onChanged.addListener === 'function') {
      chrome.storage.onChanged.addListener((changes, area) => {
        if (area !== 'sync' || !changes.chartMarkersEnabled) {
          return;
        }
        enabled = changes.chartMarkersEnabled.newValue !== false;
        if (!enabled) {
          clearMarkers();
        }
      });
    }
  } catch (_) {
    // вне расширения — оставляем enabled=true
  }

  try {
    if (chrome.runtime && chrome.runtime.onMessage && typeof chrome.runtime.onMessage.addListener === 'function') {
      chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
        if (!msg || typeof msg !== 'object') {
          return false;
        }
        if (msg.type === 'po:draw-marker') {
          const ok = drawMarker(msg);
          sendResponse({ ok });
          return false;
        }
        if (msg.type === 'po:clear-markers') {
          clearMarkers();
          sendResponse({ ok: true });
          return false;
        }
        return false;
      });
    }
  } catch (_) {
    // ignore
  }

  // MAIN-world inject может кинуть событие без sendMessage (после switch пары).
  // Слушаем и window, и document — DOM-события пересекают isolated/MAIN world.
  const onMarkerEvent = (ev) => {
    const detail = ev && ev.detail ? ev.detail : {};
    drawMarker(detail);
  };
  window.addEventListener('platform-signals-marker', onMarkerEvent);
  document.addEventListener('platform-signals-marker', onMarkerEvent);

  /**
   * @param {object} detail
   * @returns {boolean}
   */
  function drawMarker(detail) {
    if (!enabled) {
      return false;
    }
    const side = String(detail.side || '').toLowerCase();
    if (side !== 'call' && side !== 'put') {
      console.warn('[platform-signals] marker: bad side', detail);
      return false;
    }

    ensureStyles();
    const host = ensureHost();
    if (!host) {
      console.warn('[platform-signals] marker: no chart canvas');
      return false;
    }

    const confRaw = detail.confidence;
    let confLabel = '';
    if (confRaw != null && confRaw !== '') {
      const n = Number(confRaw);
      if (Number.isFinite(n)) {
        const pct = Math.round(n * (n <= 1 ? 100 : 1));
        confLabel = `${pct}%`;
      }
    }
    const timeLabel = formatTs(detail.ts);
    const pair = String(detail.display_name || detail.instrument || '').trim();
    const bot = String(detail.bot_name || '').trim();
    const metaParts = [timeLabel, confLabel, bot].filter(Boolean);

    markerSeq += 1;
    const el = document.createElement('div');
    el.className = `ps-mk ps-mk--${side}`;
    el.dataset.mkId = String(markerSeq);
    // Сдвиг вправо: «текущая» свеча. CALL выше mid, PUT ниже.
    const xPct = 88;
    const yPct = side === 'call' ? 36 : 62;
    el.style.left = `${xPct}%`;
    el.style.top = `${yPct}%`;

    el.innerHTML = `
      <div class="ps-mk-line" aria-hidden="true"></div>
      <div class="ps-mk-badge">
        <span class="ps-mk-arrow">${side === 'call' ? '▲' : '▼'}</span>
        <span class="ps-mk-side">${side.toUpperCase()}</span>
        ${pair ? `<span class="ps-mk-pair">${escapeHtml(pair)}</span>` : ''}
        ${metaParts.length ? `<span class="ps-mk-meta">${escapeHtml(metaParts.join(' · '))}</span>` : ''}
      </div>
    `;

    host.appendChild(el);
    // Старые маркеры чуть левее, чтобы не наслаивались в одной точке.
    restackMarkers(host);
    trimMarkers(host);
    ensureClearBtn(host);
    startSyncLoop();

    // Короткий pulse — внимание на новый сигнал.
    requestAnimationFrame(() => {
      el.classList.add('ps-mk--in');
    });

    console.info('[platform-signals] marker drawn', side, pair || detail.instrument);
    return true;
  }

  /** Развести маркеры по горизонтали: новый правее всех. */
  function restackMarkers(host) {
    const nodes = Array.from(host.querySelectorAll('.ps-mk'));
    const n = nodes.length;
    nodes.forEach((node, i) => {
      // База 88%, шаг ~3% влево для более старых.
      const offset = (n - 1 - i) * 3.2;
      node.style.left = `${Math.max(55, 88 - offset)}%`;
    });
  }

  function trimMarkers(host) {
    const nodes = host.querySelectorAll('.ps-mk');
    while (nodes.length > MAX_MARKERS) {
      nodes[0].remove();
    }
  }

  function clearMarkers() {
    const host = document.getElementById(HOST_ID);
    if (host) {
      host.remove();
    }
    stopSyncLoop();
  }

  function ensureClearBtn(host) {
    let btn = host.querySelector('.ps-mk-clear');
    if (btn) {
      return;
    }
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ps-mk-clear';
    btn.title = 'Убрать маркеры сигналов';
    btn.textContent = '✕ маркеры';
    // Единственный элемент с pointer-events — чтобы можно было очистить.
    btn.addEventListener('click', (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      clearMarkers();
    });
    host.appendChild(btn);
  }

  /**
   * @returns {HTMLElement|null}
   */
  function ensureHost() {
    const canvas = findChartCanvas();
    if (!canvas) {
      return null;
    }
    let host = document.getElementById(HOST_ID);
    if (!(host instanceof HTMLElement)) {
      host = document.createElement('div');
      host.id = HOST_ID;
      // fixed + sync по getBoundingClientRect canvas — переживает скролл/zoom PO.
      host.setAttribute('aria-hidden', 'true');
      document.documentElement.appendChild(host);
    }
    syncHost(host, canvas);
    return host;
  }

  /**
   * @param {HTMLElement} host
   * @param {HTMLCanvasElement} [canvas]
   */
  function syncHost(host, canvas) {
    const cv = canvas || findChartCanvas();
    if (!cv) {
      host.style.display = 'none';
      return;
    }
    const r = cv.getBoundingClientRect();
    if (r.width < 80 || r.height < 80) {
      host.style.display = 'none';
      return;
    }
    host.style.display = 'block';
    host.style.left = `${Math.round(r.left)}px`;
    host.style.top = `${Math.round(r.top)}px`;
    host.style.width = `${Math.round(r.width)}px`;
    host.style.height = `${Math.round(r.height)}px`;
  }

  /** Самый большой видимый canvas — обычно основной график котировок. */
  function findChartCanvas() {
    /** @type {HTMLCanvasElement|null} */
    let best = null;
    let bestArea = 0;
    const list = document.querySelectorAll('canvas');
    for (let i = 0; i < list.length; i++) {
      const cv = list[i];
      if (!(cv instanceof HTMLCanvasElement)) {
        continue;
      }
      const r = cv.getBoundingClientRect();
      if (r.width < 200 || r.height < 150) {
        continue;
      }
      const area = r.width * r.height;
      if (area > bestArea) {
        bestArea = area;
        best = cv;
      }
    }
    return best;
  }

  function startSyncLoop() {
    if (syncTimer != null) {
      return;
    }
    syncTimer = window.setInterval(() => {
      const host = document.getElementById(HOST_ID);
      if (!(host instanceof HTMLElement) || !host.querySelector('.ps-mk')) {
        stopSyncLoop();
        if (host) {
          host.remove();
        }
        return;
      }
      syncHost(host);
    }, SYNC_MS);
  }

  function stopSyncLoop() {
    if (syncTimer != null) {
      clearInterval(syncTimer);
      syncTimer = null;
    }
  }

  window.addEventListener('resize', () => {
    const host = document.getElementById(HOST_ID);
    if (host instanceof HTMLElement) {
      syncHost(host);
    }
  }, { passive: true });

  function ensureStyles() {
    if (document.getElementById(STYLE_ID)) {
      return;
    }
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
#${HOST_ID} {
  position: fixed;
  z-index: 2147483000;
  pointer-events: none;
  overflow: hidden;
  box-sizing: border-box;
}
#${HOST_ID} .ps-mk-clear {
  position: absolute;
  top: 8px;
  right: 8px;
  pointer-events: auto;
  cursor: pointer;
  border: 0;
  border-radius: 4px;
  padding: 4px 8px;
  font: 11px/1.2 system-ui, sans-serif;
  color: #e8eef7;
  background: rgba(12, 18, 28, 0.72);
  opacity: 0.75;
}
#${HOST_ID} .ps-mk-clear:hover { opacity: 1; }
#${HOST_ID} .ps-mk {
  position: absolute;
  transform: translate(-50%, -50%) scale(0.85);
  opacity: 0;
  transition: opacity 180ms ease, transform 180ms ease;
  will-change: transform, opacity;
}
#${HOST_ID} .ps-mk--in {
  opacity: 1;
  transform: translate(-50%, -50%) scale(1);
}
#${HOST_ID} .ps-mk-line {
  position: absolute;
  left: 50%;
  top: -120px;
  bottom: -120px;
  width: 0;
  border-left: 1px dashed currentColor;
  opacity: 0.55;
  transform: translateX(-50%);
}
#${HOST_ID} .ps-mk-badge {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  min-width: 64px;
  padding: 6px 8px;
  border-radius: 6px;
  border: 1px solid currentColor;
  background: rgba(8, 12, 20, 0.82);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
  color: inherit;
  font: 600 11px/1.2 system-ui, sans-serif;
  white-space: nowrap;
}
#${HOST_ID} .ps-mk-arrow { font-size: 14px; line-height: 1; }
#${HOST_ID} .ps-mk-side { letter-spacing: 0.04em; }
#${HOST_ID} .ps-mk-pair,
#${HOST_ID} .ps-mk-meta {
  font-weight: 500;
  font-size: 10px;
  opacity: 0.85;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
}
#${HOST_ID} .ps-mk--call { color: #3ddc8a; }
#${HOST_ID} .ps-mk--put { color: #ff6b6b; }
`;
    document.documentElement.appendChild(style);
  }

  /**
   * @param {unknown} ts
   * @returns {string}
   */
  function formatTs(ts) {
    const n = Number(ts);
    if (!Number.isFinite(n) || n <= 0) {
      return '';
    }
    // Секунды vs миллисекунды.
    const ms = n < 1e12 ? n * 1000 : n;
    try {
      return new Date(ms).toLocaleTimeString('ru-RU', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      });
    } catch (_) {
      return '';
    }
  }

  /**
   * @param {string} s
   * @returns {string}
   */
  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
})();
