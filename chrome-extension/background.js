/**
 * Service worker: UI mode + переключение актива на PO через inject (актуальный DOM).
 * WebSocket живёт в float.html.
 */
importScripts('endpoints.js');

const DEFAULTS = {
  networkMode: 'wan',
  serverHost: EXT_ENDPOINTS.wan,
  /** @type {'float'|'sidepanel'} */
  uiMode: 'sidepanel',
};

/** @type {number|null} */
let floatWindowId = null;

chrome.runtime.onInstalled.addListener(() => {
  applyUiModeFromStorage();
  void restoreFloatWindowId();
});

chrome.runtime.onStartup.addListener(() => {
  applyUiModeFromStorage();
  void restoreFloatWindowId();
});

applyUiModeFromStorage();
void restoreFloatWindowId();

// Срабатывает только когда openPanelOnActionClick=false (режим «Окно»).
chrome.action.onClicked.addListener(() => {
  void openOrFocusFloat();
});

chrome.windows.onRemoved.addListener((windowId) => {
  if (windowId === floatWindowId) {
    floatWindowId = null;
    void chrome.storage.session.set({ floatWindowId: null }).catch(() => {});
  }
});

chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'sync' && changes.uiMode) {
    applyUiMode(String(changes.uiMode.newValue || DEFAULTS.uiMode));
  }
});

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (!msg || typeof msg !== 'object') {
    return false;
  }
  if (msg.type === 'ext:open-ui') {
    openUi().then(() => sendResponse({ ok: true })).catch((e) => {
      sendResponse({ ok: false, error: String(e) });
    });
    return true;
  }
  if (msg.type === 'ext:open-float') {
    openOrFocusFloat().then(() => sendResponse({ ok: true }));
    return true;
  }
  if (msg.type === 'ext:apply-ui-mode') {
    const mode = msg.mode === 'sidepanel' ? 'sidepanel' : 'float';
    chrome.storage.sync.set({ uiMode: mode }).then(() => applyUiMode(mode)).then(() => {
      sendResponse({ ok: true, mode });
    });
    return true;
  }
  if (msg.type === 'ext:open-asset') {
    const instrument = String(msg.instrument || '').trim();
    const displayName = String(msg.display_name || '').trim();
    if (!instrument) {
      sendResponse({ ok: false, error: 'empty_instrument' });
      return false;
    }
    openAssetOnPo(instrument, displayName)
      .then((r) => sendResponse(r))
      .catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  }
  return false;
});

async function applyUiModeFromStorage() {
  const cfg = await chrome.storage.sync.get({ uiMode: DEFAULTS.uiMode });
  // По умолчанию sidepanel; float только при явном выборе.
  await applyUiMode(cfg.uiMode === 'float' ? 'float' : 'sidepanel');
}

/**
 * @param {'float'|'sidepanel'} mode
 */
async function applyUiMode(mode) {
  try {
    await chrome.sidePanel.setPanelBehavior({
      openPanelOnActionClick: mode === 'sidepanel',
    });
  } catch (e) {
    console.warn('[ext] setPanelBehavior', e);
  }
}

async function openUi() {
  const cfg = await chrome.storage.sync.get({ uiMode: DEFAULTS.uiMode });
  if (cfg.uiMode !== 'float') {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab && tab.windowId != null) {
      await chrome.sidePanel.open({ windowId: tab.windowId });
    }
    return;
  }
  await openOrFocusFloat();
}

async function restoreFloatWindowId() {
  try {
    const stored = await chrome.storage.session.get({ floatWindowId: null });
    const id = stored.floatWindowId != null ? Number(stored.floatWindowId) : null;
    if (!id) {
      return;
    }
    await chrome.windows.get(id);
    floatWindowId = id;
  } catch (_) {
    floatWindowId = null;
    await chrome.storage.session.set({ floatWindowId: null }).catch(() => {});
  }
}

async function rememberFloatWindowId(id) {
  floatWindowId = id;
  await chrome.storage.session.set({ floatWindowId: id }).catch(() => {});
}

