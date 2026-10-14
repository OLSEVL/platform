/**
 * Плавающее окно владеет WebSocket (без HTTP-poll — не грузим бэкенд).
 * История в chrome.storage.local — не чистится по актуальности.
 * Новый сигнал → звук (стандартный beep или свой файл из local).
 */
const statusEl = document.getElementById('status');
const statusText = document.getElementById('status-text');
const rowsEl = document.getElementById('rows');
const settingsEl = document.getElementById('settings');
const soundEnabledEl = document.getElementById('soundEnabled');
const soundNameEl = document.getElementById('sound-name');
const soundFileEl = document.getElementById('soundFile');
const botFilterEl = document.getElementById('bot-filter');
const botsPanelEl = document.getElementById('bots-panel');
const btnBotsEl = document.getElementById('btn-bots');

const WS_PATH = '/extension-live';
const MAX_SIGNALS = 2000;
const PING_MS = 15000;
const RECONNECT_MS = 2000;
/** Нет pong дольше этого — принудительный reconnect. */
const PONG_STALE_MS = 45000;
/** Свой звук: лимит data URL (~350KB файла). */
const MAX_SOUND_BYTES = 350 * 1024;

/** @type {Array<object>} */
let signals = [];
/** @type {WebSocket|null} */
let socket = null;
/** @type {ReturnType<typeof setInterval>|null} */
let pingTimer = null;
/** @type {ReturnType<typeof setTimeout>|null} */
let reconnectTimer = null;
/** @type {ReturnType<typeof setTimeout>|null} */
let helloTimer = null;
let connGen = 0;
/** @type {'lan'|'wan'} */
let networkMode = 'wan';
/** host:port — из endpoints.js, в UI не показываем. */
let serverHost = resolveServerHost('wan');
/** После подключения WS — можно пищать на новые. */
let liveReady = false;
let soundEnabled = true;
/** data: URL своего звука; пусто = стандартный beep через WebAudio. */
let soundDataUrl = '';
let soundName = '';
let lastPongAt = 0;
/** Сериализация persist при пачке WS-сообщений. */
let persistChain = Promise.resolve();
/** @type {AudioContext|null} */
let audioCtx = null;
/**
 * Выбранные bot_id для фильтра. Пустой массив = показывать всех ботов.
 * @type {number[]}
 */
let filterBotIds = [];
/** Сигнатура списка ботов в UI — чтобы не пересобирать чекбоксы зря. */
let botFilterSignature = '';

init();

