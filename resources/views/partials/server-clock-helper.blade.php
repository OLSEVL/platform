{{-- Синхронизация «сейчас» с сервером: таймеры expiry не зависят от рассинхрона часов браузера. --}}
@verbatim
<script>
(function attachPlatformServerClock(global) {
    if (global.PlatformServerClock) {
        return;
    }
    /** Смещение Date.now() относительно server_time последнего API-ответа (мс). */
    let offsetMs = 0;

    /**
     * @param {string|{server_time?: string}|null|undefined} isoOrPayload
     */
    function sync(isoOrPayload) {
        if (isoOrPayload == null) return;
        const iso = (typeof isoOrPayload === 'object' && isoOrPayload !== null)
            ? isoOrPayload.server_time
            : isoOrPayload;
        if (!iso) return;
        const parsed = Date.parse(String(iso));
        if (Number.isFinite(parsed)) {
            offsetMs = Date.now() - parsed;
        }
    }

    /** Unix-секунды «сейчас» по серверу (для expiry_ts, countdown). */
    function unixNow() {
        return Math.floor((Date.now() - offsetMs) / 1000);
    }

    global.PlatformServerClock = {
        sync: sync,
        unixNow: unixNow,
        getOffsetMs: function () { return offsetMs; },
    };
})(window);
</script>
@endverbatim
