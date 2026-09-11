/**
 * Мост page (MAIN world) ↔ content script (isolated).
 * Без этого window.__poTimeframes в консоли F12 = ReferenceError.
 */
(function () {
  "use strict";

  function sendCmd(cmd, extra) {
    return new Promise((resolve) => {
      const id = Math.random().toString(36).slice(2);
      let settled = false;

      const onResponse = (event) => {
        if (event.detail?.id !== id) return;
        if (settled) return;
        settled = true;
        document.removeEventListener("po-tf-response", onResponse);
        window.removeEventListener("message", onMessage);
        resolve(event.detail.result);
      };

      const onMessage = (event) => {
        const data = event.data;
        if (!data || data.type !== "po-tf-bridge-response" || data.id !== id) return;
        if (settled) return;
        settled = true;
        document.removeEventListener("po-tf-response", onResponse);
        window.removeEventListener("message", onMessage);
        resolve(data.result);
      };

      document.addEventListener("po-tf-response", onResponse);
      window.addEventListener("message", onMessage);

      const detail = { id, cmd, ...extra };
      const ev = new CustomEvent("po-tf-cmd", { detail });

      document.dispatchEvent(ev);

      for (const iframe of document.querySelectorAll("iframe")) {
        try {
          iframe.contentWindow?.document?.dispatchEvent(
            new CustomEvent("po-tf-cmd", { detail })
          );
        } catch (_) {}
      }

      setTimeout(() => {
        if (settled) return;
        settled = true;
        document.removeEventListener("po-tf-response", onResponse);
        window.removeEventListener("message", onMessage);
        resolve({
          ok: false,
          error: "Нет ответа: график в iframe? Обновите страницу после v1.5.0",
        });
      }, 10000);
    });
  }

  window.__poTimeframes = {
    setTimeframe: (timeframe) => sendCmd("setTimeframe", { timeframe }),
    testCloseMethods: () => sendCmd("testCloseMethods"),
    openMenu: () => sendCmd("openMenu"),
    closeMenu: () => sendCmd("closeMenu"),
    analyzePage: () => sendCmd("analyzePage"),
    ping: () => sendCmd("ping"),
    zoomOut: () => sendCmd("zoomOut"),
  };

  console.info(
    "[PO Timeframes] Консоль: __poTimeframes.setTimeframe('M5'), analyzePage(), ping()"
  );
})();