async function init() {
  const cfg = await chrome.storage.sync.get({
    serverHost: EXT_ENDPOINTS.lan,
    networkMode: 'wan',
    uiMode: 'sidepanel',
    soundEnabled: true,
    filterBotIds: [],
  });
  networkMode = inferNetworkMode(cfg.serverHost, cfg.networkMode);
  serverHost = resolveServerHost(networkMode);
  soundEnabled = cfg.soundEnabled !== false;
  filterBotIds = normalizeFilterIds(cfg.filterBotIds);
  soundEnabledEl.checked = soundEnabled;

  const netRadio = document.querySelector(`input[name="networkMode"][value="${networkMode}"]`);
  if (netRadio) {
    netRadio.checked = true;
  }
  // По умолчанию боковая панель; явное float только если уже сохранено.
  const mode = cfg.uiMode === 'float' ? 'float' : 'sidepanel';
  const modeRadio = document.querySelector(`input[name="uiMode"][value="${mode}"]`);
  if (modeRadio) {
    modeRadio.checked = true;
  }
  // На случай старого storage — нормализуем networkMode/serverHost без показа адреса.
  await chrome.storage.sync.set({ networkMode, serverHost });

  // Свой звук лежит в local (sync слишком маленький для audio data URL).
  const localCfg = await chrome.storage.local.get({ signals: [], soundDataUrl: '', soundName: '' });
  signals = Array.isArray(localCfg.signals) ? localCfg.signals : [];
  soundDataUrl = typeof localCfg.soundDataUrl === 'string' ? localCfg.soundDataUrl : '';
  soundName = typeof localCfg.soundName === 'string' ? localCfg.soundName : '';
  updateSoundLabel();
  renderBotFilter();
  render();

  liveReady = true;
  connectWs();

  // Окно снова видимо — только будим WS (без HTTP к API).
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      onWake();
    }
  });
  window.addEventListener('focus', () => {
    onWake();
  });

  // Options / другое окно сменило сеть, звук или фильтр — подхватываем без перезагрузки.
  chrome.storage.onChanged.addListener((changes, area) => {
    if (area === 'sync' && changes.soundEnabled) {
      soundEnabled = changes.soundEnabled.newValue !== false;
      soundEnabledEl.checked = soundEnabled;
    }
    if (area === 'sync' && changes.filterBotIds) {
      filterBotIds = normalizeFilterIds(changes.filterBotIds.newValue);
      renderBotFilter();
      render();
    }
    // Смена внутренней/внешней сети → сразу новый WebSocket.
    if (area === 'sync' && (changes.networkMode || changes.serverHost)) {
      const nextMode = inferNetworkMode(
        changes.serverHost ? changes.serverHost.newValue : serverHost,
        changes.networkMode ? changes.networkMode.newValue : networkMode,
      );
      const nextHost = resolveServerHost(nextMode);
      if (nextMode !== networkMode || nextHost !== serverHost) {
        networkMode = nextMode;
        serverHost = nextHost;
        const netRadio = document.querySelector(`input[name="networkMode"][value="${networkMode}"]`);
        if (netRadio) {
          netRadio.checked = true;
        }
        console.info('[signals] network →', networkMode);
        setStatus('connecting');
        connectWs(true);
      }
    }
    if (area === 'local') {
      if (changes.soundDataUrl) {
        soundDataUrl = typeof changes.soundDataUrl.newValue === 'string' ? changes.soundDataUrl.newValue : '';
      }
      if (changes.soundName) {
        soundName = typeof changes.soundName.newValue === 'string' ? changes.soundName.newValue : '';
      }
      if (changes.soundDataUrl || changes.soundName) {
        updateSoundLabel();
      }
    }
  });

  document.getElementById('btn-filter-all').addEventListener('click', () => {
    // Пустой фильтр = все боты в списке и в звуке.
    void setFilterBotIds([]);
  });

  // Кнопка «Боты» в топбаре — открывает/закрывает панель выбора.
  btnBotsEl.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const open = botsPanelEl.classList.toggle('hidden') === false;
    btnBotsEl.classList.toggle('open', open);
    // Настройки закрываем, чтобы не наслаивались панели.
    if (open) {
      settingsEl.classList.add('hidden');
      renderBotFilter();
    }
  });
}

/** После простоя вкладки/окна — только проверка/reconnect WS. */
function onWake() {
  if (!socket || socket.readyState !== WebSocket.OPEN) {
    connectWs(true);
  } else if (lastPongAt && Date.now() - lastPongAt > PONG_STALE_MS) {
    connectWs(true);
  }
}

document.getElementById('btn-settings').addEventListener('click', () => {
  settingsEl.classList.toggle('hidden');
  // При открытии настроек прячем панель ботов.
  botsPanelEl.classList.add('hidden');
  btnBotsEl.classList.remove('open');
});

document.getElementById('btn-save').addEventListener('click', async () => {
  const netEl = document.querySelector('input[name="networkMode"]:checked');
  networkMode = normalizeNetworkMode(netEl && netEl.value);
  serverHost = resolveServerHost(networkMode);
  const modeEl = document.querySelector('input[name="uiMode"]:checked');
  const uiMode = modeEl && modeEl.value === 'float' ? 'float' : 'sidepanel';
  soundEnabled = !!soundEnabledEl.checked;
  await chrome.storage.sync.set({ networkMode, serverHost, uiMode, soundEnabled });
  await chrome.runtime.sendMessage({ type: 'ext:apply-ui-mode', mode: uiMode }).catch(() => {});
  settingsEl.classList.add('hidden');
  setStatus('connecting');
  connectWs(true);
});

