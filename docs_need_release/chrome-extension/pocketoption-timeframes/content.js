/**
 * PocketOption — горячие клавиши таймфреймов (оптимизированное переключение).
 * Минимум кликов: 0 если TF уже выбран, 2 если меню закрыто (открыть + выбрать).
 */
(function () {
  "use strict";

  const TF_SECONDS = { S15: 15, M1: 60, M5: 300, M15: 900, M30: 1800, H1: 3600 };

  const PO = {
    chartToggle:
      "a.items__link.items__link--chart-type:not(.chart-layout-options-btn)",
    chartToggleWithCandles:
      "a.items__link--chart-type:not(.chart-layout-options-btn) .candles-icon",
    toolbarItems: "div.items",
    dropdown:
      ".drop-down-modal, [class*='drop-down-modal'], [class*='dropdown-modal']",
    intervalLink: "a.items__link, [class*='items__link']",
    currentTfBadge:
      "a.items__link--chart-type:not(.chart-layout-options-btn) .counter__icon",
  };

  const INTERVAL_HEADINGS = ["Интервал", "Interval", "Timeframe", "Time frame"];
  const MAX_RETRIES = 5;
  const RETRY_MS = 90;
  const OPEN_MAX_RETRIES = 15;
  const OPEN_RETRY_MS = 120;
  const CACHE_MS = 3000;
  /** Интервал опроса появления кнопки свечей (без MutationObserver — он грузил CPU на PO) */
  const BOOT_POLL_MS = 2000;
  const BOOT_POLL_MAX = 12;
  /** Сколько «щелчков» колеса вниз после смены TF (уменьшение / отдаление графика) */
  const ZOOM_OUT_WHEEL_TICKS = 20;
  /** deltaY одного щелчка (как у типичной мыши) */
  const ZOOM_OUT_DELTA_Y = 100;
  /** Задержка перед зумом — дать PO закрыть меню / перерисовать */
  const ZOOM_OUT_DELAY_MS = 280;
  /** Отступ от правого края canvas внутрь (точка прокрутки) */
  const ZOOM_POINT_RIGHT_INSET_PX = 40;
  /** Сколько показывать точку центра прокрутки */
  const WHEEL_MARKER_MS = 900;

  /** Только страницы кабинета — иначе content.js не должен ничего делать */
  /** /ru/cabinet, /ru/cabinet/demo-quick-high-low/ и т.д. */
  const CABINET_PATH_RE = /\/cabinet(?:\/|$)/i;

  const SHORTCUTS_VERSION = PoTfConfig.SHORTCUTS_VERSION;

  /** Настраиваемые кнопки: таймфрейм + сочетание клавиш */
  let tfBindings = PoTfConfig.cloneBindings(PoTfConfig.DEFAULT_BINDINGS);

  const isTopFrame = window === window.top;
  let toastEl = null;
  let toastTimer = null;
  let busy = false;
  let busySince = 0;
  let perfLog = false;

  let cachedToggle = null;
  let cachedDropdown = null;
  let cacheTs = 0;
  /** Фрейм с графиком уже инициализирован (hotkeys + маркер DOM) */
  let activated = false;
  let bootPollTimer = null;

  try {
    chrome.storage.sync.get(
      { tfBindings: null, shortcuts: null, shortcutsVersion: 0 },
      (data) => {
        tfBindings = PoTfConfig.normalizeStorageData(data);
        if ((data?.shortcutsVersion || 0) < SHORTCUTS_VERSION) {
          chrome.storage.sync.set({
            tfBindings: PoTfConfig.cloneBindings(tfBindings),
            shortcutsVersion: SHORTCUTS_VERSION,
          });
        }
      }
    );
    chrome.storage.onChanged.addListener((changes, area) => {
      if (area !== "sync") return;
      if (changes.tfBindings?.newValue) {
        tfBindings = PoTfConfig.cloneBindings(changes.tfBindings.newValue);
      } else if (changes.shortcutsVersion?.newValue) {
        chrome.storage.sync.get(
          { tfBindings: null, shortcuts: null, shortcutsVersion: 0 },
          (stored) => {
            tfBindings = PoTfConfig.normalizeStorageData(stored);
          }
        );
      }
    });
  } catch (_) {}

  /** Быстрая проверка без getComputedStyle — только для boot / «есть ли график в этом фрейме» */
  function hasChartToggleLite() {
    if (document.querySelector(PO.chartToggleWithCandles)) return true;
    const link = document.querySelector(PO.chartToggle);
    return !!(link && link.querySelector(".candles-icon, .counter__icon"));
  }

  /** Нужен ли этому фрейму content script (top кабинета или iframe с кнопкой свечей) */
  function shouldRunInThisFrame() {
    if (hasChartToggleLite()) return true;
    return isTopFrame && CABINET_PATH_RE.test(location.pathname);
  }

  if (!shouldRunInThisFrame()) {
    return;
  }

  if (typeof chrome !== "undefined" && chrome.runtime?.onMessage) {
    chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
      if (msg?.type === "SET_TIMEFRAME" && msg.timeframe) {
        const ok = setTimeframe(msg.timeframe);
        sendResponse({ ok: ok !== false });
      }
      return true;
    });
  }

  /** Есть ли в этом фрейме тулбар графика / выпадающее меню */
  function frameHasChartUi() {
    return !!(getChartTypeToggle() || findChartDropdown(false));
  }

  /** Команды из консоли F12 (page-bridge.js, MAIN world) */
  document.addEventListener("po-tf-cmd", async (event) => {
    const { id, cmd, timeframe } = event.detail || {};
    let result = null;

    try {
      switch (cmd) {
        case "setTimeframe": {
          const ok = setTimeframe(timeframe);
          result = { ok: ok !== false, timeframe, frame: isTopFrame ? "top" : "iframe" };
          break;
        }
        case "testCloseMethods":
          result = await testCloseMethods();
          break;
        case "openMenu":
          result = { ok: await ensureChartMenuOpenAsync() };
          break;
        case "closeMenu":
          result = { ok: closeChartSettingsMenu(false) };
          break;
        case "getCurrentTf":
          result = { tf: getCurrentTimeframeFromToolbar() };
          break;
        case "analyzePage":
          result = analyzePage();
          break;
        case "ping":
          result = {
            ok: true,
            marker: document.documentElement.getAttribute("data-po-timeframes"),
            path: location.pathname,
            activated,
            toggle: !!getChartTypeToggle(),
          };
          break;
        case "zoomOut":
          result = { ok: zoomChartOut() };
          break;
        default:
          result = { error: "unknown cmd" };
      }
    } catch (err) {
      result = { error: String(err) };
    }

    if (!isTopFrame) {
      try {
        window.top.postMessage(
          { type: "po-tf-bridge-response", id, result },
          "*"
        );
      } catch (_) {}
      return;
    }

    document.dispatchEvent(
      new CustomEvent("po-tf-response", { detail: { id, result } })
    );
  });

  if (isTopFrame) {
    window.addEventListener("message", (event) => {
      const data = event.data;
      if (!data || data.type !== "po-tf-bridge-response" || !data.id) return;
      document.dispatchEvent(
        new CustomEvent("po-tf-response", {
          detail: { id: data.id, result: data.result },
        })
      );
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
      "data-po-timeframes",
      isTopFrame ? "1" : "iframe"
    );
  }

  /**
   * Ждём появления кнопки свечей редким poll (2 с), без MutationObserver на всём DOM.
   * PO постоянно меняет DOM → observer вызывал frameHasChartUi() десятки раз в секунду.
   */
  function startBootPoll(attempt) {
    if (activated) return;
    const n = attempt ?? 0;

    if (hasChartToggleLite() || frameHasChartUi()) {
      activateFrame();
      return;
    }
    if (n >= BOOT_POLL_MAX) return;

    bootPollTimer = setTimeout(() => startBootPoll(n + 1), BOOT_POLL_MS);
  }

  function logPerf(label, t0) {
    if (!perfLog) return;
    console.info(`[PO Timeframes] ${label}: ${(performance.now() - t0).toFixed(1)} ms`);
  }

  function invalidateDomCache() {
    cachedToggle = null;
    cachedDropdown = null;
    cacheTs = 0;
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

  function normText(el) {
    return (el?.textContent || "").replace(/\s+/g, " ").trim();
  }

  function directText(el) {
    if (!el) return "";
    let t = "";
    for (const n of el.childNodes) {
      if (n.nodeType === Node.TEXT_NODE) t += n.textContent;
    }
    return t.replace(/\s+/g, " ").trim() || normText(el);
  }

  function getChartTypeToggle() {
    const byClass = document.querySelector(PO.chartToggle);
    if (byClass && isVisible(byClass)) return byClass;

    const candles = document.querySelector(PO.chartToggleWithCandles);
    if (candles) {
      const link = candles.closest("a.items__link--chart-type");
      if (link && !link.classList.contains("chart-layout-options-btn") && isVisible(link)) {
        return link;
      }
    }

    for (const a of document.querySelectorAll("a.items__link--chart-type")) {
      if (a.classList.contains("chart-layout-options-btn")) continue;
      if (a.querySelector(".candles-icon, .counter__icon") && isVisible(a)) return a;
    }

    for (const a of document.querySelectorAll("a.items__link, a[class*='items__link']")) {
      if (a.classList.contains("chart-layout-options-btn")) continue;
      if (!a.querySelector(".candles-icon")) continue;
      if (isVisible(a)) return a;
    }
    return null;
  }

  function getChartTypeToggleCached() {
    if (cachedToggle?.isConnected && performance.now() - cacheTs < CACHE_MS) {
      return cachedToggle;
    }
    cachedToggle = getChartTypeToggle();
    cacheTs = performance.now();
    return cachedToggle;
  }

  function getCurrentTimeframeFromToolbar() {
    const badge = document.querySelector(PO.currentTfBadge);
    return badge ? normText(badge) : null;
  }

  function panelHasIntervalButtons(el) {
    for (const a of el.querySelectorAll("a, [class*='items__link']")) {
      if (!isVisible(a)) continue;
      const label = normText(a);
      if (/^(M1|M5|M15|M30|H1|S15)$/.test(label)) return true;
      if (/\b(M1|M5|M15|M30|H1|S15)\b/.test(label) && label.length <= 4) return true;
    }
    return false;
  }

  /**
   * strict=false — открыто ли меню (достаточно заголовков, PO рисует кнопки с задержкой).
   * strict=true — после закрытия: нужны видимые кнопки M1/M5…
   */
  function isChartSettingsPanelElement(el, strict) {
    if (!el || !isVisible(el)) return false;
    const text = el.innerText || "";
    if (!/Типы графика|Chart type/i.test(text)) return false;
    if (!/Интервал|Interval/i.test(text)) return false;
    const r = el.getBoundingClientRect();
    if (r.width < 160 || r.height < 100) return false;
    if (strict) return panelHasIntervalButtons(el);
    return true;
  }

  /** Панель «Типы графика» + «Интервал» */
  function findChartDropdown(strict) {
    const useStrict = strict === true;

    if (
      cachedDropdown?.isConnected &&
      isChartSettingsPanelElement(cachedDropdown, useStrict)
    ) {
      return cachedDropdown;
    }

    let best = null;
    let bestArea = 0;

    for (const modal of document.querySelectorAll(".drop-down-modal")) {
      if (!isChartSettingsPanelElement(modal, useStrict)) continue;
      const r = modal.getBoundingClientRect();
      const area = r.width * r.height;
      if (area > bestArea) {
        best = modal;
        bestArea = area;
      }
    }

    if (best) {
      cachedDropdown = best;
      cacheTs = performance.now();
      return best;
    }

    cachedDropdown = null;
    return null;
  }

  function isChartMenuOpen(strict) {
    return !!findChartDropdown(strict === true);
  }

  function isExcludedToolbarLink(el) {
    if (!el) return true;
    const link = el.closest("a");
    if (!link) return false;
    if (link.closest(".drop-down-modal, [class*='drop-down-modal']")) return false;

    const cls = link.className || "";
    if (cls.includes("items__link--chart-type")) return true;
    if (cls.includes("items__link--indicators")) return true;
    if (cls.includes("items__link--drawings")) return true;
    if (cls.includes("items__link--dots")) return true;
    if (cls.includes("chart-layout-options-btn")) return true;
    if (link.closest(PO.toolbarItems)) return true;
    return false;
  }

  /**
   * Один клик: React onClick или native .click() (без лишних synthetic events).
   */
  function quickClick(el) {
    if (!el) return false;

    const reactKey = Object.keys(el).find(
      (k) =>
        k.startsWith("__reactProps$") ||
        k.startsWith("__reactEventHandlers$")
    );

    if (reactKey && el[reactKey]) {
      const handler =
        el[reactKey]?.onClick ||
        el[reactKey]?.onMouseDown ||
        el[reactKey]?.memoizedProps?.onClick;
      if (typeof handler === "function") {
        try {
          handler({
            preventDefault() {},
            stopPropagation() {},
            nativeEvent: new MouseEvent("click", { bubbles: true }),
            target: el,
            currentTarget: el,
            type: "click",
          });
          return true;
        } catch (_) {}
      }
    }

    try {
      el.click();
      return true;
    } catch (_) {
      return false;
    }
  }

  function intervalLabel(el) {
    const direct = directText(el);
    if (direct) return direct;
    return normText(el);
  }

  function findTimeframeButton(timeframe) {
    const dropdown = findChartDropdown(false);

    if (dropdown) {
      for (const a of dropdown.querySelectorAll("a")) {
        if (intervalLabel(a) !== timeframe) continue;
        if (isExcludedToolbarLink(a)) continue;
        if (!isVisible(a)) continue;
        return a;
      }

      for (const a of dropdown.querySelectorAll(PO.intervalLink)) {
        if (normText(a) !== timeframe) continue;
        if (isExcludedToolbarLink(a)) continue;
        if (!isVisible(a)) continue;
        return a;
      }

      for (const el of dropdown.querySelectorAll("a.items__link, a")) {
        if (normText(el) !== timeframe) continue;
        if (isExcludedToolbarLink(el)) continue;
        if (!isVisible(el)) continue;
        return el;
      }
    }

    const sec = TF_SECONDS[timeframe];
    if (sec && dropdown) {
      for (const el of dropdown.querySelectorAll("[data-period], [data-timeframe], [data-value]")) {
        const dp =
          el.getAttribute("data-period") ||
          el.getAttribute("data-timeframe") ||
          el.getAttribute("data-value");
        if (String(dp) === String(sec) && isVisible(el)) {
          const link = el.closest("a") || el;
          if (!isExcludedToolbarLink(link)) return link;
        }
      }
    }

    return null;
  }

  /** Клик по свечам + короткое ожидание появления .drop-down-modal */
  function ensureChartMenuOpen() {
    invalidateDomCache();
    if (isChartMenuOpen()) return true;
    const toggle = getChartTypeToggle();
    if (!toggle) return false;
    quickClick(toggle);
    invalidateDomCache();
    return isChartMenuOpen();
  }

  async function ensureChartMenuOpenAsync() {
    if (isChartMenuOpen()) return true;
    const toggle = getChartTypeToggle();
    if (!toggle) return false;

    for (let i = 0; i < 4; i++) {
      invalidateDomCache();
      quickClick(toggle);
      const deadline = performance.now() + 450;
      while (performance.now() < deadline) {
        if (isChartMenuOpen()) return true;
        await new Promise((r) => setTimeout(r, 40));
      }
    }
    return isChartMenuOpen();
  }

  /** Клик в координаты экрана (элемент под курсором + всплытие) */
  function clickAtPoint(clientX, clientY) {
    const hit = document.elementFromPoint(clientX, clientY);
    if (!hit) return false;

    const opts = {
      bubbles: true,
      cancelable: true,
      view: window,
      clientX,
      clientY,
      button: 0,
      buttons: 1,
    };
    for (const type of ["pointerdown", "mousedown", "mouseup", "click"]) {
      hit.dispatchEvent(new MouseEvent(type, opts));
    }
    quickClick(hit);
    return true;
  }

  function isPointInsideModal(x, y, modalRect) {
    if (!modalRect) return false;
    return (
      x >= modalRect.left - 6 &&
      x <= modalRect.right + 6 &&
      y >= modalRect.top - 6 &&
      y <= modalRect.bottom + 6
    );
  }

  /** Затемнение / overlay рядом с .drop-down-modal (на PO часто именно он ловит клик «мимо») */
  function findDropdownOverlay() {
    const dropdown = findChartDropdown();
    if (!dropdown) return null;

    let node = dropdown.parentElement;
    for (let i = 0; i < 6 && node; i++) {
      for (const sel of [
        ":scope > [class*='overlay']",
        ":scope > [class*='backdrop']",
        ":scope > [class*='shade']",
        ":scope > [class*='curtain']",
      ]) {
        try {
          const el = node.querySelector(sel);
          if (el && isVisible(el)) return el;
        } catch (_) {}
      }
      if (/overlay|backdrop|shade|curtain/i.test(node.className || "") && isVisible(node)) {
        return node;
      }
      node = node.parentElement;
    }

    for (const el of document.querySelectorAll(
      "[class*='overlay'], [class*='backdrop'], [class*='shade']"
    )) {
      if (!isVisible(el)) continue;
      const r = el.getBoundingClientRect();
      if (r.width < 80 || r.height < 80) continue;
      if (el.contains(dropdown) || dropdown.contains(el)) continue;
      const dRect = dropdown.getBoundingClientRect();
      if (r.width >= window.innerWidth * 0.25 || r.height >= window.innerHeight * 0.25) {
        return el;
      }
      if (
        r.left <= dRect.left &&
        r.top <= dRect.top &&
        r.right >= dRect.right &&
        r.bottom >= dRect.bottom
      ) {
        return el;
      }
    }
    return null;
  }

  /**
   * Точки клика «в пустоту» (проверено на PO: правый край canvas ≈ 1639,267).
   * Если меню перекрывает canvas — клик под меню или ниже по графику.
   */
  function getCanvasClosePoints(modalRect) {
    const points = [];
    const canvas = document.querySelector("canvas");
    if (!canvas || !isVisible(canvas)) return points;

    const r = canvas.getBoundingClientRect();
    if (modalRect) {
      // На demo-quick-high-low меню перекрывает canvas — «проверенная» точка справа попадает В меню
      points.push(
        [r.left + r.width * 0.55, modalRect.bottom + 28],
        [r.left + 40, r.top + r.height * 0.4],
        [modalRect.right + 36, modalRect.top + 80]
      );
    }
    points.push(
      [r.right - 16, r.top + r.height * 0.35],
      [r.right - 16, r.top + r.height * 0.55],
      [r.left + r.width * 0.72, r.top + r.height * 0.45]
    );
    return points;
  }

  /** Первая точка закрытия: под меню, если оно открыто; иначе правый край canvas */
  function clickProvenClosePoint(modalRect) {
    const canvas = document.querySelector("canvas");
    if (!canvas || !isVisible(canvas)) return false;
    const r = canvas.getBoundingClientRect();

    if (modalRect) {
      const belowX = r.left + r.width * 0.55;
      const belowY = modalRect.bottom + 28;
      if (tryCloseClickAt(belowX, belowY, modalRect)) return true;
    }

    const x = r.right - 16;
    const y = r.top + r.height * 0.35;
    return tryCloseClickAt(x, y, modalRect);
  }

  function tryCloseClickAt(x, y, modalRect) {
    if (isPointInsideModal(x, y, modalRect)) return false;
    const hit = document.elementFromPoint(x, y);
    if (!hit) return false;
    if (hit.closest(".drop-down-modal, [class*='drop-down-modal']")) return false;
    if (hit.closest("a.items__link--chart-type")) return false;
    if (hit.closest(".btn-call, .btn-put, [class*='btn-call'], [class*='btn-put']")) {
      return false;
    }
    clickAtPoint(x, y);
    invalidateDomCache();
    return !isChartMenuOpen(true);
  }

  function clickOutsideDropdown() {
    const dropdown = findChartDropdown();
    const modalRect = dropdown?.getBoundingClientRect();

    for (const [x, y] of getCanvasClosePoints(modalRect)) {
      if (tryCloseClickAt(x, y, modalRect)) return true;
    }

    const canvas = document.querySelector("canvas");
    if (canvas && isVisible(canvas)) {
      quickClick(canvas);
      const r = canvas.getBoundingClientRect();
      clickAtPoint(r.left + r.width * 0.72, r.top + r.height * 0.45);
      if (!isChartMenuOpen()) return true;
    }

    return !isChartMenuOpen();
  }

  /**
   * Закрыть меню кликом по графику.
   * useToggle=false после смены TF — иначе toggle снова ОТКРЫВАЕТ меню (PO уже закрыл его).
   */
  function closeChartSettingsMenu(useToggle = false) {
    if (!isChartMenuOpen()) {
      invalidateDomCache();
      return true;
    }

    const dropdown = findChartDropdown();
    const modalRect = dropdown?.getBoundingClientRect();

    if (clickProvenClosePoint(modalRect)) {
      invalidateDomCache();
      return true;
    }

    clickOutsideDropdown();

    if (!isChartMenuOpen()) {
      invalidateDomCache();
      return true;
    }

    if (useToggle) {
      const toggle = getChartTypeToggleCached();
      if (toggle) quickClick(toggle);
    }

    invalidateDomCache();
    return !isChartMenuOpen();
  }

  function closeChartSettingsMenuSoon(useToggle = false) {
    const run = () => closeChartSettingsMenu(useToggle);
    run();
    setTimeout(run, 80);
    setTimeout(run, 220);
  }

  /**
   * Диагностика на живой странице PO (F12 → Console):
   * await __poTimeframes.testCloseMethods()
   */
  async function testCloseMethods() {
    const toggle = getChartTypeToggle();
    const report = {
      toggleFound: !!toggle,
      frame: isTopFrame ? "top" : "iframe",
      methods: [],
    };

    if (!toggle) {
      report.error = "Кнопка свечей не найдена в этом фрейме";
      return report;
    }

    if (!isChartMenuOpen()) {
      await ensureChartMenuOpenAsync();
    }
    report.menuOpenBefore = isChartMenuOpen();

    if (!report.menuOpenBefore) {
      report.error = "Меню не открылось после клика по свечам";
      return report;
    }

    const tryMethod = async (name, fn) => {
      if (!isChartMenuOpen()) return;
      await fn();
      await new Promise((r) => setTimeout(r, 250));
      report.methods.push({ name, stillOpen: isChartMenuOpen() });
    };

    const dropdown = findChartDropdown();
    const modalRect = dropdown?.getBoundingClientRect();

    await tryMethod("proven (right-16, 35% height)", () => {
      clickProvenClosePoint(modalRect);
    });

    for (const [i, pt] of getCanvasClosePoints(modalRect).entries()) {
      const [x, y] = pt;
      await tryMethod(`canvas-${i} (${Math.round(x)},${Math.round(y)})`, () => {
        invalidateDomCache();
        clickAtPoint(x, y);
      });
    }

    report.note =
      "toggle не тестируем — после выбора TF он снова открывает меню";

    report.menuOpenAfter = isChartMenuOpen();
    return report;
  }

  function showToast(message, isError) {
    if (!isTopFrame) return;
    if (!toastEl) {
      toastEl = document.createElement("div");
      toastEl.className = "po-tf-toast";
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = message;
    toastEl.classList.toggle("po-tf-toast--error", !!isError);
    toastEl.classList.add("po-tf-toast--visible");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove("po-tf-toast--visible"), 1500);
  }

  /**
   * Точка прокрутки на графике: правый край − 40px, по вертикали — середина.
   * (раньше был геометрический центр canvas)
   */
  function getChartCanvasCenter() {
    const canvases = Array.from(document.querySelectorAll("canvas")).filter(isVisible);
    if (!canvases.length) return null;

    // Берём самый большой видимый canvas — это обычно график PO
    let best = null;
    let bestArea = 0;
    for (const c of canvases) {
      const r = c.getBoundingClientRect();
      const area = r.width * r.height;
      if (area > bestArea) {
        best = c;
        bestArea = area;
      }
    }
    if (!best) return null;
    const r = best.getBoundingClientRect();
    return {
      canvas: best,
      x: r.right - ZOOM_POINT_RIGHT_INSET_PX,
      y: r.top + r.height / 2,
    };
  }

  /** Красная точка — откуда идут wheel-события (для отладки/настройки) */
  function showWheelOriginMarker(x, y) {
    if (!isTopFrame) return;
    let el = document.getElementById("po-tf-wheel-marker");
    if (!el) {
      el = document.createElement("div");
      el.id = "po-tf-wheel-marker";
      el.className = "po-tf-wheel-marker";
      document.documentElement.appendChild(el);
    }
    el.style.left = `${Math.round(x)}px`;
    el.style.top = `${Math.round(y)}px`;
    el.classList.add("po-tf-wheel-marker--visible");
    clearTimeout(showWheelOriginMarker._timer);
    showWheelOriginMarker._timer = setTimeout(() => {
      el.classList.remove("po-tf-wheel-marker--visible");
    }, WHEEL_MARKER_MS);
  }

  /**
   * Эмуляция прокрутки колеса вниз в центре графика (zoom out).
   * PO/PIXI часто слушают wheel на canvas — шлём и на canvas, и на elementFromPoint.
   */
  function zoomChartOut() {
    const center = getChartCanvasCenter();
    if (!center) return false;

    const { canvas, x, y } = center;
    showWheelOriginMarker(x, y);

    const hit = document.elementFromPoint(x, y) || canvas;
    const opts = {
      bubbles: true,
      cancelable: true,
      view: window,
      clientX: x,
      clientY: y,
      deltaX: 0,
      deltaY: ZOOM_OUT_DELTA_Y,
      deltaZ: 0,
      deltaMode: WheelEvent.DOM_DELTA_PIXEL,
    };

    for (let i = 0; i < ZOOM_OUT_WHEEL_TICKS; i++) {
      const ev = new WheelEvent("wheel", opts);
      hit.dispatchEvent(ev);
      if (hit !== canvas) canvas.dispatchEvent(new WheelEvent("wheel", opts));
    }
    return true;
  }

  function scheduleZoomOut() {
    setTimeout(() => zoomChartOut(), ZOOM_OUT_DELAY_MS);
  }

  function finishSwitch(timeframe, t0) {
    setTimeout(() => {
      invalidateDomCache();
      if (isChartMenuOpen()) {
        closeChartSettingsMenuSoon(false);
      }
      scheduleZoomOut();
      logPerf(`готово ${timeframe}`, t0);
      busy = false;
    }, 200);
  }

  function setTimeframe(timeframe, attempt) {
    const tryNum = attempt ?? 0;
    const t0 = performance.now();

    if (tryNum === 0) {
      if (busy) {
        if (performance.now() - busySince < 12000) return false;
        busy = false;
      }
      busy = true;
      busySince = performance.now();

      const current = getCurrentTimeframeFromToolbar();
      if (current === timeframe) {
        setTimeout(() => {
          invalidateDomCache();
          if (isChartMenuOpen()) closeChartSettingsMenuSoon(false);
          scheduleZoomOut();
        }, 200);
        if (isTopFrame) showToast(`Таймфрейм: ${timeframe}`);
        logPerf(`уже ${timeframe}`, t0);
        busy = false;
        return true;
      }
    }

    const menuWasOpen = isChartMenuOpen();

    if (!menuWasOpen) {
      if (!ensureChartMenuOpen()) {
        if (tryNum < OPEN_MAX_RETRIES) {
          setTimeout(() => setTimeframe(timeframe, tryNum + 1), OPEN_RETRY_MS);
          return false;
        }
        busy = false;
        if (isTopFrame) {
          showToast("Не удалось открыть меню графика (иконка свечей).", true);
        }
        return false;
      }
      logPerf("меню открыто", t0);
    }

    const btn = findTimeframeButton(timeframe);
    if (btn) {
      quickClick(btn);
      if (isTopFrame) showToast(`Таймфрейм: ${timeframe}`);
      logPerf(`клик ${timeframe}`, t0);
      finishSwitch(timeframe, t0);
      return true;
    }

    if (tryNum < MAX_RETRIES) {
      invalidateDomCache();
      setTimeout(() => setTimeframe(timeframe, tryNum + 1), RETRY_MS);
      return false;
    }

    busy = false;
    if (isTopFrame) {
      showToast(
        `Не найден ${timeframe}. Откройте меню «Типы графика» вручную.`,
        true
      );
    }
    return false;
  }

  function isTypingContext(target) {
    if (!target) return false;
    const tag = target.tagName;
    if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return true;
    return !!target.isContentEditable;
  }

  function installHotkeys() {
    if (installHotkeys.done) return;
    installHotkeys.done = true;

    const onKeyDown = (event) => {
      if (isTypingContext(event.target)) return;
      const timeframe = PoTfConfig.resolveTimeframeFromEvent(event, tfBindings);
      if (!timeframe) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      setTimeframe(timeframe);
    };

    window.addEventListener("keydown", onKeyDown, true);
    document.addEventListener("keydown", onKeyDown, true);

    console.info(
      `[PO Timeframes] настраиваемые клавиши (${isTopFrame ? "top" : "iframe"})`
    );
  }

  /**
   * Диагностика DOM для оптимизации (F12: await __poTimeframes.analyzePage()).
   * Считает iframe, селекторы, где лежит график.
   */
  function analyzePage() {
    const iframes = document.querySelectorAll("iframe");
    const modals = document.querySelectorAll(".drop-down-modal");
    const toggle = getChartTypeToggle();
    const canvas = document.querySelector("canvas");
    return {
      frame: isTopFrame ? "top" : "iframe",
      url: location.pathname,
      activated,
      iframeCount: iframes.length,
      iframeSrcs: Array.from(iframes)
        .slice(0, 8)
        .map((f) => (f.src || f.id || "no-src").slice(0, 120)),
      toggleFound: !!toggle,
      toggleClass: toggle?.className?.slice(0, 80) || null,
      currentTf: getCurrentTimeframeFromToolbar(),
      menuOpen: isChartMenuOpen(),
      dropdownCount: modals.length,
      canvasFound: !!canvas,
      canvasRect: canvas?.getBoundingClientRect
        ? ((r) => ({
            w: Math.round(r.width),
            h: Math.round(r.height),
            right: Math.round(r.right),
            top: Math.round(r.top),
          }))(canvas.getBoundingClientRect())
        : null,
      marker: document.documentElement.getAttribute("data-po-timeframes"),
    };
  }

  window.__poTimeframes = {
    setTimeframe,
    findTimeframeButton,
    ensureChartMenuOpen,
    ensureChartMenuOpenAsync,
    closeChartSettingsMenu,
    closeChartSettingsMenuSoon,
    clickOutsideDropdown,
    getCanvasClosePoints,
    testCloseMethods,
    analyzePage,
    zoomChartOut,
    getChartTypeToggle: getChartTypeToggleCached,
    getCurrentTimeframeFromToolbar,
    findChartDropdown,
    get perf() {
      return perfLog;
    },
    set perf(v) {
      perfLog = !!v;
    },
  };

  /** Запуск после объявления всех функций (иначе installHotkeys/setTimeframe недоступны) */
  function bootstrap() {
    document.documentElement.setAttribute(
      "data-po-timeframes",
      isTopFrame ? "1" : "iframe"
    );
    installHotkeys();
    if (hasChartToggleLite() || frameHasChartUi()) {
      activateFrame();
    } else {
      startBootPoll(0);
    }
    console.info(
      `[PO Timeframes] готов (${isTopFrame ? "top" : "iframe"}) ${location.pathname}`
    );
  }

  bootstrap();
})();
