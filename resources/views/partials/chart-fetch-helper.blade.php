{{-- @verbatim: JSDoc содержит {{ }} и @param — иначе Blade сломает файл. --}}
@verbatim
<script>
/**
 * PlatformChartFetch — общий helper для JSON-fetch на графиках (dashboard / bots/show).
 *
 * Зачем:
 * 1) Несколько параллельных GET (таймер 15с, смена TF, loadMore, poll) раньше «перетирали»
 *    друг друга: поздний ответ устаревшего запроса рисовал чужие panes / зоны, либо
 *    seq-бамп от таймера отбрасывал ещё не доехавший тяжёлый classic на M1.
 * 2) Единый контракт: канал (name) + ключ (key) + AbortController + seq.
 *
 * Контракт requestJson(name, opts):
 *  - name: независимый канал («classic», «structures», «candles-poll»…). Счётчики не смешиваются.
 *  - key: строка идентичности запроса (instrument|tf|layers…). Тот же key in-flight → coalesce (skip).
 *  - Другой key / invalidate → abort предыдущего HTTP и bump seq (ответ не apply).
 *  - apply(data, meta) вызывается ТОЛЬКО если ответ ещё актуален (seq совпадает).
 *
 * Возвращает Promise<data|undefined> | null:
 *  - null — coalesce: такой же запрос уже в полёте, новый не стартовали;
 *  - Promise → data при успехе; undefined при abort / stale (без throw на AbortError).
 */
(function attachPlatformChartFetchHelper(global) {
    if (global.PlatformChartFetch) {
        return;
    }

    /** @type {Map<string, {name:string, seq:number, inFlightKey:string|null, controller:AbortController|null}>} */
    const channels = new Map();

    function getChannel(name) {
        const id = String(name || 'default');
        let ch = channels.get(id);
        if (!ch) {
            ch = {
                name: id,
                seq: 0,
                inFlightKey: null,
                controller: null,
            };
            channels.set(id, ch);
        }
        return ch;
    }

    /**
     * Инвалидация канала: abort in-flight + bump seq.
     * Вызывать при смене инструмента / TF / выключении слоя, чтобы late-ответ не применился.
     */
    function invalidate(name) {
        const ch = channels.get(String(name || ''));
        if (!ch) return;
        ch.seq += 1;
        if (ch.controller) {
            try { ch.controller.abort(); } catch (_) {}
            ch.controller = null;
        }
        ch.inFlightKey = null;
    }

    /** Инвалидировать все каналы страницы (полная смена контекста графика). */
    function invalidateAll() {
        channels.forEach((_, name) => invalidate(name));
    }

    function isCurrent(name, seq) {
        const ch = channels.get(String(name || ''));
        return !!(ch && ch.seq === seq);
    }

    /**
     * @param {string} name
     * @param {object} opts — url, key?, coalesce?, abortPrevious?, init?, apply?, onError?
     * @returns {Promise|null}
     */
    function requestJson(name, opts) {
        const options = opts || {};
        const url = options.url;
        if (!url) {
            console.error('[PlatformChartFetch] url обязателен', name);
            return null;
        }

        const ch = getChannel(name);
        const key = options.key != null ? String(options.key) : String(url);
        const coalesce = options.coalesce !== false;
        const abortPrevious = options.abortPrevious !== false;

        // Тот же смысловой запрос уже летит — не плодим второй и не бампим seq
        // (иначе таймер 15с убивал бы долгий classic на M1).
        if (coalesce && ch.inFlightKey === key && ch.controller) {
            return null;
        }

        if (abortPrevious && ch.controller) {
            try { ch.controller.abort(); } catch (_) {}
            ch.controller = null;
        }

        const seq = ++ch.seq;
        const controller = new AbortController();
        ch.controller = controller;
        ch.inFlightKey = key;

        const baseHeaders = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        const userInit = options.init || {};
        const headers = Object.assign({}, baseHeaders, userInit.headers || {});
        const init = Object.assign({}, userInit, {
            headers: headers,
            signal: controller.signal,
        });

        const meta = { seq: seq, key: key, name: ch.name };

        const promise = fetch(url, init)
            .then((r) => {
                if (!r.ok) {
                    const err = new Error('[PlatformChartFetch] HTTP ' + r.status);
                    err.status = r.status;
                    throw err;
                }
                return r.json();
            })
            .then((data) => {
                // Устаревший ответ (invalidate / новый запрос в том же канале).
                if (seq !== ch.seq) return undefined;
                if (typeof options.apply === 'function') {
                    options.apply(data, meta);
                }
                return data;
            })
            .catch((err) => {
                // Abort — нормальный исход при смене key / invalidate; наружу не шумим.
                if (err && err.name === 'AbortError') return undefined;
                if (seq !== ch.seq) return undefined;
                if (typeof options.onError === 'function') {
                    try { options.onError(err, meta); } catch (_) {}
                }
                return undefined;
            })
            .finally(() => {
                // Снимаем «замок» только у своего запроса.
                if (ch.seq === seq && ch.inFlightKey === key) {
                    ch.inFlightKey = null;
                    ch.controller = null;
                }
            });

        return promise;
    }

    global.PlatformChartFetch = {
        requestJson: requestJson,
        invalidate: invalidate,
        invalidateAll: invalidateAll,
        isCurrent: isCurrent,
    };
})(window);
</script>
@endverbatim
