"""Клиент PO с HTTP/SOCKS-прокси (как ingest), API как у BinaryOptionsToolsV2 PocketOptionAsync."""

from __future__ import annotations

import asyncio
import sys
from datetime import datetime
from pathlib import Path
from typing import Any, Optional
from urllib.parse import urlsplit

_PLATFORM_API = Path("/home/o/platform/bot/python/PocketOptionAPI-main")
if str(_PLATFORM_API) not in sys.path:
    sys.path.insert(0, str(_PLATFORM_API))

from pocketoptionapi_async.client import AsyncPocketOptionClient  # noqa: E402


def proxy_host_label(proxy_url: str) -> str:
    """Хост:порт без логина/пароля — только для логов."""
    try:
        parts = urlsplit(proxy_url)
        host = parts.hostname or "?"
        port = parts.port or ""
        return f"{host}:{port}" if port else host
    except Exception:
        return "proxy"


class ProxiedPocketClient:
    """Тонкая обёртка: history/payout/active_assets как у PocketOptionAsync."""

    def __init__(self, inner: AsyncPocketOptionClient, proxy_label: str):
        self._inner = inner
        self.proxy_label = proxy_label

    async def wait_for_assets(self, timeout: float = 60.0) -> None:
        try:
            ok = await self._inner.connect()
        except Exception as exc:
            raise RuntimeError(f"PO connect exception via {self.proxy_label}: {type(exc).__name__}: {exc}") from exc
        if not ok:
            raise RuntimeError(f"PO connect failed via {self.proxy_label}")
        deadline = asyncio.get_event_loop().time() + timeout
        while asyncio.get_event_loop().time() < deadline:
            if self._inner._payout_data:
                return
            await asyncio.sleep(0.2)
        raise RuntimeError(f"PO connected via {self.proxy_label} but payout map empty")

    async def history(self, asset: str, period: int) -> list[dict[str, Any]]:
        # V2 history(1) = S1. ingest changeSymbol отдаёт минутные бары;
        # period<60 здесь таймаутится → подменяем на M1 (последний бар = живая минута).
        tf = max(1, int(period))
        if tf < 60:
            tf = 60
        count = 100
        # REQUEST_BATCH_SIZE=0 → gather по всем парам SSID; ключ Future — asset+tf,
        # разные символы можно запрашивать параллельно (как V2).
        raw = await self._inner._request_candles(str(asset), tf, count, None, send_to=False)
        out: list[dict[str, Any]] = []
        for candle in raw or []:
            ts = getattr(candle, "timestamp", None)
            if isinstance(ts, datetime):
                unix = int(ts.timestamp())
            else:
                unix = int(ts or 0)
            out.append({
                "time": unix,
                "timestamp": unix,
                "open": float(candle.open),
                "high": float(candle.high),
                "low": float(candle.low),
                "close": float(candle.close),
            })
        return out

    async def payout(self, asset: Optional[str] = None) -> Any:
        data = await self._inner.get_payment(request_update=False, timeout=2.0)
        if isinstance(asset, str):
            row = data.get(asset) or data.get(asset.replace("#", ""))
            if not isinstance(row, dict):
                return None
            pay = row.get("payment")
            try:
                return int(pay) if pay is not None else None
            except (TypeError, ValueError):
                return None
        return {name: int(row["payment"]) for name, row in data.items() if isinstance(row, dict) and row.get("payment") is not None}

    async def active_assets(self) -> list[dict[str, Any]]:
        data = await self._inner.get_payment(request_update=False, timeout=2.0)
        out = []
        for symbol, row in data.items():
            if not isinstance(row, dict):
                continue
            out.append({
                "symbol": symbol,
                "name": str(row.get("name") or symbol),
                "payout": row.get("payment"),
                "is_active": bool(row.get("open", True)),
            })
        if out:
            return out
        # Fallback: хотя бы символы из PAIRS не потерять в name_map.
        return []

    async def close(self) -> None:
        try:
            await self._inner.disconnect()
        except Exception:
            pass

    async def disconnect(self) -> None:
        await self.close()


async def create_proxied_client(ssid: str, proxy_url: str) -> ProxiedPocketClient:
    compact = ssid.replace(" ", "")
    is_demo = '"isDemo":1' in compact or '"isDemo":true' in compact.lower()
    inner = AsyncPocketOptionClient(
        ssid,
        is_demo=is_demo,
        enable_logging=False,
        persistent_connection=False,
        auto_reconnect=True,
        proxy_url=proxy_url,
    )
    wrapped = ProxiedPocketClient(inner, proxy_host_label(proxy_url))
    await wrapped.wait_for_assets()
    return wrapped
