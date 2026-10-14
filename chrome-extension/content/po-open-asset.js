/**
 * Content script Pocket Option: переключение актива.
 *
 * Реальный DOM PO (проверено на cabinet):
 *  - открыть список: a.pair-number-wrap (body.open-assets-list)
 *  - поиск: input.search__field[placeholder=Search]
 *  - строка: li.alist__item > a.alist__link > span.alist__label
 */
(function () {
  if (window.__platformSignalsPoHook) {
    return;
  }
  window.__platformSignalsPoHook = true;

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (!msg || msg.type !== 'po:open-asset') {
      return false;
    }
    const instrument = String(msg.instrument || '').trim();
    const displayName = String(msg.display_name || '').trim();
    if (!instrument) {
      sendResponse({ ok: false, error: 'empty_instrument' });
      return false;
    }
    switchAsset(instrument, displayName)
      .then((result) => sendResponse(result))
      .catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });

  /**
   * @param {string} instrument  напр. USCrude_otc
   * @param {string} displayName напр. WTI Crude Oil OTC
   */
  async function switchAsset(instrument, displayName) {
    const names = buildNames(instrument, displayName);
    const queries = buildQueries(instrument, displayName, names);
    console.info('[platform-signals] switchAsset', { instrument, displayName, queries, names });

    const current = getCurrentSymbol();
    if (current && names.some((n) => matchLabel(current, n))) {
      return { ok: true, instrument, already: true, matched: current };
    }

    for (const query of queries) {
      const opened = await ensureAssetsListOpen();
      if (!opened) {
        console.warn('[platform-signals] cannot open assets list');
        continue;
      }

      const typed = await typeSearch(query);
      if (!typed) {
        console.warn('[platform-signals] search__field missing');
        continue;
      }
      await sleep(500);

      const hit = clickListItem(names, query);
      if (hit) {
        await sleep(450);
        await closeAssetsList();
        const now = getCurrentSymbol();
        console.info('[platform-signals] switched', { hit, now, query });
        return {
          ok: true,
          instrument,
          matched: now || hit,
          query,
        };
      }
    }

    await closeAssetsList();
    console.warn('[platform-signals] asset not found', instrument, names, queries);
    return { ok: false, error: 'not_found', instrument, displayName, names, queries };
  }

  /** Закрыть меню выбора пары кликом по canvas графика. */
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

  function getCurrentSymbol() {
    const el = document.querySelector('.current-symbol');
    return el ? normText(el.textContent || '') : '';
  }

  async function ensureAssetsListOpen() {
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
    for (let i = 0; i < 10; i++) {
      await sleep(100);
      if (document.body.classList.contains('open-assets-list')
        && document.querySelector('input.search__field')) {
        return true;
      }
    }
    return !!document.querySelector('input.search__field');
  }

  /**
   * @param {string} query
   */
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
    await sleep(30);
    if (setter) {
      setter.call(input, query);
    } else {
      input.value = query;
    }
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('keyup', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  /**
   * @param {string[]} names
   * @param {string} query
   */
  function clickListItem(names, query) {
    const items = Array.from(document.querySelectorAll('li.alist__item'));
    if (!items.length) {
      return null;
    }

    /** @type {{li: HTMLElement, label: string, score: number}[]} */
    const scored = [];
    for (const li of items) {
      if (!(li instanceof HTMLElement)) {
        continue;
      }
      const labelEl = li.querySelector('.alist__label');
      const label = normText(labelEl?.textContent || '');
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
      // Предпочитаем OTC-версию при равном счёте.
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

  /**
   * @param {string} instrument
   * @param {string} displayName
   */
  function buildNames(instrument, displayName) {
    const out = [];
    const push = (v) => {
      const t = String(v || '').trim();
      if (t && !out.some((x) => x.toLowerCase() === t.toLowerCase())) {
        out.push(t);
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
    const aliases = ALIASES[instrument] || ALIASES[base] || [];
    for (const a of aliases) {
      push(a);
    }
    return out;
  }

  /**
   * @param {string} instrument
   * @param {string} displayName
   * @param {string[]} names
   */
  function buildQueries(instrument, displayName, names) {
    const q = [];
    const add = (v) => {
      const t = String(v || '').trim();
      if (t && !q.some((x) => x.toLowerCase() === t.toLowerCase())) {
        q.push(t);
      }
    };
    if (displayName) {
      const noOtc = displayName.replace(/\s*OTC\s*$/i, '').trim();
      add(noOtc);
      add(noOtc.split(/\s+/)[0]);
    }
    for (const n of names) {
      if (n.includes('/')) {
        add(n.replace(/\s*OTC\s*$/i, '').trim());
      }
    }
    const base = instrument.replace(/_otc$/i, '').replace(/^#/, '');
    if (/brent/i.test(base + displayName)) {
      add('Brent');
    }
    if (/crude|wti/i.test(base + displayName)) {
      add('WTI');
    }
    if (/matic|polygon/i.test(base + displayName)) {
      add('Polygon');
    }
    add(base);
    return q.slice(0, 6);
  }

  /** @type {Record<string, string[]>} */
  const ALIASES = {
    UKBrent_otc: ['Brent Oil OTC', 'Brent Oil', 'Brent'],
    UKBrent: ['Brent Oil OTC', 'Brent Oil', 'Brent'],
    USCrude_otc: ['WTI Crude Oil OTC', 'WTI Crude Oil', 'WTI'],
    USCrude: ['WTI Crude Oil OTC', 'WTI Crude Oil', 'WTI'],
    MATIC_otc: ['Polygon OTC', 'Polygon', 'MATIC'],
    MATIC: ['Polygon OTC', 'Polygon', 'MATIC'],
  };

  /**
   * @param {string} label
   * @param {string} want
   */
  function matchLabel(label, want) {
    const a = normKey(label);
    const b = normKey(want);
    if (!a || !b) {
      return false;
    }
    return a === b || a.includes(b) || b.includes(a);
  }

  function normText(s) {
    return String(s || '').replace(/\s+/g, ' ').trim();
  }

  function normKey(s) {
    return normText(s).toLowerCase().replace(/[\s/_\-#.+%]/g, '');
  }

  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }
})();
