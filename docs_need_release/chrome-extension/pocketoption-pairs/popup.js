/**
 * Popup настроек: категории (Валюты / Крипто / Акции) + фильтры OTC.
 * Сохраняет в chrome.storage.sync → content.js подхватывает через onChanged.
 */
(function () {
  "use strict";

  /** Значения по умолчанию */
  const DEFAULT_FILTERS = {
    skipOtc: false,
    skipNonOtc: false,
    categories: {
      currency: true,
      cryptocurrency: true,
      stock: true,
    },
  };

  const skipOtcEl = document.getElementById("skipOtc");
  const skipNonOtcEl = document.getElementById("skipNonOtc");
  const catCurrencyEl = document.getElementById("catCurrency");
  const catCryptoEl = document.getElementById("catCrypto");
  const catStockEl = document.getElementById("catStock");
  const warnBothEl = document.getElementById("warnBoth");
  const warnCategoriesEl = document.getElementById("warnCategories");
  const statusEl = document.getElementById("status");

  let statusTimer = null;

  /** Слить с дефолтами (на случай старых сохранений без categories) */
  function mergeFilters(raw) {
    return {
      ...DEFAULT_FILTERS,
      ...(raw || {}),
      categories: {
        ...DEFAULT_FILTERS.categories,
        ...((raw && raw.categories) || {}),
      },
    };
  }

  /** Предупреждения: оба OTC / ни одной категории */
  function updateWarn() {
    warnBothEl.classList.toggle(
      "visible",
      skipOtcEl.checked && skipNonOtcEl.checked
    );
    const noCats =
      !catCurrencyEl.checked && !catCryptoEl.checked && !catStockEl.checked;
    warnCategoriesEl.classList.toggle("visible", noCats);
  }

  function showStatus(text) {
    statusEl.textContent = text;
    clearTimeout(statusTimer);
    statusTimer = setTimeout(() => {
      statusEl.textContent = "";
    }, 1800);
  }

  /** Собрать объект фильтров из чекбоксов и сохранить */
  function saveFilters() {
    const filters = {
      skipOtc: !!skipOtcEl.checked,
      skipNonOtc: !!skipNonOtcEl.checked,
      categories: {
        currency: !!catCurrencyEl.checked,
        cryptocurrency: !!catCryptoEl.checked,
        stock: !!catStockEl.checked,
      },
    };
    chrome.storage.sync.set({ pairFilters: filters }, () => {
      updateWarn();
      showStatus("Сохранено");
    });
  }

  // Загрузка сохранённых настроек при открытии popup
  chrome.storage.sync.get({ pairFilters: DEFAULT_FILTERS }, (data) => {
    const f = mergeFilters(data.pairFilters);
    skipOtcEl.checked = !!f.skipOtc;
    skipNonOtcEl.checked = !!f.skipNonOtc;
    catCurrencyEl.checked = !!f.categories.currency;
    catCryptoEl.checked = !!f.categories.cryptocurrency;
    catStockEl.checked = !!f.categories.stock;
    updateWarn();
  });

  [
    skipOtcEl,
    skipNonOtcEl,
    catCurrencyEl,
    catCryptoEl,
    catStockEl,
  ].forEach((el) => el.addEventListener("change", saveFilters));
})();
