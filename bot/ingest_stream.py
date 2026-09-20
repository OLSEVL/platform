# -*- coding: utf-8 -*-
"""
True Streaming Ingest: держит постоянные WebSocket-соединения к Pocket Option,
ловит каждый тик в реальном времени, формирует минутные свечи и пишет их в БД
по завершению каждой минуты.

Запуск: из pocket-options-proxy.py (lifespan) или отдельно:
  asyncio.run(run_ingest_forever())
"""

import os
import asyncio
import time
from datetime import datetime, timezone
from typing import Optional, List, Dict, Any

# Путь к PocketOptionAPI
import sys
_bot_dir = os.path.dirname(os.path.abspath(__file__))
_pocket_path = os.path.join(_bot_dir, "python", "PocketOptionAPI-main")
if _pocket_path not in sys.path:
    sys.path.insert(0, _pocket_path)

import aiohttp
from pocketoptionapi_async import AsyncPocketOptionClient

# Конфиг из env
PLATFORM_URL = os.environ.get("PLATFORM_URL", "").rstrip("/")
MAX_CONNECTIONS = int(os.environ.get("INGEST_MAX_CONNECTIONS", "100"))
INSTRUMENTS_REFRESH_SEC = int(os.environ.get("INSTRUMENTS_REFRESH_SEC", "300"))
# Как часто проверять proxy_pool_version после «Обновить прокси» в настройках платформы.
PROXY_POOL_VERSION_POLL_SEC = int(os.environ.get("PROXY_POOL_VERSION_POLL_SEC", "15"))
# После N неудачных connect выходим из воркера — supervisor пересоздаст его с актуальным proxy_url
# (иначе воркер навсегда крутит sleep 300s со старым proxy в памяти, а БД уже ротирует endpoint).
CONNECT_FAIL_EXIT_STREAK = int(os.environ.get("INGEST_CONNECT_FAIL_EXIT_STREAK", "3"))
# Макс. backoff до выхода/ретрая — не 300с: иначе одна мёртвая пара «молчит» часами.
CONNECT_FAIL_MAX_SLEEP_SEC = int(os.environ.get("INGEST_CONNECT_FAIL_MAX_SLEEP_SEC", "45"))
# Сколько ждать завершения воркера после task.cancel().
# Без лимита супервизор навечно зависает на await, если finally→disconnect() залип
# на полумёртвом SOCKS/WebSocket — тогда пропадают poll pool-version / instruments
# и Laravel перестаёт помечать прокси dead по stale PO.
WORKER_CANCEL_AWAIT_TIMEOUT_SEC = float(
    os.environ.get("INGEST_WORKER_CANCEL_AWAIT_TIMEOUT_SEC", "5")
)
# Таймаут client.disconnect() в finally воркера (websocket.close на мёртвом прокси).
WORKER_DISCONNECT_TIMEOUT_SEC = float(
    os.environ.get("INGEST_WORKER_DISCONNECT_TIMEOUT_SEC", "3")
)
# Таймаут client.connect() — иначе cancel во время connect тоже может не вернуться.
WORKER_CONNECT_TIMEOUT_SEC = float(
    os.environ.get("INGEST_WORKER_CONNECT_TIMEOUT_SEC", "45")
)
# Базовый период свечи в секундах.
# Для нового секундного режима по умолчанию используем S5.
BASE_BAR_PERIOD_SEC = int(os.environ.get("INGEST_BASE_BAR_SECONDS", "5"))
# Внутренний timeframe ID для записи базового ряда (S5 = 4005).
BASE_TIMEFRAME_ID = int(os.environ.get("INGEST_BASE_TIMEFRAME_ID", "4005"))
CANDLES_REQUEST_COUNT = 100  # количество свечей для начального backfill
# Интервал главного цикла (flush завершённых минут, POST текущей свечи, payout).
# Раньше было 5s: при этом даже при debounce=1s текущая свеча на платформу уходила не чаще раз в 5s —
# отсюда ощущаемое отставание ~5–10s от графика Pocket Options.
# float: иначе INGEST_LOOP_INTERVAL_SEC=0.25 обрежется int() до 0 и цикл станет busy-spin.
FLUSH_CHECK_INTERVAL = float(os.environ.get("INGEST_LOOP_INTERVAL_SEC", "1"))
# Защита от нуля/отрицательных значений (минимальный разумный шаг цикла ~50 мс).
if FLUSH_CHECK_INTERVAL <= 0:
    FLUSH_CHECK_INTERVAL = 0.05

INGEST_USE_API_CANDLES = os.environ.get("INGEST_USE_API_CANDLES", "1").strip().lower() in (
    "1",
    "true",
    "yes",
)
# Stream-first: fallback запрашиваем только если поток "замолчал".
INGEST_FALLBACK_IDLE_SEC = int(os.environ.get("INGEST_FALLBACK_IDLE_SEC", "20"))
INGEST_FALLBACK_MIN_INTERVAL_SEC = int(
    os.environ.get("INGEST_FALLBACK_MIN_INTERVAL_SEC", "60")
)
# 0/false: только сводка flush; 1/true: строка flush_detail на каждую свечу (удобно для journalctl)
INGEST_FLUSH_DETAIL = os.environ.get("INGEST_FLUSH_DETAIL", "1").strip().lower() in (
    "1",
    "true",
    "yes",
)
# 0: не удалять из памяти завершённые свечи, пока POST не пройдёт (безопасно при сбоях API).
# >0: удалять завершённые свечи старше N секунд (защита от утечки RAM при вечно мёртвом бэкенде).
INGEST_PRUNE_COMPLETED_OLDER_THAN_SEC = int(
    os.environ.get("INGEST_PRUNE_COMPLETED_OLDER_THAN_SEC", "0")
)
INGEST_HTTP_CONN_LIMIT = int(os.environ.get("INGEST_HTTP_CONN_LIMIT", "100"))
INGEST_HTTP_CONN_PER_HOST = int(os.environ.get("INGEST_HTTP_CONN_PER_HOST", "30"))
INGEST_PAYOUT_ENABLE = os.environ.get("INGEST_PAYOUT_ENABLE", "1").strip().lower() in (
    "1",
    "true",
    "yes",
)
INGEST_PAYOUT_TTL_SEC = int(os.environ.get("INGEST_PAYOUT_TTL_SEC", "120"))
INGEST_PAYOUT_POST_DEBOUNCE_SEC = int(
    os.environ.get("INGEST_PAYOUT_POST_DEBOUNCE_SEC", "30")
)
INGEST_CURRENT_CANDLE_ENABLE = os.environ.get("INGEST_CURRENT_CANDLE_ENABLE", "1").strip().lower() in (
    "1",
    "true",
    "yes",
)
INGEST_CURRENT_CANDLE_TTL_SEC = int(os.environ.get("INGEST_CURRENT_CANDLE_TTL_SEC", "180"))
INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC = float(
    os.environ.get("INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC", "1")
)
# 1: писать partial прямо в Redis (setex + PUBLISH chart:s5), минуя PHP-FPM.
# Нужно при debounce ≤0.25s × ~100 инструментов: pm.max_children=5 не тянет HTTP.
INGEST_CURRENT_CANDLE_REDIS_DIRECT = os.environ.get(
    "INGEST_CURRENT_CANDLE_REDIS_DIRECT", "1"
).strip().lower() in ("1", "true", "yes")
# Redis — те же значения, что у Laravel / chart_live_hub (prefix обязателен для pub/sub).
REDIS_HOST = os.environ.get("REDIS_HOST", "127.0.0.1")
REDIS_PORT = int(os.environ.get("REDIS_PORT", "6379"))
_REDIS_PASSWORD_RAW = (os.environ.get("REDIS_PASSWORD") or "").strip()
REDIS_PASSWORD = (
    ""
    if _REDIS_PASSWORD_RAW.lower() in ("", "null", "none")
    else _REDIS_PASSWORD_RAW
)
REDIS_PREFIX = os.environ.get("REDIS_PREFIX") or "laravel-database-"
# Поправка времени feed в секундах (например -7200, если источник стабильно "вперёд" на 2 часа).
# Применяется централизованно при расчёте effective времени свечи.
FEED_TIME_CORRECTION_SEC = int(os.environ.get("FEED_TIME_CORRECTION_SEC", "0"))
# Логировать дельту feed↔server при отправке partial свечи (для диагностики смещений).
FEED_TIME_DELTA_LOG_ENABLE = os.environ.get("FEED_TIME_DELTA_LOG_ENABLE", "1").strip().lower() in (
    "1",
    "true",
    "yes",
)

