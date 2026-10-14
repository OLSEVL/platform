/**
 * Адреса сервера сигналов (не показываем в UI плагина).
 * lan — Wi‑Fi/LAN дома; wan — через домен снаружи (роутер: TCP 8080 → ПК с nginx).
 */
const EXT_ENDPOINTS = {
  lan: '192.168.1.139:8080',
  wan: 'bot123456789.space:8080',
};

/**
 * @param {'lan'|'wan'|string|undefined} networkMode
 * @returns {'lan'|'wan'}
 */
function normalizeNetworkMode(networkMode) {
  // По умолчанию — внешняя сеть; явное lan только если выбрано.
  return networkMode === 'lan' ? 'lan' : 'wan';
}

/**
 * @param {'lan'|'wan'|string|undefined} networkMode
 * @returns {string} host:port
 */
function resolveServerHost(networkMode) {
  return normalizeNetworkMode(networkMode) === 'wan' ? EXT_ENDPOINTS.wan : EXT_ENDPOINTS.lan;
}

/**
 * Миграция со старого свободного serverHost → lan/wan.
 * @param {string|undefined} serverHost
 * @param {string|undefined} networkMode
 * @returns {'lan'|'wan'}
 */
function inferNetworkMode(serverHost, networkMode) {
  if (networkMode === 'wan' || networkMode === 'lan') {
    return networkMode;
  }
  const h = String(serverHost || '');
  // Старый storage только с LAN IP → внутренняя; иначе по умолчанию внешняя.
  if (/^192\.168\.|^10\.|^127\.0\.0\.1/i.test(h) && !/bot123456789\.space|bot13123\.online/i.test(h)) {
    return 'lan';
  }
  return 'wan';
}