soundEnabledEl.addEventListener('change', async () => {
  soundEnabled = !!soundEnabledEl.checked;
  await chrome.storage.sync.set({ soundEnabled });
});

document.getElementById('btn-sound-test').addEventListener('click', () => {
  void playSignalSound(true);
});

document.getElementById('btn-sound-reset').addEventListener('click', async () => {
  soundDataUrl = '';
  soundName = '';
  soundFileEl.value = '';
  await chrome.storage.local.set({ soundDataUrl: '', soundName: '' });
  updateSoundLabel();
});

soundFileEl.addEventListener('change', async () => {
  const file = soundFileEl.files && soundFileEl.files[0];
  if (!file) {
    return;
  }
  if (file.size > MAX_SOUND_BYTES) {
    statusText.textContent = `Файл слишком большой (макс. ${Math.round(MAX_SOUND_BYTES / 1024)} КБ)`;
    soundFileEl.value = '';
    return;
  }
  try {
    const dataUrl = await readFileAsDataUrl(file);
    soundDataUrl = dataUrl;
    soundName = file.name || 'custom';
    await chrome.storage.local.set({ soundDataUrl, soundName });
    updateSoundLabel();
    statusText.textContent = `Звук: ${soundName}`;
    void playSignalSound(true);
  } catch (e) {
    statusText.textContent = 'Не удалось прочитать файл звука';
    console.warn('[signals] sound file', e);
  }
});

document.getElementById('btn-clear').addEventListener('click', async () => {
  if (!confirm('Очистить весь список сигналов?')) {
    return;
  }
  signals = [];
  await chrome.storage.local.set({ signals: [] });
  render();
});

/**
 * @param {string} hostRaw
 */