/** Вернуть фокус на плавающее окно сигналов (чтобы не уходило под Chrome/PO). */
async function refocusFloatWindow() {
  if (floatWindowId == null) {
    await restoreFloatWindowId();
  }
  if (floatWindowId == null) {
    return;
  }
  try {
    await chrome.windows.update(floatWindowId, { focused: true });
  } catch (_) {
    floatWindowId = null;
    await chrome.storage.session.set({ floatWindowId: null }).catch(() => {});
  }
}

async function openOrFocusFloat() {
  if (floatWindowId != null) {
    try {
      await chrome.windows.update(floatWindowId, { focused: true });
      return;
    } catch (_) {
      floatWindowId = null;
    }
  }
  const win = await chrome.windows.create({
    url: chrome.runtime.getURL('float.html'),
    type: 'popup',
    width: 460,
    height: 600,
    focused: true,
  });
  await rememberFloatWindowId(win.id ?? null);
}

/**
 * Переключение пары на PO: каждый клик — свежий inject с актуальными селекторами.
 * DOM PO (проверено): a.pair-number-wrap → input.search__field → li.alist__item a.alist__link
 *
 * В режиме float не забираем фокус у плавающего окна (иначе оно «исчезает» под PO).
 *
 * @param {string} instrument
 * @param {string} [displayName]
 */
async function openAssetOnPo(instrument, displayName = '') {
  if (!displayName) {
    displayName = await resolveDisplayName(instrument);
  }

  const cfg = await chrome.storage.sync.get({ uiMode: DEFAULTS.uiMode });
  const keepFloatOnTop = cfg.uiMode !== 'sidepanel';

  const tabs = await chrome.tabs.query({
    url: ['*://pocketoption.com/*', '*://*.pocketoption.com/*'],
  });
  let tab = tabs.find((t) => t.active && t.url && /cabinet/i.test(t.url))
    || tabs.find((t) => t.url && /cabinet/i.test(t.url))
    || tabs.find((t) => t.active)
    || tabs[0];

  try {
    if (!tab) {
      // Новую вкладку всё же активируем; сразу после inject вернём float наверх.
      tab = await chrome.tabs.create({ url: 'https://pocketoption.com/en/cabinet/demo-quick-high-low/' });
      await waitTabComplete(tab.id, 20000);
    } else {
      // Вкладку PO делаем активной внутри своего окна, но окно PO не фокусируем —
      // иначе плавающий список уходит под браузер.
      await chrome.tabs.update(tab.id, { active: true });
      if (!keepFloatOnTop && tab.windowId != null) {
        await chrome.windows.update(tab.windowId, { focused: true });
      }
    }

    await new Promise((r) => setTimeout(r, 200));

    try {
      const injected = await chrome.scripting.executeScript({
        target: { tabId: tab.id },
        world: 'MAIN',
        func: poSwitchAssetInPage,
        args: [instrument, displayName || ''],
      });
      const result = injected && injected[0] ? injected[0].result : null;
      if (result && typeof result === 'object') {
        return result;
      }
      return { ok: false, error: 'empty_inject_result' };
    } catch (e) {
      // Fallback: isolated world + content script message.
      try {
        await chrome.scripting.executeScript({
          target: { tabId: tab.id },
          files: ['content/po-open-asset.js'],
        });
        const res = await chrome.tabs.sendMessage(tab.id, {
          type: 'po:open-asset',
          instrument,
          display_name: displayName || '',
        });
        return res && typeof res === 'object' ? res : { ok: false, error: String(e) };
      } catch (e2) {
        return { ok: false, error: String(e2) };
      }
    }
  } finally {
    if (keepFloatOnTop) {
      // Пара уже переключена на PO — возвращаем список сигналов поверх.
      await refocusFloatWindow();
    }
  }
}

/**
 * Выполняется В СТРАНИЦЕ PO (MAIN world). Не замыкать внешние переменные.
 * @param {string} instrument
 * @param {string} displayName
 */
