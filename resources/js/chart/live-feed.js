/**
 * PR2: WebSocket live-лента S5 partial → бар текущего TF.
 * + payout push (op=payout) для сайдбара без частого GET /instruments/data.
 *
 * Когда hub доступен — график двигает хвост через series.update (applyIncremental).
 * HTTP /candles poll при live больше не используется (ни 500ms, ни soft-resync):
 * только initial/loadMore. Обрыв WS → reconnect в этом модуле, без REST-fallback.
 * Если live выключен в boot — caller сам держит классический poll.
 */
(function (global) {
    'use strict';

    /**
     * Агрегация одного S5 partial в бар TF без БД.
     * M1+: open/high/low наследуем от уже загруженного lastBar (серверная агрегация),
     * close/high/low расширяем тиком; новый бакет — seed из S5, soft-resync поправит.
     */
    function s5ToTfBar(s5, periodSec, lastBar) {
        if (!s5 || !periodSec) return null;
        const t = Number(s5.effective_feed_time || s5.time || 0);
        if (!Number.isFinite(t) || t <= 0) return null;

        const bucket = Math.floor(t / periodSec) * periodSec;
        const o = Number(s5.open);
        const h = Number(s5.high);
        const l = Number(s5.low);
        const c = Number(s5.close);
        if (![o, h, l, c].every(Number.isFinite)) return null;

        // S5 TF: отдаём OHLC как есть.
        if (periodSec === 5) {
            return { time: bucket, open: o, high: h, low: l, close: c };
        }

        if (lastBar && Number(lastBar.time) === bucket) {
            return {
                time: bucket,
                open: Number(lastBar.open),
                high: Math.max(Number(lastBar.high), h),
                low: Math.min(Number(lastBar.low), l),
                close: c,
            };
        }

        // Устаревший тик (часы/коррекция) — игнор; следующий валидный S5 обновит бар.
        if (lastBar && bucket < Number(lastBar.time)) {
            return null;
        }

        return { time: bucket, open: o, high: h, low: l, close: c };
    }

    /**
     * @param {object} opts
     * @param {string} opts.wsUrl
     * @param {string} opts.token
     * @param {() => string|null} opts.getInstrument
     * @param {() => number} opts.getBarPeriodSec
     * @param {() => object|null} opts.getLastBar
     * @param {(bar: object) => void} opts.onLiveBar
     * @param {(payload: object) => void} [opts.onPayout] — смена % выплаты (broadcast)
     * @param {(payload: object) => void} [opts.onBotEvent] — open/settle сделки бота
     * @param {(connected: boolean) => void} [opts.onConnectionChange]
     */
    function create(opts) {
        const wsUrl = String(opts.wsUrl || '').replace(/\/$/, '');
        const token = String(opts.token || '');
        let socket = null;
        let wantRun = false;
        let reconnectTimer = null;
        let pingTimer = null;
        let subscribedInstrument = null;
        let connected = false;
        let reconnectAttempt = 0;

        function setConnected(ok) {
            if (connected === ok) return;
            connected = ok;
            if (typeof opts.onConnectionChange === 'function') {
                opts.onConnectionChange(ok);
            }
        }

        function clearTimers() {
            if (reconnectTimer) {
                clearTimeout(reconnectTimer);
                reconnectTimer = null;
            }
            if (pingTimer) {
                clearInterval(pingTimer);
                pingTimer = null;
            }
        }

        function scheduleReconnect() {
            if (!wantRun || reconnectTimer) return;
            const delay = Math.min(15000, 500 * Math.pow(1.6, reconnectAttempt));
            reconnectAttempt += 1;
            reconnectTimer = setTimeout(() => {
                reconnectTimer = null;
                openSocket();
            }, delay);
        }

        function sendSubscribe() {
            if (!socket || socket.readyState !== WebSocket.OPEN) return;
            const inst = typeof opts.getInstrument === 'function' ? opts.getInstrument() : null;
            if (!inst) return;
            if (subscribedInstrument === inst) return;
            socket.send(JSON.stringify({ op: 'subscribe', instrument: inst }));
            subscribedInstrument = inst;
        }

        function openSocket() {
            if (!wantRun || !wsUrl || !token) return;
            if (socket && (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING)) {
                return;
            }

            let url = wsUrl;
            const sep = wsUrl.includes('?') ? '&' : '?';
            url = wsUrl + sep + 'token=' + encodeURIComponent(token);

            let ws;
            let openedOk = false;
            try {
                ws = new WebSocket(url);
            } catch (e) {
                console.warn('[chart-live] open failed', e);
                setConnected(false);
                // Уведомляем caller (heal и т.п.); HTTP poll при live больше не включаем.
                if (typeof opts.onConnectionChange === 'function' && !connected) {
                    try { opts.onConnectionChange(false); } catch (_) {}
                }
                scheduleReconnect();
                return;
            }
            socket = ws;
            subscribedInstrument = null;

            ws.onopen = () => {
                openedOk = true;
                reconnectAttempt = 0;
                setConnected(true);
                sendSubscribe();
                clearTimers();
                pingTimer = setInterval(() => {
                    if (ws.readyState === WebSocket.OPEN) {
                        try { ws.send(JSON.stringify({ op: 'ping' })); } catch (_) {}
                    }
                }, 25000);
            };

            ws.onmessage = (ev) => {
                let msg;
                try {
                    msg = JSON.parse(ev.data);
                } catch (_) {
                    return;
                }
                if (!msg || typeof msg !== 'object') return;
                if (msg.op === 's5' && msg.candle) {
                    const periodSec = typeof opts.getBarPeriodSec === 'function'
                        ? opts.getBarPeriodSec()
                        : 60;
                    const lastBar = typeof opts.getLastBar === 'function'
                        ? opts.getLastBar()
                        : null;
                    const bar = s5ToTfBar(msg.candle, periodSec, lastBar);
                    if (bar && typeof opts.onLiveBar === 'function') {
                        opts.onLiveBar(bar);
                    }
                } else if (msg.op === 'payout' && typeof opts.onPayout === 'function') {
                    // Broadcast: обновляем % в сайдбаре без полного GET /instruments/data.
                    try { opts.onPayout(msg); } catch (_) {}
                } else if (msg.op === 'bot_event' && typeof opts.onBotEvent === 'function') {
                    // Open/settle paper-сделки: клиент фильтрует bot_id и обновляет stats.
                    try { opts.onBotEvent(msg); } catch (_) {}
                } else if (msg.op === 'err') {
                    console.warn('[chart-live]', msg.message || 'err');
                }
            };

            ws.onclose = () => {
                if (socket === ws) socket = null;
                subscribedInstrument = null;
                clearTimers();
                if (openedOk) {
                    setConnected(false);
                } else {
                    // Close до onopen: setConnected(false) no-op — всё равно уведомляем caller.
                    connected = false;
                    if (typeof opts.onConnectionChange === 'function') {
                        try { opts.onConnectionChange(false); } catch (_) {}
                    }
                }
                scheduleReconnect();
            };

            ws.onerror = () => {
                // onclose сделает reconnect / fallback notify
            };
        }

        return {
            start() {
                wantRun = true;
                openSocket();
            },
            stop() {
                wantRun = false;
                clearTimers();
                subscribedInstrument = null;
                if (socket) {
                    try { socket.close(); } catch (_) {}
                    socket = null;
                }
                setConnected(false);
            },
            /** Смена инструмента на UI — переподписка на том же сокете. */
            resubscribe() {
                subscribedInstrument = null;
                sendSubscribe();
            },
            isConnected() {
                return connected;
            },
        };
    }

    global.PlatformChartLive = {
        create,
        s5ToTfBar,
    };
})(typeof window !== 'undefined' ? window : globalThis);
