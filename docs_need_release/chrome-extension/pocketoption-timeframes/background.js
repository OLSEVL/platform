/**
 * Service worker: chrome.commands Alt+1…4 → content script.
 * Таймфрейм берётся из настроек кнопок 1–4 (popup).
 */
importScripts("shortcuts-config.js");

function loadBindings() {
  return new Promise((resolve) => {
    chrome.storage.sync.get(
      { tfBindings: null, shortcuts: null, shortcutsVersion: 0 },
      (data) => {
        resolve(PoTfConfig.normalizeStorageData(data));
      }
    );
  });
}

async function sendTimeframe(tabId, timeframe) {
  const msg = { type: "SET_TIMEFRAME", timeframe };
  try {
    await chrome.tabs.sendMessage(tabId, msg);
    return;
  } catch (_) {}

  setTimeout(async () => {
    try {
      await chrome.tabs.sendMessage(tabId, msg);
    } catch (e) {
      console.warn("[PO Timeframes] sendMessage:", e?.message || e);
    }
  }, 400);
}

chrome.commands.onCommand.addListener(async (command) => {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !tab.url?.includes("pocketoption.com")) return;

  const bindings = await loadBindings();
  const timeframe = PoTfConfig.getTimeframeForCommand(bindings, command);
  if (!timeframe) return;

  await sendTimeframe(tab.id, timeframe);
});