function buildWsUrl(hostRaw) {
  let host = String(hostRaw || '').trim();
  if (!host) {
    return '';
  }
  if (/^wss?:\/\//i.test(host)) {
    const u = host.replace(/\/$/, '');
    return /\/extension-live$/i.test(u) ? u : `${u}${WS_PATH}`;
  }
  host = host.replace(/^https?:\/\//i, '').replace(/\/$/, '');
  host = host.replace(/\/extension-live.*$/i, '');
  return `ws://${host}${WS_PATH}`;
}

/**
 * Базовый HTTP origin сервера (только для редкого resolve display_name по клику).
 * @param {string} hostRaw
 */
function buildHttpOrigin(hostRaw) {
  let host = String(hostRaw || '').trim();
  if (!host) {
    return '';
  }
  if (/^wss?:\/\//i.test(host)) {
    host = host.replace(/^ws/i, 'http');
  }
  if (!/^https?:\/\//i.test(host)) {
    host = `http://${host}`;
  }
  return host.replace(/\/$/, '').replace(/\/extension-live.*$/i, '');
}

/**
 * @param {boolean} [force]
 */
function connectWs(force = false) {
  const url = buildWsUrl(serverHost);
  if (!url) {
    setStatus('disconnected', 'Нет адреса сервера');
    return;
  }
  clearTimeout(reconnectTimer);
  reconnectTimer = null;
  clearTimeout(helloTimer);
  helloTimer = null;
  clearInterval(pingTimer);
  pingTimer = null;

  const gen = ++connGen;
  if (socket) {
    const old = socket;
    socket = null;
    try {
      old.close();
    } catch (_) {
      /* ignore */
    }
  }

  setStatus('connecting');
  console.info('[signals] WS connect', networkMode, url);
  let ws;
  try {
    ws = new WebSocket(url);
  } catch (e) {
    setStatus('error', String(e));
    scheduleReconnect(gen);
    return;
  }
  socket = ws;
  lastPongAt = Date.now();

  // Если hello не пришёл — часто глюк NAT loopback (внешняя сеть из домашнего Wi‑Fi).
  helloTimer = setTimeout(() => {
    if (gen !== connGen) {
      return;
    }
    if (!statusEl.classList.contains('status-on')) {
      setStatus(
        'error',
        networkMode === 'wan'
          ? 'внешняя сеть: нет ответа WS (проверьте проброс 8080 или используйте «Внутренняя» дома)'
          : 'нет ответа WS',
      );
    }
  }, 8000);

  ws.addEventListener('open', () => {
    if (gen !== connGen) {
      return;
    }
    // Статус «онлайн» ставим после hello — иначе ложное «подключено» при битом апстриме.
    lastPongAt = Date.now();
    // Ping + watchdog: half-open сокеты Chrome иногда не шлют close.
    pingTimer = setInterval(() => {
      if (gen !== connGen || !socket || socket.readyState !== WebSocket.OPEN) {
        return;
      }
      if (Date.now() - lastPongAt > PONG_STALE_MS) {
        console.warn('[signals] pong stale → reconnect');
        connectWs(true);
        return;
      }
      try {
        socket.send(JSON.stringify({ op: 'ping' }));
      } catch (_) {
        /* ignore */
      }
    }, PING_MS);
  });

  ws.addEventListener('message', async (ev) => {
    if (gen !== connGen) {
      return;
    }
    let data;
    try {
      data = JSON.parse(String(ev.data || ''));
    } catch (_) {
      return;
    }
    if (!data || typeof data !== 'object') {
      return;
    }
    if (data.op === 'hello') {
      clearTimeout(helloTimer);
      helloTimer = null;
      setStatus('connected');
      lastPongAt = Date.now();
      return;
    }
    if (data.op === 'pong') {
      lastPongAt = Date.now();
      return;
    }
    if (data.op === 'err') {
      setStatus('error', String(data.message || 'err'));
      return;
    }
    if (data.op === 'signal') {
      const row = normalize(data);
      if (mergeSignal(row, true)) {
        // Сначала UI, потом storage — иначе медленный set блокирует появление строки.
        renderBotFilter();
        render();
        // Звук только если сигнал проходит текущий фильтр ботов.
        if (liveReady && passesBotFilter(row)) {
          void playSignalSound(false);
        }
        void persist();
      }
    }
  });

  ws.addEventListener('close', () => {
    if (gen !== connGen) {
      return;
    }
    clearTimeout(helloTimer);
    helloTimer = null;
    clearInterval(pingTimer);
    pingTimer = null;
    if (socket === ws) {
      socket = null;
    }
    setStatus('disconnected', 'Переподключение…');
    scheduleReconnect(gen);
  });

  ws.addEventListener('error', () => {
    if (gen !== connGen) {
      return;
    }
    setStatus('error', 'websocket_error');
  });

  void force;
}

/**
 * @param {number} gen
 */
function scheduleReconnect(gen) {
  if (gen !== connGen) {
    return;
  }
  clearTimeout(reconnectTimer);
  reconnectTimer = setTimeout(() => {
    if (gen !== connGen) {
      return;
    }
    connectWs(true);
  }, RECONNECT_MS);
}

/**
 * @param {object} raw
 */
function normalize(raw) {
  const signalId = raw.signal_id != null ? Number(raw.signal_id) : null;
  return {
    id: signalId ? `sig-${signalId}` : `${raw.bot_id || 0}-${raw.ts || Date.now()}-${raw.instrument || ''}-${raw.side || ''}`,
    signal_id: signalId,
    instrument: String(raw.instrument || ''),
    display_name: raw.display_name ? String(raw.display_name) : '',
    side: String(raw.side || '').toLowerCase(),
    confidence: raw.confidence ?? null,
    payout: raw.payout ?? null,
    expiry_sec: raw.expiry_sec ?? null,
    bot_id: raw.bot_id ?? null,
    bot_name: String(raw.bot_name || ''),
    bot_type: String(raw.bot_type || ''),
    reason: raw.reason ? String(raw.reason) : null,
    ts: Number(raw.ts || Math.floor(Date.now() / 1000)),
  };
}

/**
 * @param {object} row
 * @param {boolean} prepend  true = новый live сверху
 * @returns {boolean} изменился ли список
 */
function mergeSignal(row, prepend) {
  if (!row.instrument || (row.side !== 'call' && row.side !== 'put')) {
    return false;
  }
  const idx = signals.findIndex((s) => s.id === row.id);
  if (idx >= 0) {
    // Дозаполняем display_name у старых записей (иначе клик на PO ищет мусорные имена).
    const cur = signals[idx];
    let changed = false;
    const next = { ...cur };
    if (row.display_name && !cur.display_name) {
      next.display_name = row.display_name;
      changed = true;
    }
    // Если пришёл «настоящий» signal_id — нормализуем id.
    if (row.signal_id && !cur.signal_id) {
      next.signal_id = row.signal_id;
      next.id = `sig-${row.signal_id}`;
      changed = true;
    }
    if (changed) {
      signals[idx] = next;
      return true;
    }
    return false;
  }
  if (prepend) {
    signals = [row, ...signals];
  } else {
    signals = [...signals, row];
  }
  signals.sort((a, b) => Number(b.ts || 0) - Number(a.ts || 0));
  if (signals.length > MAX_SIGNALS) {
    signals = signals.slice(0, MAX_SIGNALS);
  }
  return true;
}

async function persist() {
  // Очередь: два параллельных set не должны затереть более свежий список.
  persistChain = persistChain.then(async () => {
    await chrome.storage.local.set({ signals });
  }).catch((e) => {
    console.warn('[signals] persist', e);
  });
  await persistChain;
}

/**
 * @param {unknown} raw
 * @returns {number[]}
 */
function normalizeFilterIds(raw) {
  if (!Array.isArray(raw)) {
    return [];
  }
  const ids = [];
  for (const v of raw) {
    const n = Number(v);
    if (Number.isFinite(n) && n > 0 && !ids.includes(n)) {
      ids.push(n);
    }
  }
  return ids;
}

/**
 * Уникальные боты из накопленных сигналов (для чекбоксов фильтра).
 * @returns {Array<{ id: number, name: string }>}
 */
function collectBots() {
  /** @type {Map<number, string>} */
  const map = new Map();
  for (const s of signals) {
    const id = Number(s.bot_id || 0);
    if (!id) {
      continue;
    }
    const name = String(s.bot_name || s.bot_type || `Бот #${id}`).trim() || `Бот #${id}`;
    if (!map.has(id) || (name && name !== `Бот #${id}`)) {
      map.set(id, name);
    }
  }
  return [...map.entries()]
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name, 'ru'));
}

