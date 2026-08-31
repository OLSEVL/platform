<script>
/**
 * Единое форматирование wall-clock времени для всего фронта.
 *
 * Источник TZ — config('app.timezone') / APP_TIMEZONE (.env), как у Laravel/Carbon
 * в PHP (сайдбар, лента сделок, analytics). Unix-моменты (свечи, сделки) не сдвигаем —
 * только отображение в одной зоне через Intl.DateTimeFormat.
 */
(function attachPlatformTimeFormat(global) {
    if (global.PlatformTimeFormat) {
        return;
    }

    const APP_TZ = @json(config('app.timezone'));
    const APP_LOCALE = 'ru-RU';
    global.PlatformAppTimezone = APP_TZ;

    const pad2 = (v) => String(v ?? '0').padStart(2, '0');

    /** Разбор unix-секунды в части даты/времени в APP_TZ. */
    function parts(unixSec, options) {
        const sec = Math.floor(Number(unixSec));
        if (!Number.isFinite(sec)) {
            return null;
        }
        try {
            const formatted = new Intl.DateTimeFormat(APP_LOCALE, {
                timeZone: APP_TZ,
                hour12: false,
                ...options,
            }).formatToParts(new Date(sec * 1000));
            const out = {};
            formatted.forEach((p) => {
                if (p.type !== 'literal') {
                    out[p.type] = p.value;
                }
            });
            return out;
        } catch (_) {
            return null;
        }
    }

    /** HH:MM:SS в APP_TIMEZONE. */
    function hms(unixSec) {
        const p = parts(unixSec, {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
        if (!p) {
            return '';
        }
        return `${pad2(p.hour)}:${pad2(p.minute)}:${pad2(p.second)}`;
    }

    /** HH:MM в APP_TIMEZONE. */
    function hm(unixSec) {
        const p = parts(unixSec, {
            hour: '2-digit',
            minute: '2-digit',
        });
        if (!p) {
            return '';
        }
        return `${pad2(p.hour)}:${pad2(p.minute)}`;
    }

    /** Подпись кроссхейра: «ДД HH:MM:SS» в APP_TIMEZONE. */
    function crosshairDateTime(unixSec) {
        const p = parts(unixSec, {
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
        if (!p) {
            return '';
        }
        return `${pad2(p.day)} ${pad2(p.hour)}:${pad2(p.minute)}:${pad2(p.second)}`;
    }

    /** Текущее время «сейчас» в APP_TIMEZONE (HH:MM:SS). */
    function nowHms() {
        return hms(Math.floor(Date.now() / 1000));
    }

    /** «ДД.ММ.ГГГГ ЧЧ:ММ:СС» в APP_TIMEZONE — как в PHP Carbon->format. */
    function dmyHms(unixSec) {
        const p = parts(unixSec, {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
        if (!p) {
            return '—';
        }
        return `${pad2(p.day)}.${pad2(p.month)}.${p.year} ${pad2(p.hour)}:${pad2(p.minute)}:${pad2(p.second)}`;
    }

    /** ISO-строка → dmyHms в APP_TIMEZONE. */
    function dmyHmsFromIso(isoString) {
        if (!isoString) {
            return '—';
        }
        const ms = Date.parse(String(isoString));
        if (!Number.isFinite(ms)) {
            return '—';
        }
        return dmyHms(Math.floor(ms / 1000));
    }

    /** «ГГГГ-ММ-ДД ЧЧ:ММ» в APP_TIMEZONE (бэктест, таблицы). */
    function ymdHm(unixSec) {
        const p = parts(unixSec, {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
        if (!p) {
            return '—';
        }
        return `${p.year}-${pad2(p.month)}-${pad2(p.day)} ${pad2(p.hour)}:${pad2(p.minute)}`;
    }

    /** Значение для input[type=datetime-local] в APP_TIMEZONE. */
    function datetimeLocalValue(unixSec) {
        const p = parts(unixSec, {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
        if (!p) {
            return '';
        }
        return `${p.year}-${pad2(p.month)}-${pad2(p.day)}T${pad2(p.hour)}:${pad2(p.minute)}`;
    }

    function toChartUnix(time) {
        if (time == null) {
            return null;
        }
        if (typeof time === 'number' && Number.isFinite(time)) {
            return time > 1e12 ? Math.floor(time / 1000) : Math.floor(time);
        }
        if (typeof time === 'object' && time.year != null) {
            const ms = Date.UTC(
                Number(time.year),
                Number(time.month) - 1,
                Number(time.day),
            );
            return Number.isFinite(ms) ? Math.floor(ms / 1000) : null;
        }
        return null;
    }

    /**
     * Подписи оси времени LWC — та же APP_TIMEZONE, что PHP и маркеры сделок.
     * tickMarkType: 0 Year, 1 Month, 2 Day, 3 Time, 4 TimeWithSeconds.
     */
    function lwcTickMarkFormatter(time, tickMarkType, locale) {
        const sec = toChartUnix(time);
        if (!Number.isFinite(sec)) {
            return null;
        }
        const d = new Date(sec * 1000);
        switch (tickMarkType) {
            case 0:
                return new Intl.DateTimeFormat(locale || APP_LOCALE, {
                    timeZone: APP_TZ,
                    year: 'numeric',
                }).format(d);
            case 1:
                return new Intl.DateTimeFormat(locale || APP_LOCALE, {
                    timeZone: APP_TZ,
                    month: 'short',
                }).format(d);
            case 2:
                return new Intl.DateTimeFormat(locale || APP_LOCALE, {
                    timeZone: APP_TZ,
                    day: 'numeric',
                }).format(d);
            case 4:
                return hms(sec);
            case 3:
            default:
                return hm(sec);
        }
    }

    /** localization.timeFormatter LWC — для нативных подписей (если включены). */
    function lwcTimeFormatter(time) {
        const sec = toChartUnix(time);
        return Number.isFinite(sec) ? crosshairDateTime(sec) : '';
    }

    /**
     * Опции createChart: localization + timeScale.tickMarkFormatter в APP_TIMEZONE.
     * @param {object} timeScaleBase — borderColor, timeVisible, secondsVisible, …
     */
    function buildChartTimezoneOptions(timeScaleBase) {
        const base = timeScaleBase || {};
        return {
            localization: {
                locale: APP_LOCALE,
                timeFormatter: lwcTimeFormatter,
            },
            timeScale: {
                ...base,
                tickMarkFormatter: lwcTickMarkFormatter,
            },
        };
    }

    global.PlatformTimeFormat = {
        timezone: APP_TZ,
        locale: APP_LOCALE,
        parts,
        hms,
        hm,
        crosshairDateTime,
        nowHms,
        dmyHms,
        dmyHmsFromIso,
        ymdHm,
        datetimeLocalValue,
        lwcTickMarkFormatter,
        lwcTimeFormatter,
        buildChartTimezoneOptions,
    };
})(window);
</script>
