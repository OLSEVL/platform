/**
 * Popup: настройка таймфрейма и горячих клавиш для каждой кнопки.
 */
(function () {
  "use strict";

  const rowsEl = document.getElementById("rows");
  const statusEl = document.getElementById("status");
  const saveBtn = document.getElementById("save");
  const resetBtn = document.getElementById("reset");

  /** Текущие настройки в памяти popup */
  let bindings = PoTfConfig.cloneBindings(PoTfConfig.DEFAULT_BINDINGS);
  /** Индекс строки, для которой записываем новое сочетание */
  let recordingIndex = null;
  let statusTimer = null;

  function showStatus(text, isError) {
    statusEl.textContent = text;
    statusEl.style.color = isError ? "#f28b82" : "#81c995";
    clearTimeout(statusTimer);
    statusTimer = setTimeout(() => {
      statusEl.textContent = "";
    }, 2200);
  }

  /** Сохранить в chrome.storage.sync */
  function persistBindings(message) {
    chrome.storage.sync.set(
      {
        tfBindings: PoTfConfig.cloneBindings(bindings),
        shortcutsVersion: PoTfConfig.SHORTCUTS_VERSION,
      },
      () => {
        showStatus(message || "Сохранено");
      }
    );
  }

  /** Остановить запись сочетания */
  function stopRecording() {
    if (recordingIndex == null) return;
    const btn = rowsEl.querySelector(`[data-key-btn="${recordingIndex}"]`);
    if (btn) {
      btn.classList.remove("recording");
      btn.textContent = PoTfConfig.formatBinding(bindings[recordingIndex].binding);
    }
    recordingIndex = null;
    document.removeEventListener("keydown", onRecordKeyDown, true);
  }

  /** Обработчик записи нового сочетания */
  function onRecordKeyDown(event) {
    event.preventDefault();
    event.stopPropagation();

    if (event.key === "Escape") {
      stopRecording();
      showStatus("Запись отменена");
      return;
    }

    const binding = PoTfConfig.bindingFromKeyboardEvent(event);
    if (!binding) return;

    // Нужна хотя бы одна «осмысленная» клавиша
    if (!binding.key && !binding.code) return;

    bindings[recordingIndex].binding = binding;
    stopRecording();
    renderRows();
    persistBindings("Клавиша сохранена");
  }

  /** Начать запись сочетания для строки */
  function startRecording(index) {
    stopRecording();
    recordingIndex = index;
    const btn = rowsEl.querySelector(`[data-key-btn="${index}"]`);
    if (btn) {
      btn.classList.add("recording");
      btn.textContent = "Нажмите сочетание…";
    }
    document.addEventListener("keydown", onRecordKeyDown, true);
  }

  /** Отрисовать все строки настроек */
  function renderRows() {
    rowsEl.innerHTML = "";

    bindings.forEach((row, index) => {
      const wrap = document.createElement("div");
      wrap.className = "row";

      const label = document.createElement("div");
      label.className = "row-label";
      label.textContent = row.label;

      const select = document.createElement("select");
      PoTfConfig.AVAILABLE_TIMEFRAMES.forEach((tf) => {
        const opt = document.createElement("option");
        opt.value = tf;
        opt.textContent = tf;
        if (tf === row.timeframe) opt.selected = true;
        select.appendChild(opt);
      });
      select.addEventListener("change", () => {
        bindings[index].timeframe = select.value;
        persistBindings("Таймфрейм сохранён");
      });

      const keyBtn = document.createElement("button");
      keyBtn.type = "button";
      keyBtn.className = "key-btn";
      keyBtn.dataset.keyBtn = String(index);
      keyBtn.textContent = PoTfConfig.formatBinding(row.binding);
      keyBtn.title = "Нажмите, чтобы назначить сочетание";
      keyBtn.addEventListener("click", () => startRecording(index));

      wrap.appendChild(label);
      wrap.appendChild(select);
      wrap.appendChild(keyBtn);
      rowsEl.appendChild(wrap);
    });
  }

  // Загрузка сохранённых настроек
  chrome.storage.sync.get(
    { tfBindings: null, shortcuts: null, shortcutsVersion: 0 },
    (data) => {
      bindings = PoTfConfig.normalizeStorageData(data);
      renderRows();
    }
  );

  saveBtn.addEventListener("click", () => {
    persistBindings("Настройки сохранены");
  });

  resetBtn.addEventListener("click", () => {
    bindings = PoTfConfig.cloneBindings(PoTfConfig.DEFAULT_BINDINGS);
    stopRecording();
    renderRows();
    persistBindings("Сброшено на умолчание");
  });

  window.addEventListener("unload", stopRecording);
})();