async function poSwitchAssetInPage(instrument, displayName) {
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const normText = (s) => String(s || '').replace(/\s+/g, ' ').trim();
  const normKey = (s) => normText(s).toLowerCase().replace(/[\s/_\-#.+%]/g, '');
  const matchLabel = (label, want) => {
    const a = normKey(label);
    const b = normKey(want);
    return !!a && !!b && (a === b || a.includes(b) || b.includes(a));
  };

  const ALIASES = {
    UKBrent_otc: ['Brent Oil OTC', 'Brent Oil', 'Brent'],
    UKBrent: ['Brent Oil OTC', 'Brent', 'Brent Oil'],
    USCrude_otc: ['WTI Crude Oil OTC', 'WTI Crude Oil', 'WTI'],
    USCrude: ['WTI Crude Oil OTC', 'WTI', 'WTI Crude Oil'],
    MATIC_otc: ['Polygon OTC', 'Polygon', 'MATIC'],
    MATIC: ['Polygon OTC', 'Polygon', 'MATIC'],
  };

  const names = [];
  const push = (v) => {
    const t = String(v || '').trim();
    if (t && !names.some((x) => x.toLowerCase() === t.toLowerCase())) {
      names.push(t);
    }
  };
  push(displayName);
  if (displayName) {
    push(displayName.replace(/\s*OTC\s*$/i, '').trim());
  }
  push(instrument);
  const base = instrument.replace(/_otc$/i, '').replace(/^#/, '');
  push(base);
  if (/^[A-Za-z]{6}$/.test(base)) {
    const a = base.slice(0, 3).toUpperCase();
    const b = base.slice(3).toUpperCase();
    push(`${a}/${b}`);
    push(`${a}/${b} OTC`);
  }
  for (const a of (ALIASES[instrument] || ALIASES[base] || [])) {
    push(a);
  }

  const queries = [];
  const addQ = (v) => {
    const t = String(v || '').trim();
    if (t && !queries.some((x) => x.toLowerCase() === t.toLowerCase())) {
      queries.push(t);
    }
  };
  if (displayName) {
    const noOtc = displayName.replace(/\s*OTC\s*$/i, '').trim();
    addQ(noOtc);
    addQ(noOtc.split(/\s+/)[0]);
  }
  for (const n of names) {
    if (n.includes('/')) {
      addQ(n.replace(/\s*OTC\s*$/i, '').trim());
    }
  }
  if (/brent/i.test(base + displayName)) {
    addQ('Brent');
  }
  if (/crude|wti/i.test(base + displayName)) {
    addQ('WTI');
  }
  if (/matic|polygon/i.test(base + displayName)) {
    addQ('Polygon');
  }
  addQ(base);

  const getCurrent = () => {
    const el = document.querySelector('.current-symbol');
    return el ? normText(el.textContent || '') : '';
  };

  const current0 = getCurrent();
  if (current0 && names.some((n) => matchLabel(current0, n))) {
    return { ok: true, instrument, already: true, matched: current0 };
  }

  async function ensureOpen() {
    if (document.body.classList.contains('open-assets-list')
      && document.querySelector('input.search__field')) {
      return true;
    }
    const toggle = document.querySelector('a.pair-number-wrap')
      || document.querySelector('.currencies-block a')
      || document.querySelector('.currencies-block');
    if (!toggle) {
      return false;
    }
    toggle.click();
    for (let i = 0; i < 12; i++) {
      await sleep(80);
      if (document.body.classList.contains('open-assets-list')
        && document.querySelector('input.search__field')) {
        return true;
      }
    }
    return !!document.querySelector('input.search__field');
  }

  async function typeSearch(query) {
    const input = document.querySelector('input.search__field');
    if (!(input instanceof HTMLInputElement)) {
      return false;
    }
    input.focus();
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    if (setter) {
      setter.call(input, '');
    } else {
      input.value = '';
    }
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await sleep(20);
    if (setter) {
      setter.call(input, query);
    } else {
      input.value = query;
    }
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('keyup', { bubbles: true }));
    return true;
  }

  function clickItem(query) {
    const items = Array.from(document.querySelectorAll('li.alist__item'));
    const scored = [];
    for (const li of items) {
      if (!(li instanceof HTMLElement)) {
        continue;
      }
      const label = normText(li.querySelector('.alist__label')?.textContent || '');
      if (!label) {
        continue;
      }
      let score = 0;
      for (const n of names) {
        if (matchLabel(label, n)) {
          score = Math.max(score, label.toLowerCase() === n.toLowerCase() ? 100 : 80);
        }
      }
      if (query && matchLabel(label, query)) {
        score = Math.max(score, 70);
      }
      if (/otc/i.test(label)) {
        score += 5;
      }
      if (score > 0) {
        scored.push({ li, label, score });
      }
    }
    if (!scored.length) {
      return null;
    }
    scored.sort((a, b) => b.score - a.score);
    const top = scored[0];
    const link = top.li.querySelector('a.alist__link');
    if (link instanceof HTMLElement) {
      link.click();
    } else {
      top.li.click();
    }
    return top.label;
  }

  /** Закрыть меню: клик по canvas графика «в стороне». */
  async function closeAssetsList() {
    if (!document.body.classList.contains('open-assets-list')) {
      return true;
    }
    const canvas = Array.from(document.querySelectorAll('canvas')).find((cv) => {
      const r = cv.getBoundingClientRect();
      return r.width > 200 && r.height > 200;
    });
    if (canvas) {
      const r = canvas.getBoundingClientRect();
      const x = r.left + r.width * 0.55;
      const y = r.top + r.height * 0.45;
      for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
        canvas.dispatchEvent(new MouseEvent(type, {
          bubbles: true,
          cancelable: true,
          clientX: x,
          clientY: y,
          view: window,
        }));
      }
      await sleep(200);
    }
    if (document.body.classList.contains('open-assets-list')) {
      const close = document.querySelector('.drop-down-modal--quotes-list .js-drop-down-modal-close')
        || document.querySelector('.drop-down-modal-wrap.active .js-drop-down-modal-close');
      if (close instanceof HTMLElement) {
        close.click();
        await sleep(150);
      }
    }
    return !document.body.classList.contains('open-assets-list');
  }

  for (const query of queries.slice(0, 6)) {
    const opened = await ensureOpen();
    if (!opened) {
      continue;
    }
    const typed = await typeSearch(query);
    if (!typed) {
      continue;
    }
    await sleep(450);
    const hit = clickItem(query);
    if (hit) {
      await sleep(400);
      await closeAssetsList();
      return {
        ok: true,
        instrument,
        matched: getCurrent() || hit,
        query,
        displayName,
      };
    }
  }

  await closeAssetsList();
  return {
    ok: false,
    error: 'not_found',
    instrument,
    displayName,
    names,
    queries,
    current: getCurrent(),
  };
}

/**
 * @param {string} instrument
 */
async function resolveDisplayName(instrument) {
  try {
    const cfg = await chrome.storage.sync.get({
      serverHost: DEFAULTS.serverHost,
      networkMode: DEFAULTS.networkMode,
    });
    const mode = inferNetworkMode(cfg.serverHost, cfg.networkMode);
    let host = resolveServerHost(mode);
    host = host.replace(/^wss?:\/\//i, 'http://').replace(/^https?:\/\//i, '');
    host = host.replace(/\/$/, '').replace(/\/extension-live.*$/i, '');
    const url = `http://${host}/api/extension/resolve?instrument=${encodeURIComponent(instrument)}`;
    const res = await fetch(url, { cache: 'no-store' });
    if (!res.ok) {
      return '';
    }
    const data = await res.json();
    return data && data.display_name ? String(data.display_name) : '';
  } catch (_) {
    return '';
  }
}

/**
 * @param {number} tabId
 * @param {number} timeoutMs
 */
function waitTabComplete(tabId, timeoutMs) {
  return new Promise((resolve) => {
    const started = Date.now();
    const tick = () => {
      chrome.tabs.get(tabId, (tab) => {
        if (chrome.runtime.lastError || !tab) {
          resolve(false);
          return;
        }
        if (tab.status === 'complete') {
          setTimeout(() => resolve(true), 800);
          return;
        }
        if (Date.now() - started > timeoutMs) {
          resolve(false);
          return;
        }
        setTimeout(tick, 300);
      });
    };
    tick();
  });
}