/** Пустой filterBotIds = все боты. */
function isFilterActive() {
  return filterBotIds.length > 0;
}

/**
 * @param {object} row
 */
function passesBotFilter(row) {
  if (!isFilterActive()) {
    return true;
  }
  const id = Number(row && row.bot_id || 0);
  return id > 0 && filterBotIds.includes(id);
}

/** @returns {Array<object>} */
function visibleSignals() {
  if (!isFilterActive()) {
    return signals;
  }
  return signals.filter((s) => passesBotFilter(s));
}

/**
 * @param {number[]} ids
 */
async function setFilterBotIds(ids) {
  filterBotIds = normalizeFilterIds(ids);
  await chrome.storage.sync.set({ filterBotIds });
  renderBotFilter();
  render();
}

/** Подпись кнопки «Боты» + активное состояние, если фильтр узкий. */
function updateBotsButton() {
  if (isFilterActive()) {
    btnBotsEl.textContent = `Боты · ${filterBotIds.length}`;
    btnBotsEl.classList.add('active');
  } else {
    btnBotsEl.textContent = 'Боты';
    btnBotsEl.classList.remove('active');
  }
}

/** Пересобрать чекбоксы ботов (если список ботов изменился). */
function renderBotFilter() {
  updateBotsButton();
  const bots = collectBots();
  const sig = `${bots.map((b) => b.id).join(',')}|${filterBotIds.join(',')}`;
  if (sig === botFilterSignature && botFilterEl.childElementCount > 0) {
    // Только обновить checked без перерисовки DOM.
    botFilterEl.querySelectorAll('input[data-bot-id]').forEach((input) => {
      const id = Number(input.getAttribute('data-bot-id'));
      input.checked = !isFilterActive() || filterBotIds.includes(id);
    });
    return;
  }
  botFilterSignature = sig;

  if (!bots.length) {
    botFilterEl.innerHTML = '<span class="empty-bots">Боты появятся после первых сигналов</span>';
    return;
  }

  const allOn = !isFilterActive();
  botFilterEl.innerHTML = bots.map((b) => {
    const checked = allOn || filterBotIds.includes(b.id);
    return `<label title="id ${b.id}">
      <input type="checkbox" data-bot-id="${b.id}" ${checked ? 'checked' : ''} />
      ${escapeHtml(b.name)}
    </label>`;
  }).join('');

  botFilterEl.querySelectorAll('input[data-bot-id]').forEach((input) => {
    input.addEventListener('change', () => {
      const selected = [...botFilterEl.querySelectorAll('input[data-bot-id]:checked')]
        .map((el) => Number(el.getAttribute('data-bot-id')))
        .filter((n) => n > 0);
      // Все отмечены или никто — считаем «без фильтра» (показываем всех).
      if (selected.length === 0 || selected.length === bots.length) {
        void setFilterBotIds([]);
      } else {
        void setFilterBotIds(selected);
      }
    });
  });
}