_ingest_http_session: Optional[aiohttp.ClientSession] = None
_ingest_http_lock: Optional[asyncio.Lock] = None
# Один async Redis на процесс — setex/publish partial без очереди в PHP-FPM.
_ingest_redis = None
_ingest_redis_lock: Optional[asyncio.Lock] = None


async def _await_cancelled_worker(
    task: asyncio.Task,
    label: str,
    timeout_sec: Optional[float] = None,
) -> None:
    """
    Ждём завершения уже отменённого воркера, но не дольше timeout_sec.

    Если finally воркера завис на disconnect/close — супервизор всё равно продолжает
    цикл (refresh instruments → dead-on-stale). Старая task может ещё жить как orphan
    до своего disconnect; новый воркер на ту же пару при этом уже можно стартовать.
    """
    limit = WORKER_CANCEL_AWAIT_TIMEOUT_SEC if timeout_sec is None else float(timeout_sec)
    try:
        await asyncio.wait_for(asyncio.shield(task), timeout=max(0.1, limit))
    except asyncio.TimeoutError:
        print(
            f"[INGEST] worker {label}: cancel await timed out after {limit:.1f}s "
            f"(orphan may still be disconnecting; supervisor continues)",
            flush=True,
        )
    except asyncio.CancelledError:
        # Сам супервизор отменён, или task уже завершился CancelledError'ом — норма.
        pass
    except Exception as e:
        print(f"[INGEST] worker {label}: cancel await error: {e!r}", flush=True)


async def _disconnect_client_safely(
    client: Any,
    instrument: str,
    timeout_sec: Optional[float] = None,
) -> None:
    """
    Обрыв WS с таймаутом — не блокируем finally и cancel-путь супервизора.

    shield(disconnect): при TimeoutError wait_for не должен повторно cancel'ить
    уже залипший close() и снова ждать его вечно; coroutine disconnect уходит
    в фон и мы продолжаем cleanup воркера.
    """
    if client is None:
        return
    limit = WORKER_DISCONNECT_TIMEOUT_SEC if timeout_sec is None else float(timeout_sec)
    try:
        await asyncio.wait_for(
            asyncio.shield(client.disconnect()),
            timeout=max(0.1, limit),
        )
    except asyncio.TimeoutError:
        print(
            f"[INGEST] {instrument}: disconnect timed out after {limit:.1f}s "
            f"(abandoning stuck websocket close)",
            flush=True,
        )
    except asyncio.CancelledError:
        # Cancel во время cleanup — не пробрасываем, иначе finally воркера
        # может снова застрять на пути отмены супервизора.
        print(
            f"[INGEST] {instrument}: disconnect interrupted by cancel",
            flush=True,
        )
    except Exception as e:
        print(f"[INGEST] {instrument}: disconnect error: {e!r}", flush=True)


def _ingest_lock() -> asyncio.Lock:
    global _ingest_http_lock
    if _ingest_http_lock is None:
        _ingest_http_lock = asyncio.Lock()
    return _ingest_http_lock


def _tick_volume_delta(tick_row: Any) -> float:
    """
    Прирост объёма по одному тику.
    Формат стрима: [asset, ts, price] или реже [asset, ts, price, volume, ...].
    """
    if isinstance(tick_row, (list, tuple)) and len(tick_row) >= 4:
        try:
            return float(tick_row[3])
        except (TypeError, ValueError):
            pass
    return 1.0


def _volume_from_candle_array(cd: Any) -> float:
    """Свеча с сервера: [ts, open, close, high, low, volume?]."""
    if isinstance(cd, (list, tuple)) and len(cd) > 5:
        try:
            return float(cd[5])
        except (TypeError, ValueError):
            pass
    return 0.0


def _to_unix_seconds(ts: Any) -> int:
    """PocketOption может отдавать timestamp как секунды или миллисекунды.

    Если похоже на миллисекунды (значительно больше, чем current Unix seconds),
    нормализуем к секундами.
    """
    try:
        raw = int(float(ts))
    except (TypeError, ValueError):
        return 0

    # Unix seconds сейчас ~1.7e9. Иногда можно встретить ms/micros/nanos.
    # Делаем деление на 1000 пока число похоже не "на секунды".
    if raw <= 0:
        return 0

    while raw > 10_000_000_000:
        raw //= 1000
    return raw


def _to_raw_int(ts: Any) -> int:
    """Возвращает timestamp "как прислали" (без нормализации масштаба)."""
    try:
        return int(float(ts))
    except (TypeError, ValueError):
        return 0


def _apply_feed_time_correction(unix_sec: int) -> int:
    """Применяет конфигурируемую коррекцию feed-времени (сек)."""
    return max(1, int(unix_sec) + FEED_TIME_CORRECTION_SEC)


