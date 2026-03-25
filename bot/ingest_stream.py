# -*- coding: utf-8 -*-
"""
По плану PLAN_RELEASE: прокси держит постоянные WebSocket по одному на актив.
Загружает список инструментов из платформы (GET /api/proxy/instruments),
для каждого создаёт одно постоянное соединение к Pocket Option, подписка на M1,
полученные свечи отправляет на POST /api/ingest-candles.

Запуск: задаются переменные окружения, затем этот модуль стартует как фоновая задача
из pocket-options-proxy.py (lifespan) или отдельно: asyncio.run(run_ingest_forever()).
"""

import os
import asyncio
from datetime import datetime, timezone
from typing import Optional, List, Dict, Any

# Путь к PocketOptionAPI (как в pocket-options-proxy.py)
import sys
_bot_dir = os.path.dirname(os.path.abspath(__file__))
_pocket_path = os.path.join(_bot_dir, "python", "PocketOptionAPI-main")
if _pocket_path not in sys.path:
    sys.path.insert(0, _pocket_path)

import aiohttp
from pocketoptionapi_async import AsyncPocketOptionClient

# Конфиг из env (прокси и платформа на одной машине — токен не используется)
PLATFORM_URL = os.environ.get("PLATFORM_URL", "").rstrip("/")
MAX_CONNECTIONS = int(os.environ.get("INGEST_MAX_CONNECTIONS", "100"))
INSTRUMENTS_REFRESH_SEC = int(os.environ.get("INSTRUMENTS_REFRESH_SEC", "300"))
M1_POLL_SEC = 60  # запрос свечей M1 раз в минуту по каждому соединению
M1_PERIOD = 60  # период минутной свечи в секундах
WAIT_FOR_STREAM_SEC = 2  # ожидание тиков из stream для формирования последних свечей
CANDLES_REQUEST_COUNT = 100  # количество закрытых свечей в запросе к API


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
        async with aiohttp.ClientSession() as session:
            async with session.get(url, headers=headers, timeout=aiohttp.ClientTimeout(total=30)) as resp:
                if resp.status != 200:
                    body = await resp.text()
                    print(f"[INGEST] fetch_instruments HTTP {resp.status} url={url} body={body[:200]}")
                    return []
                return await resp.json()
    except Exception as e:
        print(f"[INGEST] fetch_instruments error url={url}: {e}")
        return []


async def post_ingest_candles(instrument: str, candles: List[Dict[str, Any]]) -> bool:
    """POST /api/ingest-candles. candles: [{ time, open, high, low, close, volume? }]."""
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
        async with aiohttp.ClientSession() as session:
            async with session.post(url, headers=headers, json=payload, timeout=aiohttp.ClientTimeout(total=15)) as resp:
                if resp.status != 200:
                    body = await resp.text()
                    print(f"[INGEST] post_ingest_candles {instrument} HTTP {resp.status} body={body[:300]}")
                    return False
                return True
    except Exception as e:
        print(f"[INGEST] post_ingest_candles {instrument}: {e}")
        return False


