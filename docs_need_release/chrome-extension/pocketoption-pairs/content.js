/**
 * PocketOption — горячие клавиши переключения активов.
 * Alt+Q — вверх, Alt+W — вниз по включённым категориям
 * (Валюты / Криптовалюты / Акции).
 * Пропускаются: недоступные, payout < 75%, и по фильтрам OTC из настроек.
 */
(function () {
  "use strict";

  /** Селекторы UI каталога активов Pocket Option */
  const PO = {
    /** Кнопка текущей пары (открывает/закрывает каталог) */
    pairToggle: "a.pair-number-wrap",
    /** Блок с текущей парой в шапке */
    currenciesBlock: ".currencies-block",
    /** Имя текущей пары в шапке */
    currentSymbol: ".current-symbol",
    /** Корневой блок каталога активов */
    assetsBlock: ".assets-block",
    /** Пункты навигации категорий */
    navItem: ".assets-block__nav-item, a.assets-block__nav-item",
    navItemLabel: ".assets-block__nav-item-label",
    /** Элемент пары в списке */
    listItem: ".alist__item",
    /** Кликабельная ссылка пары */
    listLink: ".alist__link",
    /** Подпись названия пары */
    listLabel: ".alist__label",
    /** Выплата (например «+92%») */
    listPayout: ".alist__payout",
  };

  /**
   * Категории каталога PO: класс вкладки + класс списка + подписи RU/EN.
   * Порядок = порядок обхода при Alt+Q / Alt+W.
   */
  const CATEGORIES = [
    {
      id: "currency",
      title: "Валюты",
      navClass: "assets-block__nav-item--currency",
      listSelector:
        ".alist.alist-currency, .assets-block__alist.alist-currency",
      labelRe: [/^Валюты$/i, /^Currencies$/i],
    },
    {
      id: "cryptocurrency",
      title: "Криптовалюты",
      navClass: "assets-block__nav-item--cryptocurrency",
      listSelector:
        ".alist.alist-cryptocurrency, .assets-block__alist.alist-cryptocurrency",
      labelRe: [/^Криптовалюты$/i, /^Cryptocurrenc/i],
    },
    {
      id: "stock",
      title: "Акции",
      navClass: "assets-block__nav-item--stock",
      listSelector: ".alist.alist-stock, .assets-block__alist.alist-stock",
      labelRe: [/^Акции$/i, /^Stocks?$/i],
    },
  ];

  /** Класс body, когда каталог активов открыт */
  const OPEN_CLASS = "open-assets-list";
  /** Класс активной (текущей) пары в списке */
  const ACTIVE_CLASS = "alist__item--active";
  /** Класс недоступной пары — такие пропускаем */
  const NO_ACTIVE_CLASS = "alist__item--no-active";
  /** Минимальная выплата (%) — пары ниже порога не выбираем */
  const MIN_PAYOUT_PERCENT = 75;

  const CABINET_PATH_RE = /\/cabinet(?:\/|$)/i;
  const MAX_OPEN_RETRIES = 12;
  const OPEN_RETRY_MS = 80;
  const BOOT_POLL_MS = 2000;
  const BOOT_POLL_MAX = 12;

  /** Alt+Q = вверх (prev), Alt+W = вниз (next) */
  const DEFAULT_SHORTCUTS = {
    prev: { alt: true, shift: false, ctrl: false, meta: false, key: "q", code: "KeyQ" },
    next: { alt: true, shift: false, ctrl: false, meta: false, key: "w", code: "KeyW" },
  };

  /**
   * Фильтры из popup:
   * - skipOtc / skipNonOtc — OTC-фильтры
   * - categories — какие вкладки участвуют в переключении
   */
  const DEFAULT_FILTERS = {
    skipOtc: false,
    skipNonOtc: false,
    categories: {
      currency: true,
      cryptocurrency: true,
      stock: true,
    },
  };

  /** Слить сохранённые фильтры с дефолтами (старые установки без categories) */
  function mergeFilters(raw) {
    const next = {
      ...DEFAULT_FILTERS,
      ...(raw || {}),
      categories: {
        ...DEFAULT_FILTERS.categories,
        ...((raw && raw.categories) || {}),
      },
    };
    return next;
  }

  const isTopFrame = window === window.top;

  let shortcuts = { ...DEFAULT_SHORTCUTS };
  /** Текущие фильтры OTC + категории (обновляются из storage) */
  let filters = mergeFilters(DEFAULT_FILTERS);
  let toastEl = null;
  let toastTimer = null;
  let busy = false;
  let busySince = 0;
  let activated = false;
  let bootPollTimer = null;
  /** Время обработки Alt+Q/W из keydown — чтобы chrome.commands не дублировал */
  let lastKeyHandledAt = 0;

  try {
    chrome.storage.sync.get(
      { pairShortcuts: DEFAULT_SHORTCUTS, pairFilters: DEFAULT_FILTERS },
      (data) => {
        if (data?.pairShortcuts) {
          shortcuts = { ...DEFAULT_SHORTCUTS, ...data.pairShortcuts };
        }
        if (data?.pairFilters) {
          filters = mergeFilters(data.pairFilters);
        }
      }
    );
    chrome.storage.onChanged.addListener((changes, area) => {
      if (area !== "sync") return;
      if (changes.pairShortcuts?.newValue) {
        shortcuts = { ...DEFAULT_SHORTCUTS, ...changes.pairShortcuts.newValue };
      }
      if (changes.pairFilters?.newValue) {
        filters = mergeFilters(changes.pairFilters.newValue);
        console.info("[PO Pairs] фильтры:", filters);
      }
    });
  } catch (_) {}

  /** Есть ли в этом фрейме переключатель пары */
  function hasPairUi() {
    return !!(
      document.querySelector(PO.pairToggle) ||
      document.querySelector(PO.currenciesBlock)
    );
  }

  /** Нужен ли этому фрейму content script */
  function shouldRunInThisFrame() {
    if (hasPairUi()) return true;
    return isTopFrame && CABINET_PATH_RE.test(location.pathname);
  }

  if (!shouldRunInThisFrame()) {
    return;
  }

  /** Сообщения от background (chrome.commands) — только в фрейме с UI пары */
  if (typeof chrome !== "undefined" && chrome.runtime?.onMessage) {
    chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
      if (msg?.type === "SWITCH_PAIR" && msg.direction) {
        // Не гоняем переключение в каждом iframe: только там, где есть кнопка пары
        if (!hasPairUi()) {
          sendResponse({ ok: false, skipped: "no-ui" });
          return false;
        }
        switchPair(msg.direction, { fromCommand: true }).then((ok) =>
          sendResponse({ ok: !!ok })
        );
        return true;
      }
      return false;
    });
  }

  function activateFrame() {
    if (activated) return;
    activated = true;
    if (bootPollTimer) {
      clearTimeout(bootPollTimer);
      bootPollTimer = null;
    }
    document.documentElement.setAttribute(
      "data-po-pairs",
      isTopFrame ? "1" : "iframe"
    );
  }

  /** Ждём появления UI пары редким poll (без MutationObserver) */
  function startBootPoll(attempt) {
    if (activated) return;
    const n = attempt ?? 0;
    if (hasPairUi()) {
      activateFrame();
      return;
    }
    if (n >= BOOT_POLL_MAX) return;
    bootPollTimer = setTimeout(() => startBootPoll(n + 1), BOOT_POLL_MS);
  }

  function isVisible(el) {
    if (!el?.getBoundingClientRect) return false;
    if (el.getAttribute("aria-hidden") === "true") return false;
    const style = window.getComputedStyle(el);
    if (style.display === "none" || style.visibility === "hidden") return false;
    if (parseFloat(style.opacity) < 0.05) return false;
    const rect = el.getBoundingClientRect();
    return rect.width > 2 && rect.height > 2;
  }

  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  /** Нормализованный текст элемента */
  function normText(el) {
    return (el?.textContent || "").replace(/\s+/g, " ").trim();
  }

  /** Имя текущей пары из шапки (например «NZD/USD OTC») */
  function getCurrentPairName() {
    const el = document.querySelector(PO.currentSymbol);
    return el ? normText(el) : null;
  }

  /** Открыт ли каталог активов */
  function isAssetsListOpen() {
    if (document.body.classList.contains(OPEN_CLASS)) return true;
    const block = document.querySelector(PO.assetsBlock);
    // Любой видимый список категории (валюты / крипта / акции)
    return !!(
      block &&
      isVisible(block) &&
      block.querySelector(".alist, .assets-block__alist")
    );
  }

  /** Кнопка открытия каталога */
  function getPairToggle() {
    const a = document.querySelector(PO.pairToggle);
    if (a && isVisible(a)) return a;
    const block = document.querySelector(PO.currenciesBlock);
    if (!block) return null;
    return block.querySelector("a") || block;
  }

  /**
   * Лёгкий клик — одно событие как у человека.
   * Раньше reliableClick слал 6 событий (pointer/mouse/click + native) → пачка act/inter/analytics.
   */
  function lightClick(el) {
    if (!el) return false;
    if (typeof el.click === "function") {
      el.click();
      return true;
    }
    el.dispatchEvent(
      new MouseEvent("click", { bubbles: true, cancelable: true, view: window })
    );
    return true;
  }

  /** Открыть каталог активов, если закрыт (один клик, без повторных) */
  async function ensureAssetsListOpen() {
    if (isAssetsListOpen()) return true;

    const toggle = getPairToggle();
    if (!toggle) return false;

    lightClick(toggle);

    for (let i = 0; i < MAX_OPEN_RETRIES; i++) {
      await sleep(OPEN_RETRY_MS);
      if (isAssetsListOpen()) return true;
    }
    return isAssetsListOpen();
  }

  /**
   * Точка на графике справа от панели активов — PO закрывает каталог по mousedown.
   */
  function getCloseClickPoint() {
    const canvases = Array.from(document.querySelectorAll("canvas")).filter(isVisible);
    let best = null;
    let bestArea = 0;
    for (const c of canvases) {
      const r = c.getBoundingClientRect();
      const area = r.width * r.height;
      if (area > bestArea) {
        best = r;
        bestArea = area;
      }
    }
    if (best && best.width > 80) {
      return { x: best.right - 80, y: best.top + best.height / 2 };
    }

    const assets = document.querySelector(PO.assetsBlock);
    if (assets && isVisible(assets)) {
      const r = assets.getBoundingClientRect();
      return {
        x: Math.min(window.innerWidth - 24, r.right + 80),
        y: r.top + Math.min(180, Math.max(40, r.height / 3)),
      };
    }
    return null;
  }

  /**
   * Закрыть каталог одним mousedown по графику (как руками).
   * Не шлём серию pointer/mouse/click — это плодило 5–8 запросов.
   */
  async function closeAssetsList() {
    await sleep(60);
    if (!isAssetsListOpen()) return true;

    const pt = getCloseClickPoint();
    if (!pt) return !isAssetsListOpen();

    const el = document.elementFromPoint(pt.x, pt.y) || document.body;
    el.dispatchEvent(
      new MouseEvent("mousedown", {
        bubbles: true,
        cancelable: true,
        view: window,
        clientX: pt.x,
        clientY: pt.y,
        button: 0,
      })
    );
    await sleep(100);
    return !isAssetsListOpen();
  }

  /** Категория по id из CATEGORIES */
  function getCategory(id) {
    return CATEGORIES.find((c) => c.id === id) || null;
  }

  /** Включённые в настройках категории (в порядке CATEGORIES) */
  function getEnabledCategories() {
    const cats = filters.categories || DEFAULT_FILTERS.categories;
    return CATEGORIES.filter((c) => !!cats[c.id]);
  }

  /** Найти DOM-элемент вкладки категории */
  function findCategoryNav(cat) {
    if (!cat) return null;
    const byClass = document.querySelector(`.${cat.navClass}, a.${cat.navClass}`);
    if (byClass) return byClass;

    for (const a of document.querySelectorAll(PO.navItem)) {
      const label = normText(a.querySelector(PO.navItemLabel) || a);
      if (cat.labelRe.some((re) => re.test(label))) return a;
    }
    return null;
  }

  /** Активна ли вкладка данной категории */
  function isCategoryTabActive(cat) {
    const nav = findCategoryNav(cat);
    return !!(nav && nav.classList.contains("assets-block__nav-item--active"));
  }

  /**
   * Какая вкладка сейчас активна в UI (без кликов).
   * @returns {object|null} элемент CATEGORIES
   */
  function getActiveCategory() {
    for (const cat of CATEGORIES) {
      if (isCategoryTabActive(cat)) return cat;
    }
    return null;
  }

  /**
   * Переключить вкладку категории — только если она ещё не активна.
   * Один lightClick, без повторных нажатий.
   */
  async function ensureCategoryTab(cat) {
    if (!cat) return false;
    if (isCategoryTabActive(cat) && document.querySelector(cat.listSelector)) {
      return true;
    }

    const nav = findCategoryNav(cat);
    if (!nav) return false;

    lightClick(nav);
    for (let i = 0; i < 10; i++) {
      await sleep(50);
      if (isCategoryTabActive(cat) && document.querySelector(cat.listSelector)) {
        return true;
      }
    }
    return !!document.querySelector(cat.listSelector);
  }

  /**
   * Число payout из пункта списка («+92%» → 92, «41%» → 41).
   * @returns {number|null}
   */
  function parseItemPayout(item) {
    const el = item.querySelector(PO.listPayout);
    if (!el) return null;
    const raw = (el.textContent || "").replace(/\s/g, "");
    const m = raw.match(/(\d+(?:[.,]\d+)?)/);
    if (!m) return null;
    const n = parseFloat(m[1].replace(",", "."));
    return Number.isFinite(n) ? n : null;
  }

  /** Имя пары из элемента списка */
  function getItemLabel(item) {
    const label = item.querySelector(PO.listLabel);
    return label ? normText(label) : normText(item).split("\n")[0].trim();
  }

  /** OTC-пара: в названии есть «OTC» */
  function isOtcPair(name) {
    return /\bOTC\b|_otc/i.test(name || "");
  }

  /** Совпадает ли label пункта с именем из шапки */
  function labelMatchesCurrent(label, currentName) {
    if (!label || !currentName) return false;
    return (
      label === currentName ||
      label.includes(currentName) ||
      currentName.includes(label)
    );
  }

  /**
   * Проходит ли элемент фильтры: доступность, payout, OTC.
   */
  function passesItemFilters(item) {
    if (item.classList.contains(NO_ACTIVE_CLASS)) return false;
    const link = item.querySelector(PO.listLink);
    if (!link || !isVisible(item)) return false;

    const payout = parseItemPayout(item);
    if (payout == null || payout < MIN_PAYOUT_PERCENT) return false;

    const name = getItemLabel(item);
    const otc = isOtcPair(name);
    if (filters.skipOtc && otc) return false;
    if (filters.skipNonOtc && !otc) return false;

    return true;
  }

  /**
   * Кликабельные пункты уже открытого списка категории (без сетевых запросов).
   */
  function getSelectableItemsInList(cat) {
    const list = document.querySelector(cat.listSelector);
    if (!list) return [];
    return Array.from(list.querySelectorAll(PO.listItem)).filter(passesItemFilters);
  }

  /**
   * Индекс текущей пары в списке: сначала .alist__item--active, иначе имя из шапки.
   */
  function findCurrentIndex(items) {
    const activeIdx = items.findIndex((item) =>
      item.classList.contains(ACTIVE_CLASS)
    );
    if (activeIdx >= 0) return activeIdx;

    const currentName = getCurrentPairName();
    if (!currentName) return -1;

    return items.findIndex((item) =>
      labelMatchesCurrent(getItemLabel(item), currentName)
    );
  }

  /** Есть ли текущий актив в уже открытом списке категории (для analyze) */
  function listContainsCurrent(cat) {
    const list = document.querySelector(cat.listSelector);
    if (!list) return false;
    if (list.querySelector(`.${ACTIVE_CLASS}`)) return true;
    const currentName = getCurrentPairName();
    if (!currentName) return false;
    for (const item of list.querySelectorAll(PO.listItem)) {
      if (labelMatchesCurrent(getItemLabel(item), currentName)) return true;
    }
    return false;
  }

  /**
   * Соседняя включённая категория (для перехода на краю списка).
   */
  function neighborCategory(cat, direction, enabled) {
    if (!enabled.length) return null;
    const idx = enabled.findIndex((c) => c.id === cat.id);
    if (idx < 0) return enabled[0];
    if (direction === "next") {
      return enabled[(idx + 1) % enabled.length];
    }
    return enabled[(idx - 1 + enabled.length) % enabled.length];
  }

  /**
   * Рабочая категория без единого клика по вкладкам:
   * PO при открытии каталога уже показывает вкладку текущего актива.
   * Перебор Валюты/Крипта/Акции запрещён — это и давало 8 запросов.
   */
  function resolveWorkingCategory(enabled) {
    const active = getActiveCategory();
    if (active && enabled.some((c) => c.id === active.id)) return active;
    return enabled[0] || null;
  }

  /** Клик по пункту списка — один native click */
  function clickListItem(item) {
    if (!item) return false;
    const link = item.querySelector(PO.listLink) || item;
    return lightClick(link);
  }

  function showToast(message, isError) {
    if (!isTopFrame) return;
    if (!toastEl) {
      toastEl = document.createElement("div");
      toastEl.className = "po-pairs-toast";
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = message;
    toastEl.classList.toggle("po-pairs-toast--error", !!isError);
    toastEl.classList.add("po-pairs-toast--visible");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(
      () => toastEl.classList.remove("po-pairs-toast--visible"),
      1600
    );
  }

  /**
   * Переключение актива.
   * Типичный путь (как руками): открыть список → клик по соседнему активу → закрыть.
   * Вкладку категории трогаем максимум один раз — только на краю списка.
   * @param {"prev"|"next"} direction
   * @param {{fromCommand?: boolean}} [opts]
   */
  async function switchPair(direction, opts) {
    // Синхронный busy сразу — иначе Alt+Q сработает и из keydown, и из chrome.commands
    if (busy && Date.now() - busySince < 900) return false;
    busy = true;
    busySince = Date.now();

    // Команда из background дублирует keydown — пропускаем, если клавиша уже обработана
    if (opts?.fromCommand && Date.now() - lastKeyHandledAt < 700) {
      busy = false;
      return true;
    }

    try {
      activateFrame();

      // Только фрейм с реальным UI каталога
      if (!hasPairUi()) return false;

      const enabled = getEnabledCategories();
      if (!enabled.length) {
        showToast("Включите хотя бы одну категорию в настройках", true);
        return false;
      }

      const opened = await ensureAssetsListOpen();
      if (!opened) {
        showToast("Каталог пар не найден", true);
        return false;
      }

      // Только текущая вкладка UI — без обхода категорий
      let cat = resolveWorkingCategory(enabled);
      if (!cat) {
        showToast("Категория не найдена", true);
        return false;
      }

      // Кликать по вкладке только если нужная категория ещё не активна
      if (!isCategoryTabActive(cat)) {
        if (!(await ensureCategoryTab(cat))) {
          showToast(`Вкладка «${cat.title}» не найдена`, true);
          return false;
        }
      }

      let items = getSelectableItemsInList(cat);
      let curIdx = findCurrentIndex(items);
      let target = null;
      /** Нужен ли один клик по соседней вкладке на краю списка */
      let needCategoryHop = false;
      let hopCat = null;

      if (!items.length) {
        hopCat = neighborCategory(cat, direction, enabled);
        needCategoryHop = !!(hopCat && hopCat.id !== cat.id);
      } else if (curIdx < 0) {
        target = direction === "next" ? items[0] : items[items.length - 1];
      } else if (direction === "next" && curIdx >= items.length - 1) {
        hopCat = neighborCategory(cat, "next", enabled);
        if (hopCat && hopCat.id !== cat.id) needCategoryHop = true;
        else target = items[0];
      } else if (direction === "prev" && curIdx <= 0) {
        hopCat = neighborCategory(cat, "prev", enabled);
        if (hopCat && hopCat.id !== cat.id) needCategoryHop = true;
        else target = items[items.length - 1];
      } else {
        target = items[direction === "next" ? curIdx + 1 : curIdx - 1];
      }

      // Максимум один переход на другую вкладку (Валюты→Крипта→Акции)
      if (needCategoryHop && hopCat) {
        if (await ensureCategoryTab(hopCat)) {
          const hopItems = getSelectableItemsInList(hopCat);
          if (hopItems.length) {
            cat = hopCat;
            items = hopItems;
            target =
              direction === "next" ? items[0] : items[items.length - 1];
          }
        }
        if (!target && items.length) {
          target = direction === "next" ? items[0] : items[items.length - 1];
        }
      }

      if (!target) {
        showToast("Нет доступных активов по фильтрам", true);
        return false;
      }

      const name = getItemLabel(target);
      // Единственный «торговый» клик — выбор актива
      clickListItem(target);

      await closeAssetsList();

      showToast(name);
      console.info(`[PO Pairs] ${direction}: ${name} [${cat.title}]`);
      return true;
    } catch (err) {
      console.warn("[PO Pairs] switchPair:", err);
      showToast("Ошибка переключения", true);
      return false;
    } finally {
      setTimeout(() => {
        busy = false;
      }, 280);
    }
  }

  function isTypingContext(target) {
    if (!target || !target.tagName) return false;
    const tag = target.tagName.toUpperCase();
    if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return true;
    return !!target.isContentEditable;
  }

  function matchShortcut(event, binding) {
    if (!binding) return false;
    if (!!event.altKey !== !!binding.alt) return false;
    if (!!event.shiftKey !== !!binding.shift) return false;
    if (!!event.ctrlKey !== !!binding.ctrl) return false;
    if (!!event.metaKey !== !!binding.meta) return false;

    const key = (event.key || "").toLowerCase();
    const code = event.code || "";
    if (binding.code && code === binding.code) return true;
    if (binding.key && key === binding.key.toLowerCase()) return true;
    return false;
  }

  function installHotkeys() {
    if (installHotkeys.done) return;
    installHotkeys.done = true;

    const onKeyDown = (event) => {
      if (isTypingContext(event.target)) return;
      for (const [name, binding] of Object.entries(shortcuts)) {
        if (!matchShortcut(event, binding)) continue;
        event.preventDefault();
        event.stopImmediatePropagation();
        // Помечаем, что клавиша уже обработана — background command пропустит дубль
        lastKeyHandledAt = Date.now();
        switchPair(name, { fromCommand: false });
        return;
      }
    };

    window.addEventListener("keydown", onKeyDown, true);
    document.addEventListener("keydown", onKeyDown, true);

    console.info(
      `[PO Pairs] Alt+Q ↑ / Alt+W ↓ (light clicks) (${isTopFrame ? "top" : "iframe"})`
    );
  }

  /** Диагностика из консоли: await __poPairs.analyze() */
  function analyze() {
    const enabled = getEnabledCategories().map((c) => c.id);
    const active = getActiveCategory();
    const items = active ? getSelectableItemsInList(active) : [];
    return {
      frame: isTopFrame ? "top" : "iframe",
      activated,
      pairToggle: !!getPairToggle(),
      currentPair: getCurrentPairName(),
      listOpen: isAssetsListOpen(),
      activeCategory: active?.id || null,
      minPayout: MIN_PAYOUT_PERCENT,
      filters: mergeFilters(filters),
      enabledCategories: enabled,
      selectableInActiveTab: items.length,
      currentIndex: items.length ? findCurrentIndex(items) : -1,
      sample: items.slice(0, 5).map((it) => ({
        label: getItemLabel(it),
        payout: parseItemPayout(it),
      })),
    };
  }

  function boot() {
    if (hasPairUi()) activateFrame();
    else startBootPoll(0);

    installHotkeys();

    // API для отладки в F12
    try {
      window.__poPairs = {
        switchPair,
        analyze,
        prev: () => switchPair("prev"),
        next: () => switchPair("next"),
      };
    } catch (_) {}
  }

  boot();
})();
