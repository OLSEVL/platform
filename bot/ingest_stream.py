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
M1_PERIOD = 60  # период минутной свечи в секундах
CANDLES_REQUEST_COUNT = 100  # количество свечей для начального backfill
# Интервал главного цикла (flush завершённых минут, POST текущей свечи, payout).
# Раньше было 5s: при этом даже при debounce=1s текущая свеча на платформу уходила не чаще раз в 5s —
# отсюда ощущаемое отставание ~5–10s от графика Pocket Options.
FLUSH_CHECK_INTERVAL = int(os.environ.get("INGEST_LOOP_INTERVAL_SEC", "1"))
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
    """Возвращает effective время свечи, выровненное по минутному бакету."""
    corrected = _apply_feed_time_correction(unix_sec)
    return max(1, (corrected // M1_PERIOD) * M1_PERIOD)


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


def create_ssid(session_token: str, uid: str, is_demo: bool = True) -> str:
    uid_int = int(uid) if uid else 0
    demo = 1 if is_demo else 0
    return f'42["auth",{{"session":"{session_token}","uid":{uid_int},"isDemo":{demo},"platform":2,"isFastHistory":true,"isOptimized":true}}]'


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
        "timeframe": 1,
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
) -> bool:
    """POST /api/ingest-payout."""
    if not PLATFORM_URL:
        return False
    url = f"{PLATFORM_URL}/api/ingest-payout"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    payload = {
        "instrument": instrument,
        "current_payout": float(current_payout) if current_payout is not None else None,
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
    """POST /api/ingest-current-candle."""
    if not PLATFORM_URL:
        return False
    url = f"{PLATFORM_URL}/api/ingest-current-candle"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    payload = {
        "instrument": instrument,
        "time": int(candle.get("time", 0)),
        # raw_feed_time — timestamp от feed до возможных преобразований/бакетизации;
        # effective_feed_time — timestamp, который платформа фактически использует.
        "raw_feed_time": int(candle.get("raw_feed_time", candle.get("time", 0))),
        "effective_feed_time": int(candle.get("effective_feed_time", candle.get("time", 0))),
        "open": float(candle.get("open", 0)),
        "high": float(candle.get("high", 0)),
        "low": float(candle.get("low", 0)),
        "close": float(candle.get("close", 0)),
        "volume": float(candle.get("volume", 0)),
        "updated_at": int(time.time()),
        "ttl_sec": int(ttl_sec),
    }
    if FEED_TIME_DELTA_LOG_ENABLE:
        server_now = int(time.time())
        raw_feed = _to_unix_seconds(candle.get("raw_feed_time", 0))
        effective_feed = int(candle.get("effective_feed_time", candle.get("time", 0)) or 0)
        raw_delta = raw_feed - server_now if raw_feed > 0 else None
        effective_delta = effective_feed - server_now if effective_feed > 0 else None
        print(
            f"[INGEST][TIME] {instrument}: raw={raw_feed} effective={effective_feed} "
            f"server={server_now} raw_vs_server={raw_delta}s "
            f"effective_vs_server={effective_delta}s correction={FEED_TIME_CORRECTION_SEC}s"
        )
    try:
        session = await _ensure_ingest_http_session()
        async with session.post(url, headers=headers, json=payload) as resp:
            if resp.status not in (200, 201, 204):
                body = await resp.text()
                print(f"[INGEST] post_ingest_current_candle {instrument} HTTP {resp.status} body={body[:300]}")
                return False
            return True
    except Exception as e:
        print(f"[INGEST] post_ingest_current_candle {instrument}: {e}")
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
                        if period_in is not None and int(period_in) != M1_PERIOD:
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
                except Exception:
                    pass

            client._websocket.add_event_handler("json_data", on_json_data)
            client._websocket.add_event_handler("stream_update", on_json_data)
            client._websocket.add_event_handler("history_update", on_json_data)
            client._websocket.add_event_handler("payout_update", on_payout_update)

            ok = await client.connect()
            if not ok:
                connect_fail_streak += 1
                delay = min(300, 5 * (2 ** min(connect_fail_streak - 1, 6)))
                await post_ingest_proxy_failure(
                    instrument=instrument,
                    error="connect_failed",
                    streak=connect_fail_streak,
                )
                print(
                    f"[INGEST] {instrument}: connect failed (streak={connect_fail_streak}), "
                    f"sleep {delay}s",
                    flush=True,
                )
                await asyncio.sleep(delay)
                continue
            connect_fail_streak = 0
            await asyncio.sleep(3)

            if not client.is_connected:
                connect_fail_streak += 1
                delay = min(300, 5 * (2 ** min(connect_fail_streak - 1, 6)))
                await post_ingest_proxy_failure(
                    instrument=instrument,
                    error="not_connected_after_wait",
                    streak=connect_fail_streak,
                )
                print(
                    f"[INGEST] {instrument}: not connected after wait, sleep {delay}s",
                    flush=True,
                )
                await asyncio.sleep(delay)
                continue
            connect_fail_streak = 0

            proxy_info = f" via proxy" if proxy_url else ""
            print(f"[INGEST] {instrument}: connected{proxy_info}, streaming ticks...", flush=True)

            # --- One-time backfill on connect ---
            if INGEST_USE_API_CANDLES:
                print(f"[INGEST] {instrument}: starting backfill...", flush=True)
                try:
                    candles = await asyncio.wait_for(
                        client._request_candles(
                            instrument, M1_PERIOD, CANDLES_REQUEST_COUNT, None
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
                    break

                await asyncio.sleep(FLUSH_CHECK_INTERVAL)

                now_ts = int(datetime.now(timezone.utc).timestamp())
                current_minute = (now_ts // M1_PERIOD) * M1_PERIOD

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
                            client._request_candles(instrument, M1_PERIOD, 5, None),
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
                        )
                        if ok:
                            last_payout_post_at = now_mono
                            last_payout_posted_value = last_payout_value
                            print(
                                f"[INGEST] {instrument}: payout={last_payout_value} source={last_payout_source} stale={payout_is_stale}",
                                flush=True,
                            )

                if INGEST_CURRENT_CANDLE_ENABLE and current_candles:
                    now_mono = time.time()
                    debounce_ok = (now_mono - last_current_candle_post_at) >= INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC
                    latest_ts = max(current_candles.keys())
                    candle_changed = latest_ts != last_current_candle_posted_ts
                    if debounce_ok or candle_changed:
                        latest_candle = current_candles.get(latest_ts)
                        if latest_candle:
                            ok = await post_ingest_current_candle(
                                instrument=instrument,
                                candle=latest_candle,
                                ttl_sec=INGEST_CURRENT_CANDLE_TTL_SEC,
                            )
                            if ok:
                                last_current_candle_post_at = now_mono
                                last_current_candle_posted_ts = latest_ts

                # Flush all completed candles (timestamp < current minute)
                completed_payload: List[Dict[str, Any]] = []
                completed_meta: List[Dict[str, Any]] = []
                # Не привязываемся к локальному времени: считаем "завершёнными"
                # все минуты, которые старше самой свежей минуты из текущего буфера.
                latest_ts = max(current_candles.keys()) if current_candles else None
                if latest_ts is not None:
                    for candle_ts in sorted(current_candles.keys()):
                        if candle_ts >= latest_ts:
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
                    ok = await post_ingest_candles(instrument, completed_payload)
                    if ok:
                        ticks_total = sum(m["tick_count"] for m in completed_meta)
                        print(
                            f"[INGEST] {instrument}: flushed {len(completed_payload)} candle(s), "
                            f"total_ticks_in_those_minutes={ticks_total}",
                            flush=True,
                        )
                        if INGEST_FLUSH_DETAIL:
                            for m in completed_meta:
                                print(
                                    f"[INGEST] {instrument}: flush_detail ts={m['time']} "
                                    f"ticks_in_minute={m['tick_count']} ohlc_source={m['ohlc_source']} "
                                    f"volume={m['volume']}",
                                    flush=True,
                                )
                    else:
                        print(
                            f"[INGEST] {instrument}: post failed, keeping {len(completed_payload)} "
                            f"candle(s) in memory for retry",
                            flush=True,
                        )
                    if ok:
                        for row in completed_payload:
                            current_candles.pop(row["time"], None)

                # Опционально: выкинуть только давно завершённые минуты (не трогаем «хвост» при POST-ошибках).
                if INGEST_PRUNE_COMPLETED_OLDER_THAN_SEC > 0:
                    threshold = current_minute - INGEST_PRUNE_COMPLETED_OLDER_THAN_SEC
                    for ts in list(current_candles.keys()):
                        if ts < current_minute and ts < threshold:
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
                try:
                    await client.disconnect()
                except Exception:
                    pass


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
    global instruments_cache

    startup_logged = False

    try:
        while True:
            fresh = await fetch_instruments()
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
                    workers[key].cancel()
                    try:
                        await workers[key]
                    except asyncio.CancelledError:
                        pass
                    del workers[key]

            for item in to_run:
                instrument = item.get("instrument") or item.get("symbol")
                if not instrument or instrument in workers:
                    continue
                session_token = item.get("session_token")
                uid = str(item.get("uid") or "0")
                is_demo = item.get("is_demo", True)
                if not session_token:
                    print(f"[INGEST] skip {instrument}: no session_token in DB (pocket_instruments)")
                    continue
                proxy_url = item.get("proxy_url")

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
                print(f"[INGEST] started worker for {instrument}")
                await asyncio.sleep(1)

            await asyncio.sleep(INSTRUMENTS_REFRESH_SEC)
    except asyncio.CancelledError:
        stop.set()
        for t in workers.values():
            t.cancel()
        for t in workers.values():
            try:
                await t
            except asyncio.CancelledError:
                pass
    finally:
        await close_ingest_http_session()


async def run_ingest_background() -> None:
    """Entry point: run in background (non-blocking)."""
    asyncio.create_task(run_ingest_forever())