def candle_to_dict(candle) -> Dict[str, Any]:
    """Candle model -> dict для ingest."""
    ts = candle.timestamp
    if hasattr(ts, "timestamp"):
        time_ = int(ts.timestamp())
    else:
        time_ = int(ts)
    return {
        "time": time_,
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
    Один постоянный WebSocket на один инструмент.
    Подписка на M1, раз в M1_POLL_SEC запрашиваем свечи и отправляем на платформу.
    При обрыве — переподключение к тому же инструменту.
    Актив передаётся в API как приходит из БД, без нормализации.
    """
    if not instrument:
        print(f"[INGEST] skip: empty instrument name")
        return
    if not session_token:
        print(f"[INGEST] skip {instrument}: no session_token")
        return

    ssid = create_ssid(session_token, uid, is_demo)
    client = AsyncPocketOptionClient(
        ssid,
        is_demo=is_demo,
        enable_logging=False,
        persistent_connection=False,
        auto_reconnect=True,
        proxy_url=proxy_url,
    )

    while not stop_event.is_set():
        try:
            if not client.is_connected:
                ok = await client.connect()
                if not ok:
                    print(f"[INGEST] {instrument}: connect failed (session_token/uid or network unreachable to Pocket Option)", flush=True)
                    await asyncio.sleep(10)
                    continue
                await asyncio.sleep(3)

            if not client.is_connected:
                await asyncio.sleep(10)
                continue

            # Словарь формирующихся свечей из тиков (как в asset_scanner_websocket)
            stream_candles: Dict[int, Dict[str, Any]] = {}
            instrument_norm = (instrument or "").replace("#", "").upper()

            def _tick_asset_matches(tick_asset: str) -> bool:
                return (tick_asset or "").replace("#", "").upper() == instrument_norm

            def on_json_data(data: Any) -> None:
                try:
                    # Тики: список [['ASSET', timestamp, price], ...]
                    if isinstance(data, list) and len(data) > 0:
                        for tick_data in data:
                            if isinstance(tick_data, list) and len(tick_data) >= 3:
                                tick_asset, tick_ts, tick_price = str(tick_data[0]), float(tick_data[1]), float(tick_data[2])
                                if not _tick_asset_matches(tick_asset):
                                    continue
                                ts = int(tick_ts)
                                candle_ts = (ts // M1_PERIOD) * M1_PERIOD
                                if candle_ts not in stream_candles:
                                    stream_candles[candle_ts] = {
                                        "time": candle_ts,
                                        "open": tick_price,
                                        "high": tick_price,
                                        "low": tick_price,
                                        "close": tick_price,
                                        "volume": 0.0,
                                        "source": "tick",
                                    }
                                else:
                                    c = stream_candles[candle_ts]
                                    if c.get("source") != "websocket_candles":
                                        c["high"] = max(c["high"], tick_price)
                                        c["low"] = min(c["low"], tick_price)
                                        c["close"] = tick_price
                    # Ответ с готовыми свечами или history
                    if isinstance(data, dict) and "asset" in data:
                        asset = data.get("asset", "")
                        if not _tick_asset_matches(asset):
                            return
                        period_in = data.get("period")
                        if period_in is not None and int(period_in) != M1_PERIOD:
                            return
                        if "candles" in data:
                            for candle_data in data.get("candles", []):
                                if isinstance(candle_data, (list, tuple)) and len(candle_data) >= 5:
                                    ts = int(float(candle_data[0]))
                                    if ts not in stream_candles:
                                        stream_candles[ts] = {
                                            "time": ts,
                                            "open": float(candle_data[1]),
                                            "close": float(candle_data[2]),
                                            "high": float(candle_data[3]),
                                            "low": float(candle_data[4]),
                                            "volume": 0.0,
                                            "source": "websocket_candles",
                                        }
                        if "history" in data:
                            for tick in data.get("history", []):
                                if isinstance(tick, (list, tuple)) and len(tick) >= 2:
                                    ts = int(float(tick[0]))
                                    candle_ts = (ts // M1_PERIOD) * M1_PERIOD
                                    val = float(tick[1])
                                    if candle_ts not in stream_candles:
                                        stream_candles[candle_ts] = {
                                            "time": candle_ts,
                                            "open": val,
                                            "high": val,
                                            "low": val,
                                            "close": val,
                                            "volume": 0.0,
                                            "source": "stream",
                                        }
                                    else:
                                        c = stream_candles[candle_ts]
                                        if c.get("source") != "websocket_candles":
                                            c["high"] = max(c.get("high", val), val)
                                            c["low"] = min(c.get("low", val), val)
                                            c["close"] = val
                except Exception:
                    pass

            client._websocket.add_event_handler("json_data", on_json_data)
            candles: List[Any] = []
            try:
                end_time = datetime.now(timezone.utc)
                candles = await asyncio.wait_for(
                    client._request_candles(instrument, M1_PERIOD, CANDLES_REQUEST_COUNT, end_time),
                    timeout=15.0,
                )
                await asyncio.sleep(WAIT_FOR_STREAM_SEC)
            finally:
                try:
                    client._websocket.remove_event_handler("json_data", on_json_data)
                except Exception:
                    pass

            # Закрытые свечи из API
            closed_list = [candle_to_dict(c) for c in (candles or [])]
            closed_times = {c["time"] for c in closed_list}
            # Добавляем из stream те минуты, которых нет в ответе API (последние 6–7 формирующихся)
            for ts, cd in stream_candles.items():
                if ts not in closed_times:
                    closed_list.append({
                        "time": cd["time"],
                        "open": float(cd["open"]),
                        "high": float(cd["high"]),
                        "low": float(cd["low"]),
                        "close": float(cd["close"]),
                        "volume": float(cd.get("volume", 0)),
                    })
            closed_list.sort(key=lambda x: x["time"])
            if closed_list:
                ok = await post_ingest_candles(instrument, closed_list)
                if ok:
                    print(f"[INGEST] {instrument}: sent {len(closed_list)} candles")
                else:
                    print(f"[INGEST] {instrument}: post_ingest_candles returned False")
            else:
                print(f"[INGEST] {instrument}: got 0 candles from Pocket Option")
            await asyncio.sleep(M1_POLL_SEC)
        except asyncio.TimeoutError:
            await asyncio.sleep(5)
        except asyncio.CancelledError:
            break
        except Exception as e:
            print(f"[INGEST] {instrument} error: {e}")
            await asyncio.sleep(10)

# Global cache accessible to pocket-options-proxy.py
instruments_cache: List[Dict[str, Any]] = []

async def run_ingest_forever() -> None:
    """
    Фоновый цикл: периодически загружаем список инструментов с платформы,
    держим по одному постоянному соединению на инструмент (до MAX_CONNECTIONS).
    """
    if not PLATFORM_URL:
        print("[INGEST] PLATFORM_URL required. Ingest disabled.")
        return

    stop = asyncio.Event()
    workers: Dict[str, asyncio.Task] = {}
    global instruments_cache

    try:
        while True:
            fresh = await fetch_instruments()
            if fresh:
                instruments_cache = fresh
                instruments_cache.sort(
                    key=lambda x: (-(x.get("priority") or 0), x.get("instrument", ""))
                )
                print(f"[INGEST] fetched {len(instruments_cache)} instruments from {PLATFORM_URL}")
            else:
                if not instruments_cache:
                    print(f"[INGEST] no instruments (check platform at {PLATFORM_URL} and active instruments in DB)")

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


async def run_ingest_background() -> None:
    """Точка входа: запуск в фоне (не блокирует)."""
    asyncio.create_task(run_ingest_forever())
