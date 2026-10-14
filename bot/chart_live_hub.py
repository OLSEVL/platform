# -*- coding: utf-8 -*-
"""
PR2 Chart Live Hub: Redis PUBSUB → WebSocket браузерам + Chrome-расширению.

Каналы:
  laravel-database-chart:s5            → op=s5 (по подписке на инструмент)
  laravel-database-instruments:payout  → op=payout (broadcast chart-клиентам)
  laravel-database-bot:events          → op=bot_event (UI бота)
  laravel-database-extension:signals   → op=signal (/extension-live клиентам)

Протокол chart:
  URL:  ws://host/chart-live?token=<uid|exp.hmac>
  →    {"op":"subscribe","instrument":"EURUSD_otc"}
  ←    {"op":"s5","candle":{...}}

Протокол extension (без токена):
  URL:  ws://host/extension-live
  ←    {"op":"hello","ok":true,"channel":"extension"}
  ←    {"op":"signal","instrument":"...","side":"call",...}
  →    {"op":"ping"} / ← {"op":"pong"}
"""

from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import logging
import os
import signal
import sys
import time
from collections import defaultdict
from pathlib import Path
from typing import Any, DefaultDict, Dict, Optional, Set
from urllib.parse import parse_qs, urlparse

# ---------------------------------------------------------------------------
# Env: читаем platform/.env без python-dotenv (минимальные зависимости).
# ---------------------------------------------------------------------------

_BOT_DIR = Path(__file__).resolve().parent
_ROOT = _BOT_DIR.parent
_ENV_PATH = _ROOT / ".env"


def _load_dotenv(path: Path) -> Dict[str, str]:
    values: Dict[str, str] = {}
    if not path.is_file():
        return values
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return values
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if (value.startswith('"') and value.endswith('"')) or (
            value.startswith("'") and value.endswith("'")
        ):
            value = value[1:-1]
        values[key] = value
    return values


_ENV = _load_dotenv(_ENV_PATH)
for _k, _v in _ENV.items():
    # Не перетираем уже заданные systemd Environment=.
    os.environ.setdefault(_k, _v)

APP_KEY = os.environ.get("APP_KEY", "")
REDIS_HOST = os.environ.get("REDIS_HOST", "127.0.0.1")
REDIS_PORT = int(os.environ.get("REDIS_PORT", "6379"))
REDIS_PASSWORD = os.environ.get("REDIS_PASSWORD") or ""
if REDIS_PASSWORD.lower() in ("null", "none"):
    REDIS_PASSWORD = ""
REDIS_PREFIX = os.environ.get("REDIS_PREFIX") or "laravel-database-"
REDIS_CHANNEL = REDIS_PREFIX + "chart:s5"
# Смена payout (все клиенты) — зеркало ChartLiveConfig::REDIS_PAYOUT_CHANNEL.
REDIS_PAYOUT_CHANNEL = REDIS_PREFIX + "instruments:payout"
# Open/settle paper-сделки — зеркало ChartLiveConfig::REDIS_BOT_EVENTS_CHANNEL.
REDIS_BOT_EVENTS_CHANNEL = REDIS_PREFIX + "bot:events"
# Сигналы для Chrome-расширения — зеркало ExtensionLiveConfig::REDIS_CHANNEL.
REDIS_EXTENSION_CHANNEL = REDIS_PREFIX + "extension:signals"

HUB_HOST = os.environ.get("CHART_LIVE_HUB_HOST", "127.0.0.1")
HUB_PORT = int(os.environ.get("CHART_LIVE_HUB_PORT", "8765"))

LOG = logging.getLogger("chart_live_hub")
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    stream=sys.stdout,
)