def _to_effective_candle_time(unix_sec: int) -> int:
    """Возвращает effective время свечи, выровненное по базовому бакету."""
    corrected = _apply_feed_time_correction(unix_sec)
    return max(1, (corrected // BASE_BAR_PERIOD_SEC) * BASE_BAR_PERIOD_SEC)


async def _ensure_ingest_http_session() -> aiohttp.ClientSession:
    """Один ClientSession на процесс (пул соединений), см. aiohttp: переиспользование сессии."""
    global _ingest_http_session
    async with _ingest_lock():
        if _ingest_http_session is None or _ingest_http_session.closed:
            connector = aiohttp.TCPConnector(
                limit=INGEST_HTTP_CONN_LIMIT,
                limit_per_host=INGEST_HTTP_CONN_PER_HOST,
                enable_cleanup_closed=True,
            )
            _ingest_http_session = aiohttp.ClientSession(
                connector=connector,
                timeout=aiohttp.ClientTimeout(total=30),
                headers={"Accept": "application/json"},
            )
        return _ingest_http_session


async def close_ingest_http_session() -> None:
    """Закрыть пул HTTP (вызывать при остановке приложения)."""
    global _ingest_http_session
    async with _ingest_lock():
        if _ingest_http_session is not None and not _ingest_http_session.closed:
            await _ingest_http_session.close()
            await asyncio.sleep(0.25)
        _ingest_http_session = None
    await close_ingest_redis()


async def _ensure_ingest_redis():
    """Ленивый redis.asyncio клиент (тот же host/prefix, что Laravel)."""
    global _ingest_redis, _ingest_redis_lock
    if _ingest_redis_lock is None:
        _ingest_redis_lock = asyncio.Lock()
    async with _ingest_redis_lock:
        if _ingest_redis is not None:
            return _ingest_redis
        try:
            import redis.asyncio as redis_async
        except ImportError:
            print("[INGEST] redis.asyncio unavailable — current candle stays on HTTP", flush=True)
            return None
        kwargs: Dict[str, Any] = {
            "host": REDIS_HOST,
            "port": REDIS_PORT,
            "decode_responses": True,
        }
        if REDIS_PASSWORD:
            kwargs["password"] = REDIS_PASSWORD
        client = redis_async.Redis(**kwargs)
        try:
            await client.ping()
        except Exception as e:
            print(f"[INGEST] Redis ping failed ({e}) — current candle stays on HTTP", flush=True)
            try:
                await client.aclose()
            except Exception:
                pass
            return None
        _ingest_redis = client
        print(
            f"[INGEST] Redis direct current-candle ON "
            f"({REDIS_HOST}:{REDIS_PORT} prefix={REDIS_PREFIX!r})",
            flush=True,
        )
        return _ingest_redis


async def close_ingest_redis() -> None:
    """Закрыть Redis-клиент ingest (вместе с HTTP session при shutdown)."""
    global _ingest_redis
    if _ingest_redis_lock is None:
        return
    async with _ingest_redis_lock:
        if _ingest_redis is not None:
            try:
                await _ingest_redis.aclose()
            except Exception:
                pass
            _ingest_redis = None


async def put_current_candle_redis_direct(
    instrument: str,
    candle: Dict[str, Any],
    ttl_sec: int,
) -> bool:
    """
    Зеркало ingest-current-light.php / CurrentCandleCacheService::putPartial:
    SETEX current_candle:{instrument} + PUBLISH chart:s5 — без PHP.
    """
    client = await _ensure_ingest_redis()
    if client is None:
        return False
    now = int(time.time())
    payload = {
        "instrument": instrument,
        "time": int(candle.get("time", 0)),
        "raw_feed_time": int(candle.get("raw_feed_time", candle.get("time", 0))),
        "effective_feed_time": int(
            candle.get("effective_feed_time", candle.get("time", 0))
        ),
        "open": float(candle.get("open", 0)),
        "high": float(candle.get("high", 0)),
        "low": float(candle.get("low", 0)),
        "close": float(candle.get("close", 0)),
        "volume": float(candle.get("volume", 0)),
        "is_partial": True,
        "updated_at": int(candle.get("updated_at", now) or now),
        "ingest_received_at": now,
    }
    try:
        import json as _json

        raw = _json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
    except (TypeError, ValueError):
        return False
    key = f"{REDIS_PREFIX}current_candle:{instrument}"
    channel = f"{REDIS_PREFIX}chart:s5"
    try:
        pipe = client.pipeline(transaction=False)
        pipe.setex(key, int(ttl_sec), raw)
        pipe.publish(channel, raw)
        await pipe.execute()
        return True
    except Exception as e:
        print(f"[INGEST] put_current_candle_redis_direct {instrument}: {e}", flush=True)
        return False


def create_ssid(session_token: str, uid: str, is_demo: bool = True) -> str:
    uid_int = int(uid) if uid else 0
    demo = 1 if is_demo else 0
    return f'42["auth",{{"session":"{session_token}","uid":{uid_int},"isDemo":{demo},"platform":2,"isFastHistory":true,"isOptimized":true}}]'


async def fetch_proxy_pool_version() -> int:
    """GET /api/proxy/pool-version — меняется при полном refresh Webshare."""
    if not PLATFORM_URL:
        return 0
    url = f"{PLATFORM_URL}/api/proxy/pool-version"
    try:
        session = await _ensure_ingest_http_session()
        async with session.get(url, headers={"Accept": "application/json"}) as resp:
            if resp.status != 200:
                return 0
            data = await resp.json()
            return int(data.get("version") or 0)
    except Exception:
        return 0


async def fetch_instruments() -> List[Dict[str, Any]]:
    """GET /api/proxy/instruments."""
    if not PLATFORM_URL:
        print("[INGEST] fetch_instruments: PLATFORM_URL not set")
        return []
    url = f"{PLATFORM_URL}/api/proxy/instruments"
    headers = {"Accept": "application/json"}
    try:
        session = await _ensure_ingest_http_session()
        async with session.get(url, headers=headers) as resp:
            if resp.status != 200:
                body = await resp.text()
                print(f"[INGEST] fetch_instruments HTTP {resp.status} url={url} body={body[:200]}")
                return []
            return await resp.json()
    except Exception as e:
        print(f"[INGEST] fetch_instruments error url={url}: {e}")
        return []


async def post_ingest_candles(instrument: str, candles: List[Dict[str, Any]]) -> bool:
    """POST /api/ingest-candles."""
    if not PLATFORM_URL or not candles:
        return False
    url = f"{PLATFORM_URL}/api/ingest-candles"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    payload = {
        "instrument": instrument,
        # Пишем в БД именно базовый TF (по умолчанию S5=4005).
        "timeframe": BASE_TIMEFRAME_ID,
        "candles": [
            {
                "time": int(c.get("time", c.get("timestamp", 0))),
                "open": float(c.get("open", 0)),
                "high": float(c.get("high", 0)),
                "low": float(c.get("low", 0)),
                "close": float(c.get("close", 0)),
                "volume": float(c.get("volume", 0)),
            }
            for c in candles
        ],
    }
    try:
        session = await _ensure_ingest_http_session()
        async with session.post(url, headers=headers, json=payload) as resp:
            if resp.status not in (200, 201, 204):
                body = await resp.text()
                print(f"[INGEST] post_ingest_candles {instrument} HTTP {resp.status} body={body[:300]}")
                return False
            return True
    except Exception as e:
        print(f"[INGEST] post_ingest_candles {instrument}: {e}")
        return False


async def post_ingest_payout(
    instrument: str,
    current_payout: Optional[float],
    payout_source: Optional[str],
    is_stale: bool,
    last_payout_event_at: float,
) -> bool:
    """POST /api/ingest-payout.

    last_payout_event_at — monotonic time.time() последнего payout_update/json_data от PO.
    last_payout_at в payload — время этого POST (heartbeat ingest), не событие брокера.
    """
    if not PLATFORM_URL:
        return False
    url = f"{PLATFORM_URL}/api/ingest-payout"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    event_iso = datetime.fromtimestamp(last_payout_event_at, tz=timezone.utc).isoformat()
    payload = {
        "instrument": instrument,
        "current_payout": float(current_payout) if current_payout is not None else None,
        "last_payout_event_at": event_iso,
        "last_payout_at": datetime.now(timezone.utc).isoformat(),
        "payout_source": payout_source,
        "is_stale": bool(is_stale),
    }
    try:
        session = await _ensure_ingest_http_session()
        async with session.post(url, headers=headers, json=payload) as resp:
            if resp.status not in (200, 201, 204):
                body = await resp.text()
                print(f"[INGEST] post_ingest_payout {instrument} HTTP {resp.status} body={body[:300]}")
                return False
            return True
    except Exception as e:
        print(f"[INGEST] post_ingest_payout {instrument}: {e}")
        return False


async def post_ingest_current_candle(
    instrument: str,
    candle: Dict[str, Any],
    ttl_sec: int,
) -> bool:
    """
    Доставить partial S5 на платформу.

    Предпочтительно Redis direct (setex + chart:s5) — обход PHP-FPM.
    HTTP /api/ingest-current-candle остаётся fallback, если Redis недоступен.
    """
    # Нормализуем updated_at здесь, чтобы Redis/HTTP видели один момент ingest.
    candle_out = dict(candle)
    candle_out["updated_at"] = int(time.time())

    if FEED_TIME_DELTA_LOG_ENABLE:
        server_now = int(time.time())
        raw_feed = _to_unix_seconds(candle_out.get("raw_feed_time", 0))
        effective_feed = int(
            candle_out.get("effective_feed_time", candle_out.get("time", 0)) or 0
        )
        raw_delta = raw_feed - server_now if raw_feed > 0 else None
        effective_delta = effective_feed - server_now if effective_feed > 0 else None
        print(
            f"[INGEST][TIME] {instrument}: raw={raw_feed} effective={effective_feed} "
            f"server={server_now} raw_vs_server={raw_delta}s "
            f"effective_vs_server={effective_delta}s correction={FEED_TIME_CORRECTION_SEC}s"
        )

    if INGEST_CURRENT_CANDLE_REDIS_DIRECT:
        if await put_current_candle_redis_direct(instrument, candle_out, ttl_sec):
            return True
        # Redis упал — один раз пробуем HTTP, чтобы график не замирал.

    if not PLATFORM_URL:
        return False
    url = f"{PLATFORM_URL}/api/ingest-current-candle"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    payload = {
        "instrument": instrument,
        "time": int(candle_out.get("time", 0)),
        # raw_feed_time — timestamp от feed до возможных преобразований/бакетизации;
        # effective_feed_time — timestamp, который платформа фактически использует.
        "raw_feed_time": int(candle_out.get("raw_feed_time", candle_out.get("time", 0))),
        "effective_feed_time": int(
            candle_out.get("effective_feed_time", candle_out.get("time", 0))
        ),
        "open": float(candle_out.get("open", 0)),
        "high": float(candle_out.get("high", 0)),
        "low": float(candle_out.get("low", 0)),
        "close": float(candle_out.get("close", 0)),
        "volume": float(candle_out.get("volume", 0)),
        "updated_at": int(candle_out.get("updated_at", time.time())),
        "ttl_sec": int(ttl_sec),
    }
    try:
        session = await _ensure_ingest_http_session()
        async with session.post(url, headers=headers, json=payload) as resp:
            if resp.status not in (200, 201, 204):
                body = await resp.text()
                print(
                    f"[INGEST] post_ingest_current_candle {instrument} "
                    f"HTTP {resp.status} body={body[:300]}"
                )
                return False
            return True
    except Exception as e:
        print(f"[INGEST] post_ingest_current_candle {instrument}: {e}")
        return False


async def post_ingest_proxy_health(
    instrument: str,
    event: str,
    error: Optional[str] = None,
    streak: Optional[int] = None,
) -> bool:
    """POST /api/ingest-proxy-health — факты WebSocket (connected / ошибки) по инструменту."""
    if not PLATFORM_URL:
        return False
    url = f"{PLATFORM_URL}/api/ingest-proxy-health"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    payload: Dict[str, Any] = {
        "instrument": instrument,
        "event": str(event)[:64],
    }
    if error:
        payload["error"] = str(error)[:1000]
    if streak is not None:
        payload["streak"] = int(streak)
    try:
        session = await _ensure_ingest_http_session()
        async with session.post(url, headers=headers, json=payload) as resp:
            if resp.status not in (200, 201, 204):
                body = await resp.text()
                print(
                    f"[INGEST] post_ingest_proxy_health {instrument} HTTP {resp.status} body={body[:300]}",
                    flush=True,
                )
                return False
            return True
    except Exception as e:
        print(f"[INGEST] post_ingest_proxy_health {instrument}: {e}", flush=True)
        return False


async def post_ingest_proxy_failure(
    instrument: str,
    error: str,
    streak: Optional[int] = None,
) -> bool:
    """POST /api/ingest-proxy-failure.

    Передаём в платформу факт проблем с подключением/прокси, чтобы Laravel
    обновил health endpoint и, при включённой опции, выполнил авто-ротацию.
    """
    if not PLATFORM_URL:
        return False
    url = f"{PLATFORM_URL}/api/ingest-proxy-failure"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    payload = {
        "instrument": instrument,
        "error": str(error)[:1000],
        "streak": int(streak) if streak is not None else None,
    }
    try:
        session = await _ensure_ingest_http_session()
        async with session.post(url, headers=headers, json=payload) as resp:
            if resp.status not in (200, 201, 204):
                body = await resp.text()
                print(f"[INGEST] post_ingest_proxy_failure {instrument} HTTP {resp.status} body={body[:300]}")
                return False
            return True
    except Exception as e:
        print(f"[INGEST] post_ingest_proxy_failure {instrument}: {e}")
        return False


def candle_to_dict(candle) -> Dict[str, Any]:
    """Candle model -> dict для ingest."""
    ts = candle.timestamp
    if hasattr(ts, "timestamp"):
        raw_unix = int(ts.timestamp())
    else:
        raw_unix = int(ts)
    effective_unix = _to_effective_candle_time(raw_unix)
    return {
        "time": effective_unix,
        "raw_feed_time": raw_unix,
        "effective_feed_time": effective_unix,
        "open": float(candle.open),
        "high": float(candle.high),
        "low": float(candle.low),
        "close": float(candle.close),
        "volume": float(getattr(candle, "volume", 0) or 0),
    }


async def run_instrument_worker(
    instrument: str,
    display_name: Optional[str],
    session_token: str,
    uid: str,
    is_demo: bool,
    proxy_url: Optional[str],
    stop_event: asyncio.Event,
) -> None:
    """
    True streaming worker: one persistent WebSocket per instrument.
    - On connect: one-time backfill of historical candles
    - Permanent json_data handler captures every tick in real time
    - Completed candles are flushed to DB when minute boundary crosses
    """
    if not instrument:
        return
    if not session_token:
        print(f"[INGEST] skip {instrument}: no session_token")
        return

    ssid = create_ssid(session_token, uid, is_demo)
    def _norm_asset_name(name: Optional[str]) -> str:
        return (name or "").replace("#", "").replace("/", "").replace(" ", "").upper()

    instrument_norm = _norm_asset_name(instrument)

    # Живёт между переподключениями WebSocket: неотправленные свечи не сбрасываются при падении прокси.
    current_candles: Dict[int, Dict[str, Any]] = {}
    connect_fail_streak = 0

    while not stop_event.is_set():
        client = None
        on_json_data = None
        try:
            client = AsyncPocketOptionClient(
                ssid,
                is_demo=is_demo,
                enable_logging=False,
                persistent_connection=False,
                auto_reconnect=True,
                proxy_url=proxy_url,
            )
            last_stream_activity_at = time.time()
            last_fallback_at = 0.0
            last_payout_value: Optional[float] = None
            last_payout_source: Optional[str] = None
            last_payout_event_at = 0.0
            last_payout_post_at = 0.0
            last_payout_posted_value: Optional[float] = None
            last_current_candle_post_at = 0.0
            last_current_candle_posted_ts: Optional[int] = None
            # Не ждём HTTP flush закрытых баров — иначе цикл (и Redis partial) встаёт на секунды.
            flush_in_flight_ts: set = set()
            current_candle_post_pending = False
            # Event loop воркера: tick-handler синхронный, publish планируем через create_task.
            worker_loop = asyncio.get_running_loop()

            def _schedule_current_candle_publish(force: bool = False) -> None:
                """С тика: Redis PUBLISH partial без ожидания flush-цикла (цель debounce 0.25s)."""
                nonlocal last_current_candle_post_at, last_current_candle_posted_ts
                nonlocal current_candle_post_pending
                if not INGEST_CURRENT_CANDLE_ENABLE or not current_candles:
                    return
                now_mono = time.time()
                if (
                    not force
                    and (now_mono - last_current_candle_post_at)
                    < INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC
                ):
                    return
                if current_candle_post_pending:
                    return
                latest_ts = max(current_candles.keys())
                latest_candle = current_candles.get(latest_ts)
                if not latest_candle:
                    return
                # Копия: tick может мутировать dict пока идёт await Redis.
                candle_snapshot = dict(latest_candle)
                current_candle_post_pending = True
                # Резервируем debounce-слот сразу, чтобы тики не плодили task'и.
                last_current_candle_post_at = now_mono
                last_current_candle_posted_ts = latest_ts

                async def _publish() -> None:
                    nonlocal current_candle_post_pending
                    try:
                        await post_ingest_current_candle(
                            instrument=instrument,
                            candle=candle_snapshot,
                            ttl_sec=INGEST_CURRENT_CANDLE_TTL_SEC,
                        )
                    finally:
                        current_candle_post_pending = False

                try:
                    worker_loop.create_task(_publish())
                except RuntimeError:
                    current_candle_post_pending = False

            def _tick_asset_matches(tick_asset: str) -> bool:
                return _norm_asset_name(tick_asset) == instrument_norm

            def on_payout_update(payload: Any) -> None:
                nonlocal last_payout_value, last_payout_source, last_payout_event_at
                try:
                    if not isinstance(payload, dict):
                        return
                    symbol = (
                        payload.get("symbol")
                        or payload.get("asset")
                        or payload.get("name")
                        or ""
                    )
                    if not _tick_asset_matches(str(symbol)):
                        return
                    payout_raw = payload.get("payout")
                    if payout_raw is None:
                        return
                    payout_val = float(payout_raw)
                    if payout_val < 0 or payout_val > 100:
                        return
                    last_payout_value = payout_val
                    last_payout_source = "payout_update"
                    last_payout_event_at = time.time()
                except Exception:
                    pass

            def on_json_data(data: Any) -> None:
                """Permanent handler: captures every tick and candle update."""
                nonlocal last_stream_activity_at, last_payout_value, last_payout_source, last_payout_event_at
                tick_updated = False
                try:
                    # Ticks: list of [['ASSET', timestamp, price], ...]
                    if isinstance(data, list) and len(data) > 0:
                        for tick_data in data:
                            if isinstance(tick_data, list) and len(tick_data) >= 3:
                                tick_asset = str(tick_data[0])
                                if not _tick_asset_matches(tick_asset):
                                    continue
                                last_stream_activity_at = time.time()
                                tick_ts = _to_unix_seconds(tick_data[1])
                                tick_raw_ts = _to_raw_int(tick_data[1])
                                tick_price = float(tick_data[2])
                                candle_ts = _to_effective_candle_time(tick_ts)
                                dv = _tick_volume_delta(tick_data)

                                if candle_ts not in current_candles:
                                    current_candles[candle_ts] = {
                                        "time": candle_ts,
                                        "raw_feed_time": tick_raw_ts,
                                        "effective_feed_time": candle_ts,
                                        "open": tick_price,
                                        "high": tick_price,
                                        "low": tick_price,
                                        "close": tick_price,
                                        "volume": float(dv),
                                        "tick_count": 1,
                                        "ohlc_source": "ticks",
                                    }
                                else:
                                    c = current_candles[candle_ts]
                                    c["high"] = max(c["high"], tick_price)
                                    c["low"] = min(c["low"], tick_price)
                                    c["close"] = tick_price
                                    c["raw_feed_time"] = tick_raw_ts
                                    c["effective_feed_time"] = candle_ts
                                    c["tick_count"] = c.get("tick_count", 0) + 1
                                    c["volume"] = float(c.get("volume", 0)) + float(dv)
                                    c["ohlc_source"] = "ticks"
                                tick_updated = True

                    # Server candle data (changeSymbol response / history)
                    if isinstance(data, dict) and "asset" in data:
                        asset = data.get("asset", "")
                        if not _tick_asset_matches(asset):
                            return
                        last_stream_activity_at = time.time()
                        if "payout" in data:
                            try:
                                payout_val = float(data.get("payout"))
                                if 0 <= payout_val <= 100:
                                    last_payout_value = payout_val
                                    last_payout_source = "json_data"
                                    last_payout_event_at = time.time()
                            except (TypeError, ValueError):
                                pass
                        period_in = data.get("period")
                        if period_in is not None and int(period_in) != BASE_BAR_PERIOD_SEC:
                            return
                        if "candles" in data:
                            for cd in data.get("candles", []):
                                if isinstance(cd, (list, tuple)) and len(cd) >= 5:
                                    raw_ts_sec = _to_unix_seconds(cd[0])
                                    ts = _to_effective_candle_time(raw_ts_sec)
                                    raw_ts = _to_raw_int(cd[0])
                                    vol = _volume_from_candle_array(cd)
                                    ex = current_candles.get(ts)
                                    if ex and ex.get("tick_count", 0) > 0:
                                        if vol > float(ex.get("volume", 0)):
                                            ex["volume"] = vol
                                        ex["raw_feed_time"] = raw_ts
                                        ex["effective_feed_time"] = ts
                                        continue
                                    current_candles[ts] = {
                                        "time": ts,
                                        "raw_feed_time": raw_ts,
                                        "effective_feed_time": ts,
                                        "open": float(cd[1]),
                                        "close": float(cd[2]),
                                        "high": float(cd[3]),
                                        "low": float(cd[4]),
                                        "volume": vol,
                                        "tick_count": 0,
                                        "ohlc_source": "ws_candles",
                                    }
                                    tick_updated = True
                        if "history" in data:
                            for tick in data.get("history", []):
                                if isinstance(tick, (list, tuple)) and len(tick) >= 2:
                                    ts = _to_unix_seconds(tick[0])
                                    raw_ts = _to_raw_int(tick[0])
                                    candle_ts = _to_effective_candle_time(ts)
                                    val = float(tick[1])
                                    dv = _tick_volume_delta(tick)
                                    if candle_ts not in current_candles:
                                        current_candles[candle_ts] = {
                                            "time": candle_ts,
                                            "raw_feed_time": raw_ts,
                                            "effective_feed_time": candle_ts,
                                            "open": val,
                                            "high": val,
                                            "low": val,
                                            "close": val,
                                            "volume": float(dv),
                                            "tick_count": 1,
                                            "ohlc_source": "ticks",
                                        }
                                    else:
                                        c = current_candles[candle_ts]
                                        c["high"] = max(c.get("high", val), val)
                                        c["low"] = min(c.get("low", val), val)
                                        c["close"] = val
                                        c["raw_feed_time"] = raw_ts
                                        c["effective_feed_time"] = candle_ts
                                        c["tick_count"] = c.get("tick_count", 0) + 1
                                        c["volume"] = float(c.get("volume", 0)) + float(dv)
                                        c["ohlc_source"] = "ticks"
                                    tick_updated = True
                except Exception:
                    pass
                # Тик → Redis partial сразу (debounce), не ждём sleep/flush HTTP в цикле.
                if tick_updated:
                    _schedule_current_candle_publish(force=False)

            client._websocket.add_event_handler("json_data", on_json_data)
            client._websocket.add_event_handler("stream_update", on_json_data)
            client._websocket.add_event_handler("history_update", on_json_data)
            client._websocket.add_event_handler("payout_update", on_payout_update)

            # wait_for+shield: иначе connect через мёртвый SOCKS зависает, а при timeout
            # обычный wait_for снова cancel'ит connect и ждёт его вечно.
            connect_timed_out = False
            connect_task = asyncio.create_task(client.connect())
            try:
                ok = bool(
                    await asyncio.wait_for(
                        asyncio.shield(connect_task),
                        timeout=max(1.0, WORKER_CONNECT_TIMEOUT_SEC),
                    )
                )
            except asyncio.TimeoutError:
                ok = False
                connect_timed_out = True
                connect_task.cancel()
                print(
                    f"[INGEST] {instrument}: connect timed out after "
                    f"{WORKER_CONNECT_TIMEOUT_SEC:.0f}s",
                    flush=True,
                )
            if not ok:
                connect_fail_streak += 1
                delay = min(
                    CONNECT_FAIL_MAX_SLEEP_SEC,
                    5 * (2 ** min(connect_fail_streak - 1, 4)),
                )
                # Деталь из WebSocket-клиента (регион, последняя ошибка) — иначе только «connect_failed».
                connect_detail = "connect_failed"
                if connect_timed_out:
                    connect_detail = (
                        f"connect_failed timeout={int(WORKER_CONNECT_TIMEOUT_SEC)}s"
                    )
                try:
                    ws = getattr(client, "_websocket", None)
                    info = getattr(ws, "connection_info", None) if ws else None
                    last_err = getattr(ws, "_last_error", None) if ws else None
                    if info is not None:
                        region = getattr(info, "region", None) or getattr(info, "url", None)
                        if region:
                            connect_detail = f"{connect_detail} region={region}"
                    if last_err:
                        connect_detail = f"{connect_detail}: {last_err}"
                except Exception:
                    pass
                await post_ingest_proxy_health(
                    instrument=instrument,
                    event="connect_failed",
                    error=connect_detail,
                    streak=connect_fail_streak,
                )
                await post_ingest_proxy_failure(
                    instrument=instrument,
                    error=connect_detail,
                    streak=connect_fail_streak,
                )
                # После серии фейлов выходим: Laravel уже мог ротировать proxy_endpoint_id,
                # а этот воркер всё ещё держит старый proxy_url до cancel/reload.
                if connect_fail_streak >= CONNECT_FAIL_EXIT_STREAK:
                    print(
                        f"[INGEST] {instrument}: {connect_detail} (streak={connect_fail_streak}), "
                        f"exiting worker to reload proxy from platform",
                        flush=True,
                    )
                    return
                print(
                    f"[INGEST] {instrument}: {connect_detail} (streak={connect_fail_streak}), "
                    f"sleep {delay}s",
                    flush=True,
                )
                await asyncio.sleep(delay)
                continue
            connect_fail_streak = 0
            await asyncio.sleep(3)

            if not client.is_connected:
                connect_fail_streak += 1
                delay = min(
                    CONNECT_FAIL_MAX_SLEEP_SEC,
                    5 * (2 ** min(connect_fail_streak - 1, 4)),
                )
                await post_ingest_proxy_health(
                    instrument=instrument,
                    event="not_connected_after_wait",
                    error="not_connected_after_wait",
                    streak=connect_fail_streak,
                )
                await post_ingest_proxy_failure(
                    instrument=instrument,
                    error="not_connected_after_wait",
                    streak=connect_fail_streak,
                )
                if connect_fail_streak >= CONNECT_FAIL_EXIT_STREAK:
                    print(
                        f"[INGEST] {instrument}: not connected after wait "
                        f"(streak={connect_fail_streak}), exiting worker to reload proxy",
                        flush=True,
                    )
                    return
                print(
                    f"[INGEST] {instrument}: not connected after wait, sleep {delay}s",
                    flush=True,
                )
                await asyncio.sleep(delay)
                continue
            connect_fail_streak = 0
            await post_ingest_proxy_health(instrument=instrument, event="connected")

            proxy_info = f" via proxy" if proxy_url else ""
            print(f"[INGEST] {instrument}: connected{proxy_info}, streaming ticks...", flush=True)

            # --- One-time backfill on connect ---
            if INGEST_USE_API_CANDLES:
                print(f"[INGEST] {instrument}: starting backfill...", flush=True)
                try:
                    candles = await asyncio.wait_for(
                        client._request_candles(
                            instrument, BASE_BAR_PERIOD_SEC, CANDLES_REQUEST_COUNT, None
                        ),
                        timeout=15.0,
                    )
                    print(f"[INGEST] {instrument}: _request_candles returned {len(candles) if candles else 0} candles", flush=True)
                    if candles:
                        backfill = [candle_to_dict(c) for c in candles]
                        ok = await post_ingest_candles(instrument, backfill)
                        if ok:
                            print(f"[INGEST] {instrument}: backfill {len(backfill)} historical candles OK", flush=True)
                        else:
                            print(f"[INGEST] {instrument}: backfill post failed", flush=True)
                except asyncio.TimeoutError:
                    print(f"[INGEST] {instrument}: backfill timeout (continuing with stream)", flush=True)
                except Exception as e:
                    import traceback
                    print(f"[INGEST] {instrument}: backfill error: {e}", flush=True)
                    traceback.print_exc()
            else:
                print(
                    f"[INGEST] {instrument}: API candle backfill disabled (INGEST_USE_API_CANDLES=0), "
                    "using live ticks only",
                    flush=True,
                )

            # --- Main streaming loop ---
            print(f"[INGEST] {instrument}: entering streaming loop (flush every {FLUSH_CHECK_INTERVAL}s)", flush=True)

            while not stop_event.is_set():
                if not client.is_connected:
                    print(
                        f"[INGEST] {instrument}: connection lost, reconnecting... "
                        f"(keeping {len(current_candles)} in-memory candle bucket(s))",
                        flush=True,
                    )
                    await post_ingest_proxy_health(
                        instrument=instrument,
                        event="connection_lost",
                        error="connection_lost",
                    )
                    break

                await asyncio.sleep(FLUSH_CHECK_INTERVAL)

                now_ts = int(datetime.now(timezone.utc).timestamp())
                current_bucket_start = (now_ts // BASE_BAR_PERIOD_SEC) * BASE_BAR_PERIOD_SEC

                # Stream-first fallback: только при "тишине" в потоке.
                if INGEST_USE_API_CANDLES:
                    now_mono = time.time()
                    stream_idle_for = now_mono - last_stream_activity_at
                    since_last_fallback = now_mono - last_fallback_at
                else:
                    stream_idle_for = 0.0
                    since_last_fallback = 0.0

                if (
                    INGEST_USE_API_CANDLES
                    and stream_idle_for >= INGEST_FALLBACK_IDLE_SEC
                    and since_last_fallback >= INGEST_FALLBACK_MIN_INTERVAL_SEC
                ):
                    try:
                        fresh = await asyncio.wait_for(
                            client._request_candles(instrument, BASE_BAR_PERIOD_SEC, 5, None),
                            timeout=10.0,
                        )
                        if fresh:
                            print(f"[INGEST] {instrument}: fallback fetch got {len(fresh)} candles", flush=True)
                            for fc in fresh:
                                cd = candle_to_dict(fc)
                                ts = cd["time"]
                                ex = current_candles.get(ts)
                                if ex and ex.get("tick_count", 0) > 0:
                                    fv = float(cd.get("volume", 0))
                                    if fv > float(ex.get("volume", 0)):
                                        ex["volume"] = fv
                                    ex["raw_feed_time"] = int(cd.get("raw_feed_time", ts))
                                    ex["effective_feed_time"] = int(cd.get("effective_feed_time", ts))
                                    continue
                                current_candles[ts] = {
                                    "time": ts,
                                    "raw_feed_time": int(cd.get("raw_feed_time", ts)),
                                    "effective_feed_time": int(cd.get("effective_feed_time", ts)),
                                    "open": cd["open"],
                                    "high": cd["high"],
                                    "low": cd["low"],
                                    "close": cd["close"],
                                    "volume": float(cd.get("volume", 0)),
                                    "tick_count": 0,
                                    "ohlc_source": "fallback",
                                }
                        else:
                            print(f"[INGEST] {instrument}: fallback fetch returned empty list/None", flush=True)
                    except asyncio.TimeoutError:
                        print(f"[INGEST] {instrument}: fallback fetch timed out", flush=True)
                    except Exception as e:
                        print(f"[INGEST] {instrument}: fallback fetch error: {e}", flush=True)
                    finally:
                        last_fallback_at = time.time()

                # Payout ingest: без активного запроса request_payment_update, только по входящим событиям.
                if INGEST_PAYOUT_ENABLE and last_payout_event_at > 0:
                    now_mono = time.time()
                    payout_is_stale = (now_mono - last_payout_event_at) > INGEST_PAYOUT_TTL_SEC
                    payout_changed = last_payout_posted_value != last_payout_value
                    debounce_ok = (now_mono - last_payout_post_at) >= INGEST_PAYOUT_POST_DEBOUNCE_SEC
                    if payout_changed or debounce_ok:
                        ok = await post_ingest_payout(
                            instrument=instrument,
                            current_payout=last_payout_value,
                            payout_source=last_payout_source,
                            is_stale=payout_is_stale,
                            last_payout_event_at=last_payout_event_at,
                        )
                        if ok:
                            last_payout_post_at = now_mono
                            last_payout_posted_value = last_payout_value
                            print(
                                f"[INGEST] {instrument}: payout={last_payout_value} source={last_payout_source} stale={payout_is_stale}",
                                flush=True,
                            )

                if INGEST_CURRENT_CANDLE_ENABLE and current_candles:
                    # Backup, если тиков давно нет (тишина), но partial ещё в памяти.
                    _schedule_current_candle_publish(force=False)

                # Flush all completed candles:
                # публикуем все бары, которые уже точно закончились по системному времени.
                # Это снимает лишнюю задержку, когда flush ждал первый тик следующего бакета.
                completed_payload: List[Dict[str, Any]] = []
                completed_meta: List[Dict[str, Any]] = []
                for candle_ts in sorted(current_candles.keys()):
                    # Текущий бакет (>= current_bucket_start) остаётся partial и не flush-ится.
                    if candle_ts >= current_bucket_start:
                        continue
                    if candle_ts in flush_in_flight_ts:
                        continue
                    c = current_candles[candle_ts]
                    completed_payload.append(
                        {
                            "time": c["time"],
                            "open": float(c["open"]),
                            "high": float(c["high"]),
                            "low": float(c["low"]),
                            "close": float(c["close"]),
                            "volume": float(c.get("volume", 0)),
                        }
                    )
                    completed_meta.append(
                        {
                            "time": c["time"],
                            "tick_count": int(c.get("tick_count", 0)),
                            "ohlc_source": str(
                                c.get("ohlc_source", "unknown")
                            ),
                            "volume": float(c.get("volume", 0)),
                        }
                    )

                if completed_payload:
                    # HTTP flush в фоне: иначе await на Laravel блокирует Redis-partial на секунды.
                    payload_copy = list(completed_payload)
                    meta_copy = list(completed_meta)
                    times_to_pop = [int(row["time"]) for row in payload_copy]
                    for ts in times_to_pop:
                        flush_in_flight_ts.add(ts)

                    async def _flush_completed(
                        rows: List[Dict[str, Any]] = payload_copy,
                        meta: List[Dict[str, Any]] = meta_copy,
                        times: List[int] = times_to_pop,
                    ) -> None:
                        try:
                            ok = await post_ingest_candles(instrument, rows)
                            if ok:
                                ticks_total = sum(m["tick_count"] for m in meta)
                                print(
                                    f"[INGEST] {instrument}: flushed {len(rows)} candle(s), "
                                    f"total_ticks_in_those_bars={ticks_total}",
                                    flush=True,
                                )
                                if INGEST_FLUSH_DETAIL:
                                    for m in meta:
                                        print(
                                            f"[INGEST] {instrument}: flush_detail ts={m['time']} "
                                            f"ticks_in_bar={m['tick_count']} "
                                            f"ohlc_source={m['ohlc_source']} volume={m['volume']}",
                                            flush=True,
                                        )
                                for t in times:
                                    current_candles.pop(t, None)
                            else:
                                print(
                                    f"[INGEST] {instrument}: post failed, keeping {len(rows)} "
                                    f"candle(s) in memory for retry",
                                    flush=True,
                                )
                        finally:
                            for t in times:
                                flush_in_flight_ts.discard(t)

                    worker_loop.create_task(_flush_completed())

                # Опционально: выкинуть только давно завершённые бары (не трогаем «хвост» при POST-ошибках).
                if INGEST_PRUNE_COMPLETED_OLDER_THAN_SEC > 0:
                    threshold = current_bucket_start - INGEST_PRUNE_COMPLETED_OLDER_THAN_SEC
                    for ts in list(current_candles.keys()):
                        if ts < current_bucket_start and ts < threshold and ts not in flush_in_flight_ts:
                            print(
                                f"[INGEST] {instrument}: prune stale completed candle ts={ts} "
                                f"(older than {INGEST_PRUNE_COMPLETED_OLDER_THAN_SEC}s)",
                                flush=True,
                            )
                            current_candles.pop(ts, None)

        except asyncio.CancelledError:
            break
        except Exception as e:
            print(f"[INGEST] {instrument} error: {e}", flush=True)
            connect_fail_streak += 1
            await post_ingest_proxy_health(
                instrument=instrument,
                event="worker_exception",
                error=f"worker_exception: {e}",
                streak=connect_fail_streak,
            )
            await post_ingest_proxy_failure(
                instrument=instrument,
                error=f"worker_exception: {e}",
                streak=connect_fail_streak,
            )
            delay = min(300, 5 * (2 ** min(connect_fail_streak - 1, 6)))
            await asyncio.sleep(delay)
        finally:
            if client and on_json_data:
                try:
                    client._websocket.remove_event_handler("json_data", on_json_data)
                except Exception:
                    pass
                try:
                    client._websocket.remove_event_handler("stream_update", on_json_data)
                except Exception:
                    pass
                try:
                    client._websocket.remove_event_handler("history_update", on_json_data)
                except Exception:
                    pass
                try:
                    client._websocket.remove_event_handler("payout_update", on_payout_update)
                except Exception:
                    pass
            if client:
                # Таймаут обязателен: websocket.close() на мёртвом прокси может не вернуться,
                # и тогда task.cancel()+await в супервизоре тоже зависает навсегда.
                await _disconnect_client_safely(client, instrument)


# Global cache accessible to pocket-options-proxy.py
instruments_cache: List[Dict[str, Any]] = []

async def run_ingest_forever() -> None:
    """
    Background loop: periodically fetches instrument list from platform,
    maintains one persistent streaming connection per instrument.
    """
    if not PLATFORM_URL:
        print("[INGEST] PLATFORM_URL required. Ingest disabled.")
        return

    stop = asyncio.Event()
    workers: Dict[str, asyncio.Task] = {}
    # Храним "конфигурационный отпечаток" каждого активного воркера.
    # Это нужно, чтобы reliably детектировать runtime-изменения конфигурации инструмента
    # (proxy_url/session_token/uid/is_demo) и перезапускать конкретный воркер.
    # Без этого процесс продолжает использовать старый proxy_url до случайного reconnect.
    worker_specs: Dict[str, tuple[str, str, bool, Optional[str]]] = {}
    global instruments_cache

    startup_logged = False
    last_pool_version = 0
    last_instruments_fetch_at = 0.0

    try:
        while True:
            need_fetch = False

            # Сначала снимаем завершённых воркеров — их нужно пересоздать с актуальным proxy_url.
            # Важно сделать это ДО сборки to_run, иначе respawn возьмёт устаревший cache.
            finished_keys = [key for key, task in workers.items() if task.done()]
            if finished_keys:
                for key in finished_keys:
                    task = workers.pop(key)
                    worker_specs.pop(key, None)
                    try:
                        exc = task.exception()
                    except (asyncio.CancelledError, asyncio.InvalidStateError):
                        exc = None
                    if exc is not None:
                        print(
                            f"[INGEST] worker for {key} finished with error: {exc!r}",
                            flush=True,
                        )
                    else:
                        print(
                            f"[INGEST] worker for {key} exited — will respawn with fresh proxy",
                            flush=True,
                        )
                # Форсируем refresh: Laravel мог уже ротировать endpoint после connect_failed.
                need_fetch = True

            current_pool_version = await fetch_proxy_pool_version()
            pool_version_changed = (
                current_pool_version > 0
                and last_pool_version > 0
                and current_pool_version != last_pool_version
            )
            if current_pool_version > 0:
                last_pool_version = current_pool_version

            need_fetch = need_fetch or (
                not instruments_cache
                or pool_version_changed
                or (time.time() - last_instruments_fetch_at) >= INSTRUMENTS_REFRESH_SEC
            )

            fresh = None
            if need_fetch:
                if pool_version_changed:
                    print(
                        f"[INGEST] proxy pool version changed -> {current_pool_version}; "
                        "forcing instrument refresh",
                        flush=True,
                    )
                fresh = await fetch_instruments()
                last_instruments_fetch_at = time.time()

            if fresh:
                instruments_cache = fresh
                instruments_cache.sort(
                    key=lambda x: (-(x.get("priority") or 0), x.get("instrument", ""))
                )
                print(f"[INGEST] fetched {len(instruments_cache)} instruments from {PLATFORM_URL}", flush=True)
            else:
                if not instruments_cache:
                    print(f"[INGEST] no instruments (check platform at {PLATFORM_URL} and active instruments in DB)", flush=True)

            if not startup_logged:
                startup_logged = True
                if fresh:
                    print(
                        f"[INGEST] startup: API returned {len(fresh)} instrument(s); workers will use WebSocket ingest",
                        flush=True,
                    )
                else:
                    print(
                        "[INGEST] startup: API returned 0 instruments — включите is_active и session_token в админке, "
                        "или проверьте PLATFORM_URL (должен открываться с хоста прокси, напр. http://127.0.0.1:8080)",
                        flush=True,
                    )

            to_run = instruments_cache[:MAX_CONNECTIONS]
            current_keys = {item.get("instrument") for item in to_run if item.get("instrument")}

            for key in list(workers.keys()):
                if key not in current_keys:
                    # Инструмент удалён из выдачи API: корректно останавливаем его воркер,
                    # чтобы не держать лишние WebSocket/ресурсы.
                    task = workers.pop(key)
                    worker_specs.pop(key, None)
                    task.cancel()
                    await _await_cancelled_worker(task, key)

            for item in to_run:
                instrument = item.get("instrument") or item.get("symbol")
                if not instrument:
                    continue
                session_token = item.get("session_token")
                uid = str(item.get("uid") or "0")
                is_demo = item.get("is_demo", True)
                if not session_token:
                    print(f"[INGEST] skip {instrument}: no session_token in DB (pocket_instruments)")
                    continue
                proxy_url_raw = item.get("proxy_url")
                # Нормализуем пустые строки к None, чтобы сравнение spec было стабильным
                # и не давало ложные перезапуски на '' vs None.
                proxy_url = str(proxy_url_raw).strip() if proxy_url_raw else None
                spec = (str(session_token), uid, bool(is_demo), proxy_url)

                if instrument in workers:
                    prev_spec = worker_specs.get(instrument)
                    if prev_spec == spec:
                        # Спек не изменился: воркер уже работает на актуальной конфигурации.
                        continue

                    # Критично: при смене proxy_url/session_token/uid/is_demo принудительно
                    # перезапускаем воркер, иначе он останется на старом WebSocket-клиенте
                    # и будет фактически игнорировать новую прокси-конфигурацию.
                    # pop до await: даже при timeout orphan не блокирует старт нового воркера.
                    old_task = workers.pop(instrument)
                    worker_specs.pop(instrument, None)
                    old_task.cancel()
                    await _await_cancelled_worker(old_task, instrument)
                    print(
                        f"[INGEST] restarted worker for {instrument}: detected config change "
                        f"(proxy/session/uid/is_demo)",
                        flush=True,
                    )

                task = asyncio.create_task(
                    run_instrument_worker(
                        instrument=instrument,
                        display_name=item.get("display_name"),
                        session_token=session_token,
                        uid=uid,
                        is_demo=is_demo,
                        proxy_url=proxy_url,
                        stop_event=stop,
                    )
                )
                workers[instrument] = task
                worker_specs[instrument] = spec
                print(f"[INGEST] started worker for {instrument}")
                await asyncio.sleep(1)

            # Короткий сон: чаще проверяем pool-version (после «Обновить прокси» в UI).
            await asyncio.sleep(PROXY_POOL_VERSION_POLL_SEC)
    except asyncio.CancelledError:
        stop.set()
        pending = list(workers.items())
        workers.clear()
        worker_specs.clear()
        for key, t in pending:
            t.cancel()
        # Параллельно ждём всех с общим per-task лимитом — shutdown не должен висеть минутами.
        if pending:
            await asyncio.gather(
                *(
                    _await_cancelled_worker(t, key)
                    for key, t in pending
                ),
                return_exceptions=True,
            )
    finally:
        await close_ingest_http_session()


async def run_ingest_background() -> None:
    """Entry point: run in background (non-blocking)."""
    asyncio.create_task(run_ingest_forever())