/**
 * @param {string} status
 * @param {string} [detail]
 */
function setStatus(status, detail) {
  statusEl.className = 'status';
  if (status === 'connected') {
    statusEl.classList.add('status-on');
    statusText.textContent = statusOnlineLabel();
  } else if (status === 'connecting') {
    statusEl.classList.add('status-off');
    statusText.textContent = `Подключение · ${networkLabel()}…`;
  } else if (status === 'error') {
    statusEl.classList.add('status-err');
    statusText.textContent = detail ? `Ошибка: ${detail}` : 'Ошибка';
  } else {
    statusEl.classList.add('status-off');
    statusText.textContent = detail || `Отключено · ${networkLabel()}`;
  }
}

function networkLabel() {
  return networkMode === 'wan' ? 'внешняя' : 'внутр.';
}

function statusOnlineLabel() {
  const net = networkLabel();
  const visible = visibleSignals();
  if (!signals.length) {
    return `Онлайн · ${net} · ждём сигналы`;
  }
  if (isFilterActive()) {
    return `Онлайн · ${net} · ${visible.length}/${signals.length}`;
  }
  return `Онлайн · ${net} · ${signals.length}`;
}

function render() {
  renderBotFilter();
  const list = visibleSignals();
  if (!list.length) {
    const msg = signals.length && isFilterActive()
      ? 'Нет сигналов выбранных ботов'
      : 'Нет сигналов';
    rowsEl.innerHTML = `<tr class="empty"><td colspan="5">${msg}</td></tr>`;
    if (statusEl.classList.contains('status-on')) {
      statusText.textContent = statusOnlineLabel();
    }
    return;
  }
  if (statusEl.classList.contains('status-on')) {
    statusText.textContent = statusOnlineLabel();
  }
  rowsEl.innerHTML = list.map((s) => {
    const side = String(s.side || '').toLowerCase();
    const sideClass = side === 'call' ? 'side-call' : side === 'put' ? 'side-put' : '';
    const sideLabel = side === 'call' ? 'CALL' : side === 'put' ? 'PUT' : side.toUpperCase();
    const conf = s.confidence != null
      ? Math.round(Number(s.confidence) * (Number(s.confidence) <= 1 ? 100 : 1))
      : '—';
    const confLabel = conf === '—' ? '—' : `${conf}%`;
    const time = formatTs(s.ts);
    const pairLabel = s.display_name || s.instrument || '—';
    const pair = escapeHtml(String(pairLabel));
    const bot = escapeHtml(String(s.bot_name || s.bot_type || '—'));
    return `<tr data-instrument="${escapeAttr(String(s.instrument || ''))}" data-display="${escapeAttr(String(s.display_name || ''))}" title="${escapeAttr(s.reason || s.instrument || '')}">
      <td class="pair">${pair}</td>
      <td class="${sideClass}">${sideLabel}</td>
      <td>${confLabel}</td>
      <td class="bot">${bot}</td>
      <td class="time">${time}</td>
    </tr>`;
  }).join('');

  rowsEl.querySelectorAll('tr[data-instrument]').forEach((tr) => {
    tr.addEventListener('click', async () => {
      const instrument = tr.getAttribute('data-instrument') || '';
      let displayName = tr.getAttribute('data-display') || '';
      if (!instrument) {
        return;
      }
      // Старые записи в local без display_name — резолвим с сервера.
      if (!displayName) {
        displayName = await resolveDisplayName(instrument);
        if (displayName) {
          tr.setAttribute('data-display', displayName);
          const row = signals.find((s) => s.instrument === instrument && !s.display_name);
          if (row) {
            row.display_name = displayName;
            persist();
          }
        }
      }
      statusText.textContent = `Открываю ${displayName || instrument}…`;
      const res = await chrome.runtime.sendMessage({
        type: 'ext:open-asset',
        instrument,
        display_name: displayName,
      }).catch((e) => ({ ok: false, error: String(e) }));
      if (res && res.ok) {
        statusText.textContent = res.already
          ? `Уже открыт: ${displayName || instrument}`
          : `Открыт: ${res.matched || displayName || instrument}`;
      } else {
        statusText.textContent = `Не найден на PO: ${displayName || instrument}`;
        console.warn('[signals] open-asset failed', res);
      }
    });
  });
}

