/**
 * Общие настройки горячих клавиш таймфреймов (popup + content + background).
 * Каждая строка: выбранный таймфрейм + сочетание клавиш.
 */
var PoTfConfig = (function () {
  "use strict";

  const SHORTCUTS_VERSION = 5;

  /** Доступные таймфреймы Pocket Option */
  const AVAILABLE_TIMEFRAMES = ["S15", "M1", "M5", "M15", "M30", "H1"];

  /** Пустое сочетание клавиш */
  function emptyBinding() {
    return { alt: false, shift: false, ctrl: false, meta: false, key: "", code: "" };
  }

  /** Настройки по умолчанию: 5 кнопок + S15 (2 клавиши) */
  const DEFAULT_BINDINGS = [
    {
      label: "Кнопка 1",
      timeframe: "M1",
      binding: { alt: true, shift: false, ctrl: false, meta: false, key: "1", code: "Digit1" },
    },
    {
      label: "Кнопка 2",
      timeframe: "M5",
      binding: { alt: true, shift: false, ctrl: false, meta: false, key: "2", code: "Digit2" },
    },
    {
      label: "Кнопка 3",
      timeframe: "M15",
      binding: { alt: true, shift: false, ctrl: false, meta: false, key: "3", code: "Digit3" },
    },
    {
      label: "Кнопка 4",
      timeframe: "M30",
      binding: { alt: true, shift: false, ctrl: false, meta: false, key: "4", code: "Digit4" },
    },
    {
      label: "Кнопка 5",
      timeframe: "H1",
      binding: { alt: true, shift: false, ctrl: false, meta: false, key: "5", code: "Digit5" },
    },
    {
      label: "S15",
      timeframe: "S15",
      binding: {
        alt: true,
        shift: false,
        ctrl: false,
        meta: false,
        key: "`",
        code: "Backquote",
      },
    },
    {
      label: "S15 (доп.)",
      timeframe: "S15",
      binding: { alt: true, shift: false, ctrl: false, meta: false, key: "6", code: "Digit6" },
    },
  ];

  /** chrome.commands → индекс слота (Alt+1…4 в браузере) */
  const COMMAND_SLOT_INDEX = {
    "tf-m1": 0,
    "tf-m5": 1,
    "tf-m15": 2,
    "tf-m30": 3,
  };

  /** Клон массива настроек */
  function cloneBindings(list) {
    return list.map((row) => ({
      label: row.label,
      timeframe: row.timeframe,
      binding: { ...row.binding },
    }));
  }

  /** Текст сочетания для UI («Alt + 1») */
  function formatBinding(binding) {
    if (!binding?.key && !binding?.code) return "—";
    const parts = [];
    if (binding.ctrl) parts.push("Ctrl");
    if (binding.alt) parts.push("Alt");
    if (binding.shift) parts.push("Shift");
    if (binding.meta) parts.push("Win");

    let keyLabel = binding.key || binding.code || "?";
    if (keyLabel === "`") keyLabel = "`";
    else if (/^Digit\d$/.test(keyLabel)) keyLabel = keyLabel.replace("Digit", "");
    else if (/^Numpad\d$/.test(keyLabel)) keyLabel = "Num" + keyLabel.replace("Numpad", "");
    else if (keyLabel === "Backquote") keyLabel = "`";
    else if (keyLabel.length === 1) keyLabel = keyLabel.toUpperCase();
    else keyLabel = keyLabel.replace(/^Key/, "");

    parts.push(keyLabel);
    return parts.join(" + ");
  }

  /** Событие клавиатуры → объект binding */
  function bindingFromKeyboardEvent(event) {
    if (event.key === "Escape") return null;

    // Игнорируем «голые» модификаторы
    if (["Control", "Alt", "Shift", "Meta"].includes(event.key)) return null;

    const binding = emptyBinding();
    binding.alt = !!event.altKey;
    binding.shift = !!event.shiftKey;
    binding.ctrl = !!event.ctrlKey;
    binding.meta = !!event.metaKey;
    binding.code = event.code || "";

    if (event.key === "`" || event.key === "~" || event.code === "Backquote") {
      binding.key = "`";
      binding.code = event.code || "Backquote";
    } else if (/^Digit\d$/.test(event.code)) {
      binding.key = event.code.replace("Digit", "");
    } else if (/^Numpad\d$/.test(event.code)) {
      binding.key = event.code.replace("Numpad", "");
    } else if (/^F\d+$/.test(event.code)) {
      binding.key = event.code;
    } else if (event.key && event.key.length === 1) {
      binding.key = event.key.toLowerCase();
    } else {
      binding.key = event.key || event.code;
    }

    return binding;
  }

  /** Совпадение нажатия с сохранённым binding (логика как в content.js) */
  function matchBinding(event, binding) {
    if (!binding?.key && !binding?.code) return false;

    let keyMatch = false;
    if (binding.code && event.code === binding.code) keyMatch = true;

    if (!keyMatch && binding.key === "`") {
      keyMatch =
        event.code === "Backquote" ||
        event.code === "IntlBackslash" ||
        event.key === "`" ||
        event.key === "~" ||
        event.key === "ё" ||
        event.key === "Ё";
    }

    if (!keyMatch && binding.key) {
      keyMatch =
        event.code === `Digit${binding.key}` ||
        event.code === `Numpad${binding.key}` ||
        (event.key || "").toLowerCase() === String(binding.key).toLowerCase();
    }
    if (!keyMatch) return false;

    return (
      !!event.altKey === !!binding.alt &&
      !!event.shiftKey === !!binding.shift &&
      !!event.ctrlKey === !!binding.ctrl &&
      !!event.metaKey === !!binding.meta
    );
  }

  /** Миграция со старого формата shortcuts (M1, M5, …, S15_ALT) */
  function migrateLegacyShortcuts(oldShortcuts) {
    const s = oldShortcuts || {};
    const pick = (name, fallbackIdx) => {
      const b = s[name];
      if (b && (b.key || b.code)) return { ...b };
      return { ...DEFAULT_BINDINGS[fallbackIdx].binding };
    };

    return [
      { label: "Кнопка 1", timeframe: "M1", binding: pick("M1", 0) },
      { label: "Кнопка 2", timeframe: "M5", binding: pick("M5", 1) },
      { label: "Кнопка 3", timeframe: "M15", binding: pick("M15", 2) },
      { label: "Кнопка 4", timeframe: "M30", binding: pick("M30", 3) },
      { label: "Кнопка 5", timeframe: "H1", binding: pick("H1", 4) },
      { label: "S15", timeframe: "S15", binding: pick("S15", 5) },
      {
        label: "S15 (доп.)",
        timeframe: "S15",
        binding: pick("S15_ALT", 6),
      },
    ];
  }

  /** Нормализация данных из chrome.storage */
  function normalizeStorageData(data) {
    const ver = data?.shortcutsVersion || 0;
    if (ver >= SHORTCUTS_VERSION && Array.isArray(data?.tfBindings)) {
      return cloneBindings(data.tfBindings);
    }
    if (data?.shortcuts) {
      return migrateLegacyShortcuts(data.shortcuts);
    }
    return cloneBindings(DEFAULT_BINDINGS);
  }

  /** Таймфрейм для chrome.commands (Alt+1…4) по индексу слота */
  function getTimeframeForCommand(bindings, command) {
    const idx = COMMAND_SLOT_INDEX[command];
    if (idx == null) return null;
    const row = bindings?.[idx];
    if (!row?.timeframe) return null;
    return row.timeframe;
  }

  /** Найти таймфрейм по нажатию клавиши */
  function resolveTimeframeFromEvent(event, bindings) {
    for (const row of bindings || []) {
      if (matchBinding(event, row.binding)) return row.timeframe;
    }
    return null;
  }

  return {
    SHORTCUTS_VERSION,
    AVAILABLE_TIMEFRAMES,
    DEFAULT_BINDINGS,
    COMMAND_SLOT_INDEX,
    cloneBindings,
    formatBinding,
    bindingFromKeyboardEvent,
    matchBinding,
    migrateLegacyShortcuts,
    normalizeStorageData,
    getTimeframeForCommand,
    resolveTimeframeFromEvent,
  };
})();
