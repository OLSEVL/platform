/**
 * Options: сеть (lan/wan) + режим UI + звук. Домен/IP в UI не показываем.
 */
const soundEnabledEl = document.getElementById('soundEnabled');
const soundFileEl = document.getElementById('soundFile');
const soundNameEl = document.getElementById('soundName');
const msgEl = document.getElementById('msg');
const DEFAULT_MODE = 'sidepanel';
const MAX_SOUND_BYTES = 350 * 1024;

/** @type {AudioContext|null} */
let audioCtx = null;
let soundDataUrl = '';
let soundName = '';

chrome.storage.sync.get({
  serverHost: EXT_ENDPOINTS.lan,
  networkMode: 'wan',
  uiMode: DEFAULT_MODE,
  soundEnabled: true,
}).then((cfg) => {
  const networkMode = inferNetworkMode(cfg.serverHost, cfg.networkMode);
  const netRadio = document.querySelector(`input[name="networkMode"][value="${networkMode}"]`);
  if (netRadio) {
    netRadio.checked = true;
  }
  soundEnabledEl.checked = cfg.soundEnabled !== false;
  const mode = cfg.uiMode === 'float' ? 'float' : 'sidepanel';
  const radio = document.querySelector(`input[name="uiMode"][value="${mode}"]`);
  if (radio) {
    radio.checked = true;
  }
});

chrome.storage.local.get({ soundDataUrl: '', soundName: '' }).then((cfg) => {
  soundDataUrl = typeof cfg.soundDataUrl === 'string' ? cfg.soundDataUrl : '';
  soundName = typeof cfg.soundName === 'string' ? cfg.soundName : '';
  updateSoundLabel();
});

document.getElementById('save').addEventListener('click', async () => {
  const netEl = document.querySelector('input[name="networkMode"]:checked');
  const networkMode = normalizeNetworkMode(netEl && netEl.value);
  const serverHost = resolveServerHost(networkMode);
  const modeEl = document.querySelector('input[name="uiMode"]:checked');
  const uiMode = modeEl && modeEl.value === 'float' ? 'float' : 'sidepanel';
  const soundEnabled = !!soundEnabledEl.checked;
  await chrome.storage.sync.set({ networkMode, serverHost, uiMode, soundEnabled });
  await chrome.runtime.sendMessage({ type: 'ext:apply-ui-mode', mode: uiMode }).catch(() => {});
  // Открытое окно сигналов слушает storage.onChanged и само переподключит WS.
  msgEl.textContent = networkMode === 'wan'
    ? 'Сохранено: внешняя сеть. Окно сигналов переподключается…'
    : 'Сохранено: внутренняя сеть. Окно сигналов переподключается…';
});

document.getElementById('open').addEventListener('click', async () => {
  await chrome.runtime.sendMessage({ type: 'ext:open-ui' }).catch(() => {});
  msgEl.textContent = 'Открыто';
});

document.getElementById('soundTest').addEventListener('click', () => {
  void playSound();
});

document.getElementById('soundReset').addEventListener('click', async () => {
  soundDataUrl = '';
  soundName = '';
  soundFileEl.value = '';
  await chrome.storage.local.set({ soundDataUrl: '', soundName: '' });
  updateSoundLabel();
  msgEl.textContent = 'Звук сброшен на стандартный';
});

soundFileEl.addEventListener('change', async () => {
  const file = soundFileEl.files && soundFileEl.files[0];
  if (!file) {
    return;
  }
  if (file.size > MAX_SOUND_BYTES) {
    msgEl.textContent = `Файл слишком большой (макс. ${Math.round(MAX_SOUND_BYTES / 1024)} КБ)`;
    soundFileEl.value = '';
    return;
  }
  try {
    soundDataUrl = await readFileAsDataUrl(file);
    soundName = file.name || 'custom';
    await chrome.storage.local.set({ soundDataUrl, soundName });
    soundEnabledEl.checked = true;
    await chrome.storage.sync.set({ soundEnabled: true });
    updateSoundLabel();
    msgEl.textContent = `Звук сохранён: ${soundName}`;
    void playSound();
  } catch (e) {
    msgEl.textContent = 'Не удалось прочитать файл';
    console.warn('[options] sound', e);
  }
});

function updateSoundLabel() {
  soundNameEl.textContent = soundDataUrl
    ? `Звук: ${soundName || 'свой файл'}`
    : 'Звук: стандартный сигнал';
}

async function playSound() {
  try {
    if (soundDataUrl) {
      const audio = new Audio(soundDataUrl);
      audio.volume = 0.85;
      await audio.play();
      return;
    }
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
    msgEl.textContent = 'Не удалось воспроизвести звук';
    console.warn('[options] play', e);
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