/**
 * @param {string} instrument
 * @returns {Promise<string>}
 */
async function resolveDisplayName(instrument) {
  const origin = buildHttpOrigin(serverHost);
  if (!origin) {
    return '';
  }
  // Редкий запрос только по клику на старую строку без display_name.
  const url = `${origin}/api/extension/resolve?instrument=${encodeURIComponent(instrument)}`;
  try {
    const res = await fetch(url, { cache: 'no-store' });
    if (!res.ok) {
      return '';
    }
    const data = await res.json();
    return data && data.display_name ? String(data.display_name) : '';
  } catch (_) {
    return '';
  }
}

function updateSoundLabel() {
  soundNameEl.textContent = soundDataUrl
    ? `Звук: ${soundName || 'свой файл'}`
    : 'Звук: стандартный сигнал';
}

/**
 * @param {boolean} [force] true = тест из настроек (игнор выключателя только если force)
 */
async function playSignalSound(force = false) {
  if (!force && !soundEnabled) {
    return;
  }
  try {
    if (soundDataUrl) {
      const audio = new Audio(soundDataUrl);
      audio.volume = 0.85;
      await audio.play();
      return;
    }
    // Стандартный короткий «пинг» без внешнего файла.
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
      await audioCtx.resume();
    }
    const now = audioCtx.currentTime;
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, now);
    osc.frequency.setValueAtTime(1175, now + 0.09);
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(0.22, now + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.22);
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start(now);
    osc.stop(now + 0.24);
  } catch (e) {
    console.warn('[signals] sound play', e);
  }
}

/**
 * @param {File} file
 * @returns {Promise<string>}
 */
function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(reader.error || new Error('read_failed'));
    reader.readAsDataURL(file);
  });
}

/**
 * @param {number} ts
 */
function formatTs(ts) {
  const n = Number(ts);
  if (!n) {
    return '—';
  }
  const d = new Date(n * 1000);
  const dd = String(d.getDate()).padStart(2, '0');
  const mo = String(d.getMonth() + 1).padStart(2, '0');
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  const ss = String(d.getSeconds()).padStart(2, '0');
  return `${dd}.${mo} ${hh}:${mm}:${ss}`;
}

/**
 * @param {string} s
 */
function escapeHtml(s) {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/**
 * @param {string} s
 */
function escapeAttr(s) {
  return escapeHtml(s).replace(/'/g, '&#39;');
}
