/**
 * Service worker: chrome.commands Alt+Q / Alt+W → content script.
 * Дублирует keydown только если страница «съела» клавишу.
 * content.js сам отсекает повтор, если keydown уже сработал.
 */
const CMD_TO_DIR = {
  "pair-prev": "prev",
  "pair-next": "next",
};

chrome.commands.onCommand.addListener(async (command) => {
  const direction = CMD_TO_DIR[command];
  if (!direction) return;

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !tab.url?.includes("pocketoption.com")) return;

  // Одно сообщение — без повторной отправки через 400мс (она удваивала запросы)
  try {
    await chrome.tabs.sendMessage(tab.id, {
      type: "SWITCH_PAIR",
      direction,
    });
  } catch (e) {
    console.warn("[PO Pairs] sendMessage:", e?.message || e);
  }
});