def verify_token(token: str) -> bool:
    """Зеркало ChartLiveConfig::verifyToken — payload.sig, payload = uid|exp."""
    if not APP_KEY or not token or "." not in token:
        return False
    payload, sig = token.rsplit(".", 1)
    expected = hmac.new(
        APP_KEY.encode("utf-8"),
        payload.encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    if not hmac.compare_digest(expected, sig):
        return False
    bits = payload.split("|", 1)
    if len(bits) != 2:
        return False
    try:
        exp = int(bits[1])
    except ValueError:
        return False
    return exp >= int(time.time())


def _path_is_extension(path: str) -> bool:
    """True для /extension-live (nginx проксирует полный path + query)."""
    path_only = urlparse(path).path if path else ""
    return path_only.rstrip("/").endswith("extension-live")


# Клиенты: websocket → подписанный инструмент (один на соединение — достаточно для UI).
_clients: Dict[Any, Optional[str]] = {}
# instrument → set(websocket)
_subs: DefaultDict[str, Set[Any]] = defaultdict(set)
# Chrome-расширение: все сигналы без subscribe на инструмент.
_ext_clients: Set[Any] = set()
_clients_lock = asyncio.Lock()


async def _set_subscription(ws: Any, instrument: Optional[str]) -> None:
    async with _clients_lock:
        prev = _clients.get(ws)
        if prev and prev in _subs:
            _subs[prev].discard(ws)
            if not _subs[prev]:
                del _subs[prev]
        _clients[ws] = instrument
        if instrument:
            _subs[instrument].add(ws)


async def _drop_client(ws: Any) -> None:
    async with _clients_lock:
        prev = _clients.pop(ws, None)
        if prev and prev in _subs:
            _subs[prev].discard(ws)
            if not _subs[prev]:
                del _subs[prev]
        _ext_clients.discard(ws)


async def _fanout(instrument: str, raw_json: str) -> None:
    async with _clients_lock:
        targets = list(_subs.get(instrument, ()))
    if not targets:
        return
    dead = []
    for ws in targets:
        try:
            await ws.send(raw_json)
        except Exception:
            dead.append(ws)
    for ws in dead:
        await _drop_client(ws)


async def _fanout_all(raw_json: str) -> None:
    """Payout и прочие broadcast: всем chart-клиентам (не extension)."""
    async with _clients_lock:
        targets = list(_clients.keys())
    if not targets:
        return
    dead = []
    for ws in targets:
        try:
            await ws.send(raw_json)
        except Exception:
            dead.append(ws)
    for ws in dead:
        await _drop_client(ws)


async def _fanout_extension(raw_json: str) -> None:
    """Сигналы ботов → только клиенты /extension-live."""
    async with _clients_lock:
        targets = list(_ext_clients)
    if not targets:
        # INFO: иначе «тишина» в плагине выглядит как поломка пайплайна, а это просто нет окна.
        LOG.info("extension signal dropped: no clients")
        return
    dead = []
    for ws in targets:
        try:
            await ws.send(raw_json)
        except Exception:
            dead.append(ws)
    for ws in dead:
        await _drop_client(ws)
    LOG.info("extension signal → %s client(s)", len(targets) - len(dead))


async def handler(ws: Any) -> None:
    """Браузер /chart-live или Chrome /extension-live."""
    # websockets v10+: request.path; v12+: ws.request.path
    path = ""
    try:
        req = getattr(ws, "request", None)
        path = getattr(req, "path", None) or getattr(ws, "path", "") or ""
    except Exception:
        path = ""

    token = ""
    if "?" in path:
        qs = parse_qs(urlparse(path).query)
        token = (qs.get("token") or [""])[0]

    is_extension = _path_is_extension(path)
    if is_extension:
        # Без токена: плагин в LAN, путь /extension-live фиксирован на nginx.
        await ws.send(
            json.dumps({"op": "hello", "ok": True, "channel": "extension"}, ensure_ascii=False)
        )
        async with _clients_lock:
            _ext_clients.add(ws)
        try:
            async for raw in ws:
                if not isinstance(raw, str):
                    continue
                try:
                    msg = json.loads(raw)
                except json.JSONDecodeError:
                    continue
                # Keepalive от плавающего окна расширения.
                if msg.get("op") == "ping":
                    await ws.send(json.dumps({"op": "pong"}, ensure_ascii=False))
        except Exception as exc:
            LOG.debug("extension client closed: %s", exc)
        finally:
            await _drop_client(ws)
        return

    if not verify_token(token):
        try:
            await ws.send(json.dumps({"op": "err", "message": "unauthorized"}, ensure_ascii=False))
        except Exception:
            pass
        await ws.close(code=4401, reason="unauthorized")
        return

    await ws.send(json.dumps({"op": "hello", "ok": True}, ensure_ascii=False))
    await _set_subscription(ws, None)

    try:
        async for raw in ws:
            if not isinstance(raw, str):
                continue
            try:
                msg = json.loads(raw)
            except json.JSONDecodeError:
                continue
            op = msg.get("op")
            if op == "ping":
                await ws.send(json.dumps({"op": "pong"}, ensure_ascii=False))
            elif op == "subscribe":
                inst = str(msg.get("instrument") or "").strip()
                if not inst:
                    continue
                await _set_subscription(ws, inst)
                await ws.send(
                    json.dumps({"op": "subscribed", "instrument": inst}, ensure_ascii=False)
                )
            elif op == "unsubscribe":
                await _set_subscription(ws, None)
                await ws.send(json.dumps({"op": "unsubscribed"}, ensure_ascii=False))
    except Exception as exc:
        LOG.debug("client closed: %s", exc)
    finally:
        await _drop_client(ws)


async def redis_listener() -> None:
    """SUBSCRIBE chart:s5 + payout + bot:events + extension:signals."""
    try:
        import redis.asyncio as aioredis
    except ImportError:
        LOG.error("Нужен пакет redis: pip install redis")
        raise

    while True:
        client = None
        try:
            client = aioredis.Redis(
                host=REDIS_HOST,
                port=REDIS_PORT,
                password=REDIS_PASSWORD or None,
                decode_responses=True,
            )
            pubsub = client.pubsub()
            await pubsub.subscribe(
                REDIS_CHANNEL,
                REDIS_PAYOUT_CHANNEL,
                REDIS_BOT_EVENTS_CHANNEL,
                REDIS_EXTENSION_CHANNEL,
            )
            LOG.info(
                "Redis SUBSCRIBE %s, %s, %s, %s",
                REDIS_CHANNEL,
                REDIS_PAYOUT_CHANNEL,
                REDIS_BOT_EVENTS_CHANNEL,
                REDIS_EXTENSION_CHANNEL,
            )
            async for message in pubsub.listen():
                if message is None or message.get("type") != "message":
                    continue
                data = message.get("data")
                if not isinstance(data, str) or not data:
                    continue
                channel = str(message.get("channel") or "")
                try:
                    payload = json.loads(data)
                except json.JSONDecodeError:
                    continue

                # Chrome extension: payload уже с op=signal.
                if channel == REDIS_EXTENSION_CHANNEL:
                    if not isinstance(payload, dict):
                        continue
                    if payload.get("op") != "signal":
                        payload = {**payload, "op": "signal"}
                    out = json.dumps(payload, ensure_ascii=False)
                    await _fanout_extension(out)
                    continue

                # Payout: broadcast всем WS (сайдбар).
                if channel == REDIS_PAYOUT_CHANNEL:
                    instrument = str(payload.get("instrument") or "").strip()
                    if not instrument:
                        continue
                    out = json.dumps(
                        {
                            "op": "payout",
                            "instrument": instrument,
                            "current_payout": payload.get("current_payout"),
                            "payout_is_stale": bool(payload.get("payout_is_stale")),
                            "payout_source": payload.get("payout_source"),
                            "updated_at": payload.get("updated_at"),
                        },
                        ensure_ascii=False,
                    )
                    await _fanout_all(out)
                    continue

                # Bot trade / scan: полная карточка + stats/open/signal или scan snapshot.
                if channel == REDIS_BOT_EVENTS_CHANNEL:
                    bot_id = int(payload.get("bot_id") or 0)
                    if bot_id <= 0:
                        continue
                    out = json.dumps(
                        {
                            "op": "bot_event",
                            "bot_id": bot_id,
                            "user_id": int(payload.get("user_id") or 0),
                            "event": payload.get("event"),
                            "trade_id": payload.get("trade_id"),
                            "signal_id": payload.get("signal_id"),
                            "instrument": payload.get("instrument"),
                            "trade": payload.get("trade"),
                            "stats": payload.get("stats"),
                            "open_instruments": payload.get("open_instruments"),
                            "open_instruments_user": payload.get("open_instruments_user"),
                            "open_trade_row": payload.get("open_trade_row"),
                            "closed_trade_id": payload.get("closed_trade_id"),
                            "signal": payload.get("signal"),
                            "scan": payload.get("scan"),
                            "updated_at": payload.get("updated_at"),
                        },
                        ensure_ascii=False,
                    )
                    await _fanout_all(out)
                    continue

                # S5 candle: только подписчикам инструмента.
                instrument = str(payload.get("instrument") or "").strip()
                if not instrument:
                    continue
                out = json.dumps({"op": "s5", "candle": payload}, ensure_ascii=False)
                await _fanout(instrument, out)
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            LOG.warning("Redis listener error: %s — retry in 2s", exc)
            await asyncio.sleep(2)
        finally:
            if client is not None:
                try:
                    await client.aclose()
                except Exception:
                    pass


async def main() -> None:
    if not APP_KEY:
        LOG.error("APP_KEY пуст — проверьте %s", _ENV_PATH)
        sys.exit(1)

    try:
        import websockets
    except ImportError:
        LOG.error("Нужен пакет websockets")
        sys.exit(1)

    stop = asyncio.Event()

    def _ask_stop(*_args: Any) -> None:
        stop.set()

    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            loop.add_signal_handler(sig, _ask_stop)
        except NotImplementedError:
            pass

    redis_task = asyncio.create_task(redis_listener(), name="redis-listener")

    LOG.info(
        "WS hub listening on %s:%s (channels=%s, %s, %s, %s; extension=open)",
        HUB_HOST,
        HUB_PORT,
        REDIS_CHANNEL,
        REDIS_PAYOUT_CHANNEL,
        REDIS_BOT_EVENTS_CHANNEL,
        REDIS_EXTENSION_CHANNEL,
    )
    async with websockets.serve(
        handler,
        HUB_HOST,
        HUB_PORT,
        ping_interval=20,
        ping_timeout=20,
        max_size=2**16,
    ):
        await stop.wait()

    redis_task.cancel()
    try:
        await redis_task
    except asyncio.CancelledError:
        pass
    LOG.info("hub stopped")


if __name__ == "__main__":
    asyncio.run(main())
