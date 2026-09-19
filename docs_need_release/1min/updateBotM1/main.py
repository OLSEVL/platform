from __future__ import annotations

import asyncio
import importlib.util
import math
import sys
import time
import tempfile
from uuid import uuid4
from collections import deque
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Any, Iterable, Optional

import json
import urllib.request
import urllib.parse

try:
    import requests
except ImportError:
    requests = None
try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    Image = ImageDraw = ImageFont = None
try:
    from BinaryOptionsToolsV2 import PocketOptionAsync
except ImportError:
    PocketOptionAsync = None


for stream in (sys.stdout, sys.stderr):
    try:
        stream.reconfigure(encoding="utf-8", errors="replace")
    except (AttributeError, ValueError):
        pass


# Always use the config shipped alongside this bot.  A relative path can pick
# up another bot's config when Python is launched from a different terminal
# directory, producing unrelated profiles and scan settings.
CONFIG_PATH = Path(__file__).resolve().with_name("config.py")


SIGNAL_OUTPUT_PRIORITY = {
    "MAX": 0,
}

SIGNAL_OUTPUT_LABELS = {
    "MAX": "MAX",
}

SIGNAL_FILTER_EXEMPT_SIGNAL_TYPES = {"MAX"}
SIGNAL_SEQUENCE_EXEMPT_SIGNAL_TYPES = {"MAX"}

SIGNAL_FILTER_FACTS = {
    "TRUE", "FALSE", "LVL", "ZLVL", "LEVEL", "ZLEVEL", "VAL", "VAH", "OB", "ORDER_BLOCK",
    "RSI", "RSI_OVERBOUGHT", "RSI_OVERSOLD", "BB", "BB_UPPER", "BB_LOWER"
}


@dataclass
class SymbolState:
    symbol: str
    display_name: str
    candles_count: int
    average_body: float
    max_body: float
    candle_history: deque
    level_candle_histories: dict[int, list[dict[str, float]]]
    body_history: deque
    recent_bodies: deque
    recent_directions: deque
    recent_is_intermediate: deque
    last_closed_candle_start: Optional[int]
    # Текущая незакрытая M1. Профили получают её отдельным последним значением,
    # а уровни используют её для живых M1/M5/M15.
    live_candle: Optional[dict[str, float]] = None
    # What has already been emitted for the current open M1 candle.  The
    # values are deliberately held in memory rather than inferred from the
    # last close: an intrabar extreme must never move backwards.
    live_signal_candle_start: Optional[int] = None
    live_last_emitted_measure: float = 0.0
    live_best_profile_priority: Optional[int] = None
    payout_cache: dict[int, int] = field(default_factory=dict)
    cached_swing_highs: dict[int, list[dict[str, float]]] = field(default_factory=dict)
    cached_swing_lows: dict[int, list[dict[str, float]]] = field(default_factory=dict)
    cached_level_allowances: dict[int, float] = field(default_factory=dict)
    cached_mirror_highs: dict[int, list[dict[str, float]]] = field(default_factory=dict)
    cached_mirror_lows: dict[int, list[dict[str, float]]] = field(default_factory=dict)
    cached_order_blocks: list[dict[str, Any]] = field(default_factory=list)
    raw_level_histories: dict[int, list[dict[str, Any]]] = field(default_factory=dict)
    max_series_ref_ts: dict[str, int] = field(default_factory=dict)
    # Pivot is considered only once, after its right-hand candles are closed.
    # This prevents an intrabar M5/M15 value from repeatedly rebuilding levels.
    checked_level_candidates: dict[int, set[int]] = field(default_factory=dict)


@dataclass
class ClientWorker:
    client: Any
    ssid_index: int
    states: list[SymbolState]
    worker_cycle_timeout: float


@dataclass
class CycleResult:
    messages: list["SignalMessage"]
    processed: int = 0
    missing: int = 0
    errors: int = 0
    signals_found: int = 0
    payout_rejected: int = 0
    duplicate_suppressed: int = 0
    level_marked: int = 0
    rsi_marked: int = 0
    body_min: Optional[float] = None
    body_max: Optional[float] = None
    raw_first_ts: Optional[float] = None
    raw_last_ts: Optional[float] = None
    history1_count: int = 0
    history1_total: float = 0.0
    history1_min: Optional[float] = None
    history1_max: Optional[float] = None


@dataclass
class HistoryFetchResult:
    candle: Optional[dict[str, float]]
    elapsed: float
    error: Optional[BaseException] = None
    raw_count: int = 0
    raw_first_ts: Optional[float] = None
    raw_last_ts: Optional[float] = None
    timeframe_candles: list[dict[str, float]] = field(default_factory=list)
    raw_candles: list[dict[str, Any]] = field(default_factory=list)


@dataclass
class SignalMessage:
    text: str
    chart_path: Optional[Path] = None


@dataclass
class OutputBatch:
    """Results from all SSID workers for one History(1) request."""
    fetch_elapsed: float
    completed_workers: int = 0
    messages: list[SignalMessage] = field(default_factory=list)


# ================= CONFIG =================

TIME_OFFSET_HOURS = 0.0
GLOBAL_CONFIG = {}

def parse_restricted_countries(val: Any) -> set[str]:
    if not val:
        return set()
    if isinstance(val, (list, tuple, set)):
        items = list(val)
    else:
        s = str(val).replace("comma", ",").replace(";", ",")
        items = s.split(",")
    
    result = set()
    for item in items:
        sub_items = str(item).strip().split()
        for sub in sub_items:
            clean_sub = sub.strip(",;").upper()
            if clean_sub and len(clean_sub) == 2 and clean_sub.isalpha():
                result.add(clean_sub)
    return result


_IP_CACHE: dict[str, Any] = {"ip": None, "country": None, "timestamp": 0.0}

def get_current_ip_country() -> tuple[Optional[str], Optional[str]]:
    now = time.time()
    if _IP_CACHE["ip"] and _IP_CACHE["country"] and (now - _IP_CACHE["timestamp"] < 30):
        return _IP_CACHE["ip"], _IP_CACHE["country"]

    services = [
        ("http://ip-api.com/json/", lambda d: (d.get("query"), d.get("countryCode"))),
        ("https://ipapi.co/json/", lambda d: (d.get("ip"), d.get("country_code"))),
        ("https://api.country.is/", lambda d: (d.get("ip"), d.get("country"))),
        ("https://ipinfo.io/json", lambda d: (d.get("ip"), d.get("country")))
    ]

    for url, extractor in services:
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
            with urllib.request.urlopen(req, timeout=3) as resp:
                if resp.status == 200:
                    data = json.loads(resp.read().decode("utf-8"))
                    ip, country = extractor(data)
                    if ip and country:
                        _IP_CACHE["ip"] = str(ip).strip()
                        _IP_CACHE["country"] = str(country).strip().upper()
                        _IP_CACHE["timestamp"] = now
                        return _IP_CACHE["ip"], _IP_CACHE["country"]
        except Exception:
            continue

    return _IP_CACHE.get("ip"), _IP_CACHE.get("country")


async def check_ip_allowed(config: dict[str, Any]) -> bool:
    restricted = config.get("RESTRICTED_COUNTRIES_SET", set())
    if not restricted:
        return True

    try:
        ip, country = await asyncio.to_thread(get_current_ip_country)
    except Exception:
        ip, country = None, None

    if country and country.upper() in restricted:
        write_line("IP address filter IP address did not pass.")
        return False
    return True


def load_python_config(path: Path) -> dict[str, Any]:
    global TIME_OFFSET_HOURS, GLOBAL_CONFIG
    spec = importlib.util.spec_from_file_location("local_config", path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Unable to load config from {path}")

    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    TIME_OFFSET_HOURS = float(getattr(module, "TIME_OFFSET_HOURS", 0.0))
    ssid = getattr(module, "SSID", "")
    ssids = getattr(module, "SSIDS", None)
    pairs = getattr(module, "PAIRS", [])

    if isinstance(ssids, list) and ssids:
        normalized_ssids = [str(item).strip() for item in ssids if str(item).strip()]
    else:
        normalized_ssids = [str(ssid).strip()]

    chat_id = getattr(module, "CHAT_ID", "")
    chat_ids = getattr(module, "CHAT_IDS", None)
    normalized_chat_ids = normalize_chat_ids(chat_id, chat_ids)
    
    timeframe_seconds = int(getattr(module, "TIMEFRAME_SECONDS", 60))
    
    level_timeframes = sorted({
        int(item)
        for item in getattr(module, "LEVEL_TIMEFRAMES_SECONDS", [timeframe_seconds, 300, 900])
        if int(item) >= timeframe_seconds
    })
    if timeframe_seconds not in level_timeframes:
        level_timeframes.insert(0, timeframe_seconds)

    SIGNAL_FILTER_FACTS.add("LVL")
    SIGNAL_FILTER_FACTS.add("ZLVL")
    SIGNAL_FILTER_FACTS.add("LEVEL")
    SIGNAL_FILTER_FACTS.add("ZLEVEL")
    SIGNAL_FILTER_FACTS.add("VAL")
    SIGNAL_FILTER_FACTS.add("VAH")
    for tf in level_timeframes:
        tf_label = timeframe_label(tf).upper()
        SIGNAL_FILTER_FACTS.add(f"LVL_{tf_label}")
        SIGNAL_FILTER_FACTS.add(f"ZLVL_{tf_label}")
        SIGNAL_FILTER_FACTS.add(f"LEVEL_{tf_label}")
        SIGNAL_FILTER_FACTS.add(f"ZLEVEL_{tf_label}")
        SIGNAL_FILTER_FACTS.add(f"VAL_{tf_label}")
        SIGNAL_FILTER_FACTS.add(f"VAH_{tf_label}")

    SIGNAL_OUTPUT_PRIORITY.clear()
    SIGNAL_OUTPUT_PRIORITY["MAX"] = 0
    SIGNAL_OUTPUT_LABELS.clear()
    SIGNAL_OUTPUT_LABELS["MAX"] = "MAX"

    profiles = getattr(module, "STRATEGY_PROFILES", [])
    for p in profiles:
        if not p.get("enabled", True):
            continue
        profile_id = p["id"]
        profile_label = p.get("label", profile_id).strip()
        profile_emoji = p.get("emoji", "").strip()
        prefix = f"{profile_emoji} " if profile_emoji else "-"
        multipliers = p.get("candle_multipliers", {})
        for count in sorted(multipliers.keys()):
            word = f"CANDLE_{count}_{profile_id}"
            SIGNAL_OUTPUT_PRIORITY[word] = count
            SIGNAL_OUTPUT_LABELS[word] = f"{prefix}{profile_label}{count}-"

    restricted_raw = getattr(module, "RESTRICTED_COUNTRIES", "")
    restricted_set = parse_restricted_countries(restricted_raw)

    res = {
        "SSIDS": normalized_ssids,
        "PAIRS": pairs,
        "BOT_TOKEN": str(getattr(module, "BOT_TOKEN", "")).strip(),
        "CHAT_IDS": normalized_chat_ids,
        "REQUEST_BATCH_SIZE": int(getattr(module, "REQUEST_BATCH_SIZE", 0)),
        "HISTORY_PERIOD": int(getattr(module, "HISTORY_PERIOD", 60)),
        "HISTORY_INIT_PERIOD": timeframe_seconds,
        "HISTORY_SOURCE_PERIOD": 1,
        "HISTORY_TIMEOUT": float(getattr(module, "HISTORY_TIMEOUT", getattr(module, "TICK_TIMEOUT", 5))),
        "TIMEFRAME_SECONDS": timeframe_seconds,
        "DEBUG_CYCLE": bool(getattr(module, "DEBUG_CYCLE", False)),
        "MIN_PAYOUT": int(getattr(module, "MIN_PAYOUT", 80)),
        "RESTRICTED_COUNTRIES": restricted_raw,
        "RESTRICTED_COUNTRIES_SET": restricted_set,
        "STRATEGY_PROFILES": getattr(module, "STRATEGY_PROFILES", []),
        "TRIM_LOW_COUNT": int(getattr(module, "TRIM_LOW_COUNT", getattr(module, "TRIM_COUNT", 0))),
        "TRIM_HIGH_COUNT": int(getattr(module, "TRIM_HIGH_COUNT", getattr(module, "TRIM_COUNT", 0))),
        "MAX_BODY_PERCENT_SERIES": {
            int(k): (
                {
                    "percent": float(v["percent"]),
                    "filter_enabled": bool(v.get("filter_enabled", False)),
                    "filter_expr": str(v.get("filter_expr", "")).strip()
                }
                if isinstance(v, dict)
                else float(v)
            )
            for k, v in getattr(module, "MAX_BODY_PERCENT_SERIES", {}).items()
        },
        "MAX_BODY_PERCENT_DEFAULT": (
            {
                "percent": float(getattr(module, "MAX_BODY_PERCENT_DEFAULT", {}).get("percent", 100.0)),
                "filter_enabled": bool(getattr(module, "MAX_BODY_PERCENT_DEFAULT", {}).get("filter_enabled", False)),
                "filter_expr": str(getattr(module, "MAX_BODY_PERCENT_DEFAULT", {}).get("filter_expr", "")).strip()
            }
            if isinstance(getattr(module, "MAX_BODY_PERCENT_DEFAULT", None), dict)
            else float(getattr(module, "MAX_BODY_PERCENT_DEFAULT", 100.0))
        ),
        "MAX_ONLY_LONGEST_SERIES": bool(getattr(module, "MAX_ONLY_LONGEST_SERIES", True)),
        "SCAN_INTERVAL_SECONDS": int(getattr(module, "SCAN_INTERVAL_SECONDS", 5)),
        # The open M1 is evaluated from its largest excursion away from the
        # open (max(high-open, open-low)).  Closed candles always use bodies.
        "LIVE_CANDLE_WICK_SIGNALS": bool(getattr(module, "LIVE_CANDLE_WICK_SIGNALS", True)),
        "SEND_RULE_SEQUENCE_SIGNALS": bool(getattr(module, "SEND_RULE_SEQUENCE_SIGNALS", False)),
        "TIME_OFFSET_HOURS": TIME_OFFSET_HOURS,
        "LEVEL_SETTINGS": getattr(module, "LEVEL_SETTINGS", {}),
        "LEVEL_PIVOT_LEFT": int(getattr(module, "LEVEL_PIVOT_LEFT", 2)),
        "LEVEL_PIVOT_RIGHT": int(getattr(module, "LEVEL_PIVOT_RIGHT", 2)),
        "LEVEL_DISTANCE_BODY_MULTIPLIER": float(getattr(module, "LEVEL_DISTANCE_BODY_MULTIPLIER", 0.05)),
        "LEVEL_HISTORY_LIMIT_MINUTES": int(getattr(module, "LEVEL_HISTORY_LIMIT_MINUTES", 0)),
        "LEVEL_TIMEFRAMES_SECONDS": level_timeframes,
        "LEVEL_TIMEFRAME_PRIORITY": str(getattr(module, "LEVEL_TIMEFRAME_PRIORITY", "lower")).strip().lower(),
        "LEVEL_UPDATE_HIGHER_TF": bool(getattr(module, "LEVEL_UPDATE_HIGHER_TF", True)),
        "LEVEL_EXCLUDE_LATEST_CANDLE_FROM_FILTER": bool(getattr(module, "LEVEL_EXCLUDE_LATEST_CANDLE_FROM_FILTER", True)),
        "LEVEL_EXCLUDE_ON_BREAK": bool(getattr(module, "LEVEL_EXCLUDE_ON_BREAK", True)),
        "LEVEL_MATCH_MODE": str(getattr(module, "LEVEL_MATCH_MODE", "high_low")).strip().lower(),
        "DETAILED_MARKERS": bool(getattr(module, "DETAILED_MARKERS", True)),
        "MAX_RULE_DETAILS_ENABLED": bool(getattr(module, "MAX_RULE_DETAILS_ENABLED", True)),
        "TELEGRAM_CHARTS_ENABLED": bool(getattr(module, "TELEGRAM_CHARTS_ENABLED", False)),
        "TELEGRAM_CHART_CANDLES": max(15, int(getattr(module, "TELEGRAM_CHART_CANDLES", 40))),
        "PAIRS_MAP": getattr(module, "PAIRS_MAP", {}),
    }
    GLOBAL_CONFIG.clear()
    GLOBAL_CONFIG.update(res)
    return res


# ================= UTILS =================

def normalize_chat_ids(chat_id: Any, chat_ids: Any) -> list[str]:
    values: list[Any] = []
    if isinstance(chat_ids, (list, tuple, set)):
        values.extend(chat_ids)
    elif chat_ids:
        values.append(chat_ids)
    if chat_id:
        values.append(chat_id)
    result = []
    seen = set()
    for value in values:
        normalized = str(value).strip()
        if not normalized or normalized in seen:
            continue
        seen.add(normalized)
        result.append(normalized)
    return result


def write_line(text: str) -> None:
    try:
        sys.stdout.write(text + "\n")
        sys.stdout.flush()
    except UnicodeEncodeError:
        encoded = (text + "\n").encode("utf-8", errors="replace")
        sys.stdout.buffer.write(encoded)
        sys.stdout.buffer.flush()


_TELEGRAM_SESSION: Optional[requests.Session] = None


def _get_telegram_session() -> requests.Session:
    global _TELEGRAM_SESSION
    if _TELEGRAM_SESSION is None:
        _TELEGRAM_SESSION = requests.Session()
    return _TELEGRAM_SESSION


def telegram_enabled(config: dict[str, Any]) -> bool:
    return bool(config.get("BOT_TOKEN") and config.get("CHAT_IDS"))


def send_telegram_message_sync(config: dict[str, Any], text: str) -> None:
    if not telegram_enabled(config):
        return
    url = f"https://api.telegram.org/bot{config['BOT_TOKEN']}/sendMessage"
    session = _get_telegram_session()
    chat_ids = config.get("CHAT_IDS", [])

    def _send(chat_id: Any) -> None:
        try:
            session.post(url, data={"chat_id": chat_id, "text": text}, timeout=5)
        except Exception:
            pass

    if len(chat_ids) == 1:
        _send(chat_ids[0])
    elif chat_ids:
        from concurrent.futures import ThreadPoolExecutor
        with ThreadPoolExecutor(max_workers=min(len(chat_ids), 5)) as executor:
            list(executor.map(_send, chat_ids))


async def send_telegram_message_async(config: dict[str, Any], text: str) -> None:
    if not telegram_enabled(config):
        return
    await asyncio.to_thread(send_telegram_message_sync, config, text)


def send_telegram_photo_sync(config: dict[str, Any], chart_path: Path, caption: str) -> None:
    if requests is None or not telegram_enabled(config) or not chart_path.is_file():
        return
    url = f"https://api.telegram.org/bot{config['BOT_TOKEN']}/sendPhoto"
    session = _get_telegram_session()
    chat_ids = config.get("CHAT_IDS", [])

    def _send(chat_id: Any) -> None:
        try:
            with chart_path.open("rb") as image_file:
                session.post(
                    url,
                    data={"chat_id": chat_id, "caption": caption},
                    files={"photo": (chart_path.name, image_file, "image/png")},
                    timeout=12,
                )
        except Exception:
            pass

    if len(chat_ids) == 1:
        _send(chat_ids[0])
    elif chat_ids:
        from concurrent.futures import ThreadPoolExecutor
        with ThreadPoolExecutor(max_workers=min(len(chat_ids), 5)) as executor:
            list(executor.map(_send, chat_ids))


async def send_telegram_photo_async(config: dict[str, Any], chart_path: Optional[Path], caption: str) -> None:
    if chart_path is None:
        return
    try:
        if telegram_enabled(config):
            await asyncio.to_thread(send_telegram_photo_sync, config, chart_path, caption)
    finally:
        try:
            chart_path.unlink(missing_ok=True)
        except OSError:
            pass


async def emit_message_block(
    lines: list[str],
    config: dict[str, Any],
    *,
    send_to_telegram: bool = True,
    telegram_lines: Optional[list[str]] = None,
    prefix_first: bool = True,
) -> None:
    for index, line in enumerate(lines):
        write_line(f"== {line}" if index == 0 and prefix_first else line)
    telegram_message_lines = lines if telegram_lines is None else telegram_lines
    if send_to_telegram and telegram_enabled(config) and telegram_message_lines:
        try:
            await send_telegram_message_async(config, "\n".join(telegram_message_lines))
        except Exception:
            pass


def short_display_name(display_name: str, symbol: str, pairs_map: Optional[dict[str, str]] = None) -> str:
    if pairs_map and symbol in pairs_map:
        return pairs_map[symbol]
    is_otc = "OTC" in display_name or "otc" in symbol.lower() or "OTC" in symbol
    cleaned_name = display_name.replace("OTC", "").replace("otc", "").replace("  ", " ").strip()
    words = [w for w in cleaned_name.split() if w]
    base_word = words[0] if words else ""
    formatted_base = base_word[:7] if len(base_word) > 7 else base_word.ljust(7)
    return f"{formatted_base} OTC" if is_otc else f"{formatted_base}    "


def normalize_symbols(symbols: Iterable[str]) -> list[str]:
    return [s.strip() for s in symbols if s.strip()]


def chunked(items: list[str], size: int) -> list[list[str]]:
    return [items[i:i + size] for i in range(0, len(items), size)]


def assign_symbols_to_ssids(symbols: list[str], ssids_count: int) -> list[list[str]]:
    assignments = [[] for _ in range(ssids_count)]
    if ssids_count <= 0:
        return assignments
    for index, symbol in enumerate(symbols):
        assignments[index % ssids_count].append(symbol)
    return assignments


def format_ts(ts: float) -> str:
    offset_ts = ts + TIME_OFFSET_HOURS * 3600
    return datetime.fromtimestamp(offset_ts).strftime("%H:%M")


def format_ts_with_seconds(ts: float) -> str:
    return datetime.fromtimestamp(ts).strftime("%H:%M:%S")


def get_round_step(price: float) -> float:
    if price < 0.05: return 0.0001
    elif price < 0.5: return 0.001
    elif price < 5.0: return 0.001
    elif price < 50.0: return 0.01
    elif price < 500.0: return 0.1
    elif price < 5000.0: return 1.0
    elif price < 25000.0: return 10.0
    else: return 100.0


def is_near_round_number(price: float, allowance: float) -> bool:
    if price <= 0: return False
    step = get_round_step(price)
    closest = round(price / step) * step
    return abs(price - closest) <= allowance


def format_cycle_debug_stats(result: CycleResult) -> str:
    body_min = result.body_min if result.body_min is not None else 0.0
    body_max = result.body_max if result.body_max is not None else 0.0
    raw_range = ""
    if result.raw_first_ts is not None and result.raw_last_ts is not None:
        raw_range = f" raw={format_ts_with_seconds(result.raw_first_ts)}..{format_ts_with_seconds(result.raw_last_ts)}"
    return (
        f"signals={result.signals_found} payout_rejected={result.payout_rejected} "
        f"duplicate_suppressed={result.duplicate_suppressed} "
        f"level_marked={result.level_marked} rsi_marked={result.rsi_marked} "
        f"body_min={body_min:.6f} body_max={body_max:.6f}{raw_range}"
    )


def candle_timestamp(candle: dict[str, Any]) -> float:
    for key in ("timestamp", "time"):
        if key in candle:
            value = float(candle[key])
            if value > 10_000_000_000_000: return value / 1_000_000
            if value > 10_000_000_000: return value / 1_000
            return value
    raise KeyError("history candle has no timestamp/time field")


def normalize_history_candle(candle: dict[str, Any]) -> dict[str, float]:
    return {
        "timestamp": candle_timestamp(candle),
        "open": float(candle["open"]),
        "high": float(candle["high"]),
        "low": float(candle["low"]),
        "close": float(candle["close"]),
    }


def normalize_and_sort_history(candles: Iterable[dict[str, Any]]) -> list[dict[str, float]]:
    return sorted(
        (normalize_history_candle(candle) for candle in candles),
        key=lambda candle: candle["timestamp"],
    )


def candle_body(candle: dict[str, Any]) -> float:
    return abs(float(candle["close"]) - float(candle["open"]))


def candle_direction(candle: dict[str, Any]) -> int:
    close = float(candle["close"])
    open_ = float(candle["open"])
    return 1 if close > open_ else -1 if close < open_ else 0


def live_wick_measure_and_direction(candle: dict[str, Any]) -> tuple[float, int]:
    """Return the largest *already reached* intrabar movement from the open.

    Unlike the body, this value cannot shrink while the broker's current M1
    high and low are cumulative.  Its direction is the side which made that
    extreme; a tie is neutral until a real extreme appears.
    """
    open_ = float(candle["open"])
    up = max(0.0, float(candle["high"]) - open_)
    down = max(0.0, open_ - float(candle["low"]))
    if up > down:
        return up, 1
    if down > up:
        return down, -1
    return up, 0


def profile_live_priority(config: dict[str, Any], profile_id: str) -> int:
    """Smaller value means a higher colour priority for one live M1 bar."""
    for index, profile in enumerate(config.get("STRATEGY_PROFILES", [])):
        if profile.get("id") == profile_id:
            return int(profile.get("live_priority", index))
    return len(config.get("STRATEGY_PROFILES", []))


def body_top_rank(body: float, body_history: Iterable[float], top_count: int) -> Optional[int]:
    if body <= 0: return None
    top_count = max(1, int(top_count))
    previous_bodies = sorted((float(b) for b in body_history if float(b) > 0), reverse=True)
    if not previous_bodies: return 1
    threshold_index = min(top_count, len(previous_bodies)) - 1
    if body < previous_bodies[threshold_index]: return None
    return 1 + sum(1 for pb in previous_bodies if pb > body)


def body_top_rank_for_window(state: SymbolState, body: float, config: dict[str, Any], top_count: int) -> Optional[int]:
    top_history = list(state.body_history)
    history_period = max(1, int(config.get("HISTORY_PERIOD", len(top_history) + 1)))
    if history_period == 1: top_history = []
    elif len(top_history) >= history_period:
        top_history = top_history[-(history_period - 1):]
    return body_top_rank(body, top_history, top_count)


def max_body_reference_timestamp(state: SymbolState) -> Optional[int]:
    if state.max_body <= 0 or not state.body_history: return None
    body_count = len(state.body_history)
    history_tail = list(state.candle_history)[-body_count:]
    body_tail = list(state.body_history)[-len(history_tail):]
    for candle, body in reversed(list(zip(history_tail, body_tail))):
        if math.isclose(float(body), float(state.max_body), rel_tol=1e-12, abs_tol=1e-12):
            return int(candle["timestamp"])
    return None


def zero_candle(candle_start: int) -> dict[str, float]:
    return {"timestamp": float(candle_start), "open": 0.0, "high": 0.0, "low": 0.0, "close": 0.0}


def is_zero_candle(candle: dict[str, Any]) -> bool:
    return float(candle["open"]) == 0.0 and float(candle["high"]) == 0.0 and float(candle["low"]) == 0.0 and float(candle["close"]) == 0.0


def aggregate_history_to_timeframe(candles: Iterable[dict[str, Any]], timeframe_seconds: int, *, exclude_latest_bucket: bool = False) -> list[dict[str, float]]:
    buckets: dict[int, list[dict[str, float]]] = {}
    for raw_candle in candles:
        candle = normalize_history_candle(raw_candle)
        candle_ts = int(candle["timestamp"])
        bucket_start = candle_ts - (candle_ts % timeframe_seconds)
        buckets.setdefault(bucket_start, []).append(candle)
    if exclude_latest_bucket and buckets:
        buckets.pop(max(buckets), None)
    result = []
    for bucket_start, bucket in sorted(buckets.items()):
        bucket.sort(key=lambda c: c["timestamp"])
        result.append({
            "timestamp": float(bucket_start),
            "open": bucket[0]["open"],
            "high": max(c["high"] for c in bucket),
            "low": min(c["low"] for c in bucket),
            "close": bucket[-1]["close"],
        })
    return result


def merge_initial_timeframe_history(init_candles: Iterable[dict[str, Any]], source_candles: Iterable[dict[str, Any]], timeframe_seconds: int) -> list[dict[str, float]]:
    base_candles = normalize_and_sort_history(init_candles)
    latest_base_start = int(base_candles[-1]["timestamp"]) if base_candles else None
    source_minutes = aggregate_history_to_timeframe(normalize_and_sort_history(source_candles), timeframe_seconds, exclude_latest_bucket=True)
    tail_candles = [c for c in source_minutes if latest_base_start is None or int(c["timestamp"]) > latest_base_start]
    return sorted([*base_candles, *tail_candles], key=lambda c: c["timestamp"])


def timeframe_label(timeframe_seconds: int) -> str:
    minutes = int(timeframe_seconds) // 60
    return f"M{minutes}" if minutes >= 1 else f"S{timeframe_seconds}"


def build_complete_higher_tf_history(raw_base_candles: Iterable[dict[str, Any]], lower_timeframe_candles: Iterable[dict[str, Any]], timeframe_seconds: int) -> list[dict[str, float]]:
    base = normalize_and_sort_history(raw_base_candles)
    lower = normalize_and_sort_history(lower_timeframe_candles)
    # Последний бакет тоже нужен: это живая M5/M15, собранная из history(1), как на графике.
    aggregated_lower = aggregate_history_to_timeframe(lower, timeframe_seconds, exclude_latest_bucket=False)
    if lower:
        first_lower_start = int(lower[0]["timestamp"])
        base = [c for c in base if int(c["timestamp"]) + timeframe_seconds <= first_lower_start]
    return sorted([*base, *aggregated_lower], key=lambda c: int(c["timestamp"]))


def compute_trimmed_mean_body_values(bodies: Iterable[float], trim_low_count: int, trim_high_count: int) -> float:
    values = sorted(float(b) for b in bodies)
    if not values: return 0.0
    if len(values) < 5: return sum(values) / len(values)
    trim_low_count = max(0, int(trim_low_count))
    trim_high_count = max(0, int(trim_high_count))
    max_total_trim = len(values) - 1
    total_trim = min(trim_low_count + trim_high_count, max_total_trim)
    trim_low_count = min(trim_low_count, total_trim)
    trim_high_count = min(trim_high_count, total_trim - trim_low_count)
    start = trim_low_count
    end = len(values) - trim_high_count
    return sum(values[start:end]) / len(values[start:end]) if start < end else 0.0


def compute_max_body(candles: Iterable[dict[str, Any]]) -> float:
    bodies = [candle_body(c) for c in candles]
    return max(bodies) if bodies else 0.0


def typical_level_body(candles: Iterable[dict[str, Any]], lookback: int) -> float:
    """Медианное тело закрытых свечей одного ТФ — устойчивый масштаб допуска уровня."""
    values = [candle_body(c) for c in list(candles)[-max(1, int(lookback)):]]
    values = sorted(value for value in values if value > 0)
    if not values:
        return 0.0
    middle = len(values) // 2
    return values[middle] if len(values) % 2 else (values[middle - 1] + values[middle]) / 2.0


def recompute_body_metrics(state: SymbolState, config: dict[str, Any]) -> None:
    if not state.body_history:
        state.average_body = 0.0
        state.max_body = 0.0
        return
    bodies = list(state.body_history)
    state.average_body = compute_trimmed_mean_body_values(bodies, config["TRIM_LOW_COUNT"], config["TRIM_HIGH_COUNT"])
    state.max_body = max(bodies)


def append_closed_candle_to_state(state: SymbolState, candle: dict[str, Any], config: dict[str, Any]) -> None:
    normalized_candle = normalize_history_candle(candle)
    body = candle_body(normalized_candle)
    direction = candle_direction(normalized_candle)
    state.last_closed_candle_start = int(normalized_candle["timestamp"])
    state.candle_history.append(normalized_candle)
    state.body_history.append(body)
    state.recent_bodies.append(body)
    state.recent_directions.append(direction)
    state.recent_is_intermediate.append(False)
    recompute_body_metrics(state, config)
    refresh_level_cache_incrementally(state, config, add_confirmed_levels=True)


def update_live_candle_in_state(state: SymbolState, candle: dict[str, Any], config: dict[str, Any]) -> None:
    """Updates the live M1 without rebuilding the full level history."""
    normalized_candle = normalize_history_candle(candle)
    if state.last_closed_candle_start is None or int(normalized_candle["timestamp"]) > int(state.last_closed_candle_start):
        # Some history(1) replies are a snapshot taken in the middle of a
        # minute.  Preserve extrema from previous polls, so a late/incomplete
        # reply cannot make an already observed wick disappear.
        previous = state.live_candle
        if previous is not None and int(previous["timestamp"]) == int(normalized_candle["timestamp"]):
            normalized_candle["open"] = float(previous["open"])
            normalized_candle["high"] = max(float(previous["high"]), float(normalized_candle["high"]))
            normalized_candle["low"] = min(float(previous["low"]), float(normalized_candle["low"]))
        state.live_candle = normalized_candle
    else:
        state.live_candle = None
    # An open candle cannot confirm a new fractal.  It can only invalidate an
    # existing level by crossing it, so this is intentionally O(active levels).
    refresh_level_cache_incrementally(state, config, add_confirmed_levels=False)


# ================= SWING POINTS & LEVEL GENERATION =================

def find_swing_level_points(candles: Iterable[dict[str, Any]], *, lookback: int, pivot_left: int, pivot_right: int, use_body: bool = False) -> tuple[list[dict[str, float]], list[dict[str, float]]]:
    values = [c for c in (normalize_history_candle(candle) for candle in candles) if not is_zero_candle(c)]
    if lookback > 0: values = values[-lookback:]
    pivot_left = max(1, pivot_left)
    pivot_right = max(1, pivot_right)
    if len(values) < pivot_left + pivot_right + 1: return [], []
    def get_val_high(c): return max(float(c.get("open", 0)), float(c.get("close", 0))) if use_body else float(c.get("high", 0))
    def get_val_low(c): return min(float(c.get("open", 0)), float(c.get("close", 0))) if use_body else float(c.get("low", 0))
    swing_highs: list[dict[str, float]] = []
    swing_lows: list[dict[str, float]] = []
    for index in range(pivot_left, len(values) - pivot_right):
        candle = values[index]
        left = values[index - pivot_left:index]
        right = values[index + 1:index + pivot_right + 1]
        val_high = get_val_high(candle)
        val_low = get_val_low(candle)
        if all(val_high > get_val_high(item) for item in left) and all(val_high >= get_val_high(item) for item in right):
            swing_highs.append({"timestamp": candle["timestamp"], "price": val_high, "open": candle["open"], "close": candle["close"], "high": candle["high"], "low": candle["low"]})
        if all(val_low < get_val_low(item) for item in left) and all(val_low <= get_val_low(item) for item in right):
            swing_lows.append({"timestamp": candle["timestamp"], "price": val_low, "open": candle["open"], "close": candle["close"], "high": candle["high"], "low": candle["low"]})
    return swing_highs, swing_lows


def level_zone_bounds(
    level: dict[str, float],
    direction: int,
    mirror: bool,
    wick_only: bool = False,
    zone_mode: str = "body_to_wick",
    wick_tip_fraction: float = 0.25,
) -> tuple[float, float]:
    """Возвращает торговую зону свинга.

    body_to_wick — весь фитиль от края тела; wick_tip — только участок у экстремума.
    """
    if wick_only or not all(k in level for k in ("open", "close", "high", "low")):
        price = float(level["price"])
        return price, price
    open_ = float(level["open"])
    close = float(level["close"])
    high = float(level["high"])
    low = float(level["low"])
    body_low = min(open_, close)
    body_high = max(open_, close)
    is_upper_wick = (direction > 0 and not mirror) or (direction < 0 and mirror)
    if str(zone_mode).strip().lower() == "wick_tip":
        fraction = min(1.0, max(0.0, float(wick_tip_fraction)))
        if is_upper_wick:
            return high - ((high - body_high) * fraction), high
        return low, low + ((body_low - low) * fraction)
    if is_upper_wick:
        return body_high, high
    return low, body_low


def filter_active_levels(levels: list[dict[str, float]], candles: list[dict[str, float]], direction: int, timeframe_seconds: int, pivot_right: int, allowance: float, wick_only: bool = False, zone_mode: str = "body_to_wick", wick_tip_fraction: float = 0.25, exclude_latest: bool = False, exclude_on_break: bool = True) -> list[dict[str, float]]:
    if not exclude_on_break: return levels
    active_levels = []
    sorted_candles = sorted(candles, key=lambda c: c["timestamp"])
    if exclude_latest and len(sorted_candles) > 1: sorted_candles = sorted_candles[:-1]
    allowance = max(0.0, float(allowance))
    for lvl in levels:
        lvl_ts = int(lvl["timestamp"])
        zone_min, zone_max = level_zone_bounds(lvl, direction, False, wick_only, zone_mode, wick_tip_fraction)
        break_above = zone_max + allowance
        break_below = zone_min - allowance
        fixation_ts = lvl_ts + (pivot_right * timeframe_seconds)
        is_broken = False
        for c in sorted_candles:
            if int(c["timestamp"]) <= fixation_ts: continue
            if direction > 0:
                if c["high"] > break_above: is_broken = True; break
            else:
                if c["low"] < break_below: is_broken = True; break
        if not is_broken: active_levels.append(lvl)
    return active_levels


def filter_active_mirror_levels(levels: list[dict[str, float]], candles: list[dict[str, float]], direction: int, timeframe_seconds: int, pivot_right: int, allowance: float, wick_only: bool = False, exclude_latest: bool = False, exclude_on_break: bool = True) -> list[dict[str, float]]:
    if not exclude_on_break: return levels
    active_mirrors = []
    sorted_candles = sorted(candles, key=lambda c: c["timestamp"])
    if exclude_latest and len(sorted_candles) > 1: sorted_candles = sorted_candles[:-1]
    allowance = max(0.0, float(allowance))
    for lvl in levels:
        lvl_ts = int(lvl["timestamp"])
        fixation_ts = lvl_ts + (pivot_right * timeframe_seconds)
        if direction > 0:
            source_min, _ = level_zone_bounds(lvl, -1, False, wick_only)
            _, mirror_max = level_zone_bounds(lvl, 1, True, wick_only)
            source_break_below = source_min - allowance
            mirror_break_above = mirror_max + allowance
        else:
            _, source_max = level_zone_bounds(lvl, 1, False, wick_only)
            mirror_min, _ = level_zone_bounds(lvl, -1, True, wick_only)
            source_break_above = source_max + allowance
            mirror_break_below = mirror_min - allowance
        break_index = -1
        for i, c in enumerate(sorted_candles):
            if int(c["timestamp"]) <= fixation_ts: continue
            if direction > 0:
                if c["low"] < source_break_below: break_index = i; break
            else:
                if c["high"] > source_break_above: break_index = i; break
        if break_index == -1: continue
        is_re_broken = False
        for c in sorted_candles[break_index + 1:]:
            if direction > 0:
                if c["high"] > mirror_break_above: is_re_broken = True; break
            else:
                if c["low"] < mirror_break_below: is_re_broken = True; break
        if not is_re_broken: active_mirrors.append(lvl)
    return active_mirrors


def time_ranges_overlap(first_start: int, first_end: int, second_start: int, second_end: int) -> bool:
    return first_start < second_end and second_start < first_end


def matching_swing_level_points_cached(state: SymbolState, timeframe_seconds: int, price: float, direction: int, max_distance: float, *, mirror: bool = False, candle: Optional[dict[str, Any]] = None, config: Optional[dict[str, Any]] = None) -> list[dict[str, float]]:
    if direction > 0:
        levels = state.cached_mirror_highs.get(timeframe_seconds, []) if mirror else state.cached_swing_highs.get(timeframe_seconds, [])
    else:
        levels = state.cached_mirror_lows.get(timeframe_seconds, []) if mirror else state.cached_swing_lows.get(timeframe_seconds, [])
    level_settings = (config or GLOBAL_CONFIG).get("LEVEL_SETTINGS", {})
    tf_set = level_settings.get(timeframe_seconds, {})
    is_wick_only = bool(tf_set.get("wick_only", False))
    zone_mode = str(tf_set.get("zone_mode", "body_to_wick")).strip().lower()
    wick_tip_fraction = float(tf_set.get("wick_tip_fraction", 0.25))
    match_mode = (config or GLOBAL_CONFIG).get("LEVEL_MATCH_MODE", "high_low").strip().lower()
    candle_low = None
    candle_high = None
    if candle is not None and match_mode == "candle":
        candle_low = min(float(candle["low"]), float(candle["high"]))
        candle_high = max(float(candle["low"]), float(candle["high"]))
    matched = []
    for level in levels:
        raw_zone_min, raw_zone_max = level_zone_bounds(level, direction, mirror, is_wick_only, zone_mode, wick_tip_fraction)
        zone_min = raw_zone_min - max_distance
        zone_max = raw_zone_max + max_distance
        if candle is not None:
            if match_mode == "close":
                if zone_min <= float(candle["close"]) <= zone_max: matched.append(level)
                continue
            elif match_mode == "high_low":
                if zone_min <= price <= zone_max: matched.append(level)
                continue
            else:
                if candle_low is not None and candle_high is not None:
                    if candle_low <= zone_max and candle_high >= zone_min: matched.append(level)
                continue
        if zone_min <= price <= zone_max: matched.append(level)
    return matched


def level_candles_for_timeframe(state: SymbolState, timeframe_seconds: int) -> Iterable[dict[str, Any]]:
    main_tf = int(GLOBAL_CONFIG.get("TIMEFRAME_SECONDS", 60))
    if timeframe_seconds <= main_tf:
        history = list(state.candle_history)
        live = state.live_candle
        if live is not None and (state.last_closed_candle_start is None or int(live["timestamp"]) > int(state.last_closed_candle_start)):
            history.append(live)
        return history
    return state.level_candle_histories.get(timeframe_seconds, [])


# ================= MATCHING LABELS AND TECHNICAL INDICATORS =================

def compute_value_area(candles: Iterable[dict[str, Any]], lookback: int, num_bins: int = 24, value_area_ratio: float = 0.70) -> Optional[tuple[float, float, float]]:
    hist = list(candles)[-lookback:]
    if len(hist) < 10: return None
    lows = [float(c["low"]) for c in hist]
    highs = [float(c["high"]) for c in hist]
    min_price, max_price = min(lows), max(highs)
    if max_price <= min_price: return None
    bin_size = (max_price - min_price) / num_bins
    bins = [min_price + i * bin_size for i in range(num_bins + 1)]
    counts = [0.0] * num_bins
    for c in hist:
        c_low, c_high, c_open, c_close = float(c["low"]), float(c["high"]), float(c["open"]), float(c["close"])
        c_body_min, c_body_max = min(c_open, c_close), max(c_open, c_close)
        for i in range(num_bins):
            if max(c_low, bins[i]) <= min(c_high, bins[i+1]):
                counts[i] += 1.0
                if max(c_body_min, bins[i]) <= min(c_body_max, bins[i+1]): counts[i] += 1.0
    max_count = max(counts)
    if max_count == 0: return None
    poc_idx = counts.index(max_count)
    poc_price = min_price + (poc_idx + 0.5) * bin_size
    va_indices = {poc_idx}
    va_count = counts[poc_idx]
    target_count = sum(counts) * value_area_ratio
    while va_count < target_count and len(va_indices) < num_bins:
        min_va, max_va = min(va_indices), max(va_indices)
        above_count = counts[max_va + 1] if max_va + 1 < num_bins else 0.0
        below_count = counts[min_va - 1] if min_va - 1 >= 0 else 0.0
        if above_count == 0 and below_count == 0: break
        if above_count >= below_count and max_va + 1 < num_bins:
            va_indices.add(max_va + 1); va_count += counts[max_va + 1]
        elif min_va - 1 >= 0:
            va_indices.add(min_va - 1); va_count += counts[min_va - 1]
    return bins[min(va_indices)], bins[max(va_indices) + 1], poc_price


def level_marker_labels(state: SymbolState, candle: dict[str, Any], direction: int, config: dict[str, Any]) -> list[str]:
    if direction == 0 or state.average_body <= 0: return []
    global_multiplier = config.get("LEVEL_DISTANCE_BODY_MULTIPLIER", 0.05)
    level_settings = config.get("LEVEL_SETTINGS", {})
    found_levels: list[dict[str, Any]] = []
    level_checks = ((1, float(candle["high"])),) if direction > 0 else ((-1, float(candle["low"])),)
    
    for check_direction, price in level_checks:
        for timeframe_seconds in sorted(config["LEVEL_TIMEFRAMES_SECONDS"]):
            tf_set = level_settings.get(timeframe_seconds, {})
            tf_multiplier = tf_set.get("distance_body_multiplier", tf_set.get("LEVEL_DISTANCE_BODY_MULTIPLIER", global_multiplier))
            max_distance = state.cached_level_allowances.get(timeframe_seconds, state.average_body * tf_multiplier)

            matching_levels = matching_swing_level_points_cached(
                state, timeframe_seconds, price, check_direction, max_distance, mirror=False, candle=candle, config=config
            )
            for lvl in matching_levels:
                found_levels.append({"timestamp": int(lvl["timestamp"]), "price": lvl["price"], "timeframe": timeframe_seconds, "mirror": False})

    if not found_levels: filtered_levels = []
    else:
        priority = config.get("LEVEL_TIMEFRAME_PRIORITY", "lower")
        found_levels_sorted = sorted(found_levels, key=lambda x: ((-x["timeframe"] if priority == "higher" else x["timeframe"]), -x["timestamp"]))
        filtered_levels = []
        for lvl in found_levels_sorted:
            is_duplicate = False
            for accepted in filtered_levels:
                if abs(lvl["timestamp"] - accepted["timestamp"]) <= max(lvl["timeframe"], accepted["timeframe"]):
                    is_duplicate = True; break
            if not is_duplicate: filtered_levels.append(lvl)
        filtered_levels.sort(key=lambda x: x["timestamp"], reverse=True)
    detailed = config.get("DETAILED_MARKERS", True)
    markers = []
    for lvl in filtered_levels:
        if detailed:
            tf_label = timeframe_label(lvl["timeframe"])
            lvl_time_str = format_ts(lvl["timestamp"])
            prefix = "ZL" if lvl["mirror"] else "L"
            markers.append(f"{prefix}{tf_label}[{lvl_time_str}]")
    if not detailed:
        # Keep the text signal concise: one label per timeframe.  The chart
        # still renders every matching level zone separately.
        counts_by_tf: dict[int, int] = {}
        for lvl in filtered_levels:
            tf = int(lvl["timeframe"])
            counts_by_tf[tf] = counts_by_tf.get(tf, 0) + 1
        for tf in sorted(counts_by_tf):
            tf_label = timeframe_label(tf).upper()
            markers.append(tf_label)
    if config.get("PRICE_DENSITY_ENABLED", False):
        density_multiplier = config.get("PRICE_DENSITY_DISTANCE_MULTIPLIER", global_multiplier)
        density_max_distance = state.average_body * density_multiplier
        for timeframe_seconds in sorted(config["LEVEL_TIMEFRAMES_SECONDS"]):
            va = compute_value_area(list(level_candles_for_timeframe(state, timeframe_seconds)), lookback=config["HISTORY_PERIOD"], num_bins=config.get("PRICE_DENSITY_BINS", 24), value_area_ratio=config.get("PRICE_DENSITY_VALUE_AREA_RATIO", 0.70))
            if va is not None:
                val_price, vah_price, _ = va
                tf_label = timeframe_label(timeframe_seconds).upper()
                if direction > 0:
                    if vah_price - density_max_distance <= candle["high"] <= vah_price + density_max_distance:
                        lbl = f"VAH{tf_label}" if detailed else "VAH"
                        if lbl not in markers: markers.append(lbl)
                    if val_price - density_max_distance <= candle["high"] <= val_price + density_max_distance:
                        lbl = f"VAL{tf_label}" if detailed else "VAL"
                        if lbl not in markers: markers.append(lbl)
                else:
                    if val_price - density_max_distance <= candle["low"] <= val_price + density_max_distance:
                        lbl = f"VAL{tf_label}" if detailed else "VAL"
                        if lbl not in markers: markers.append(lbl)
                    if vah_price - density_max_distance <= candle["low"] <= vah_price + density_max_distance:
                        lbl = f"VAH{tf_label}" if detailed else "VAH"
                        if lbl not in markers: markers.append(lbl)
    return markers


def matched_signal_levels(state: SymbolState, candle: dict[str, Any], direction: int, config: dict[str, Any]) -> list[dict[str, Any]]:
    """Return the exact swing zones that passed the live LVL check."""
    if direction == 0 or state.average_body <= 0:
        return []
    global_multiplier = config.get("LEVEL_DISTANCE_BODY_MULTIPLIER", 0.05)
    settings = config.get("LEVEL_SETTINGS", {})
    price = float(candle["high"] if direction > 0 else candle["low"])
    found: list[dict[str, Any]] = []
    for timeframe_seconds in sorted(config["LEVEL_TIMEFRAMES_SECONDS"]):
        tf_set = settings.get(timeframe_seconds, {})
        multiplier = tf_set.get("distance_body_multiplier", tf_set.get("LEVEL_DISTANCE_BODY_MULTIPLIER", global_multiplier))
        allowance = state.cached_level_allowances.get(timeframe_seconds, state.average_body * multiplier)
        for level in matching_swing_level_points_cached(
            state, timeframe_seconds, price, direction, allowance, mirror=False, candle=candle, config=config
        ):
            found.append({**level, "timeframe": timeframe_seconds, "direction": direction, "allowance": allowance})

    priority = config.get("LEVEL_TIMEFRAME_PRIORITY", "lower")
    found.sort(key=lambda item: ((-item["timeframe"] if priority == "higher" else item["timeframe"]), -int(item["timestamp"])))
    accepted: list[dict[str, Any]] = []
    for level in found:
        if any(abs(int(level["timestamp"]) - int(other["timestamp"])) <= max(level["timeframe"], other["timeframe"]) for other in accepted):
            continue
        accepted.append(level)
    return accepted


def render_signal_chart(
    state: SymbolState,
    candle: dict[str, Any],
    direction: int,
    levels: list[dict[str, Any]],
    caption: str,
    config: dict[str, Any],
) -> Optional[Path]:
    """Render a self-contained candlestick PNG from accumulated M1 history."""
    if not config.get("TELEGRAM_CHARTS_ENABLED", False) or Image is None or ImageDraw is None:
        return None
    count = int(config.get("TELEGRAM_CHART_CANDLES", 40))
    candles_by_time = {int(item["timestamp"]): normalize_history_candle(item) for item in state.candle_history}
    candles_by_time[int(candle["timestamp"])] = normalize_history_candle(candle)
    candles = [item for _, item in sorted(candles_by_time.items()) if not is_zero_candle(item)][-count:]
    if len(candles) < 2:
        return None

    zone_bounds: list[tuple[dict[str, Any], float, float]] = []
    for level in levels:
        tf_set = config.get("LEVEL_SETTINGS", {}).get(int(level["timeframe"]), {})
        low, high = level_zone_bounds(
            level,
            int(level["direction"]),
            False,
            bool(tf_set.get("wick_only", False)),
            str(tf_set.get("zone_mode", "body_to_wick")).strip().lower(),
            float(tf_set.get("wick_tip_fraction", 0.25)),
        )
        allowance = float(level.get("allowance", 0.0))
        zone_bounds.append((level, low - allowance, high + allowance))

    lows = [float(item["low"]) for item in candles] + [low for _, low, _ in zone_bounds]
    highs = [float(item["high"]) for item in candles] + [high for _, _, high in zone_bounds]
    price_low, price_high = min(lows), max(highs)
    span = max(price_high - price_low, max(abs(price_high), 1.0) * 0.0001)
    price_low -= span * 0.10
    price_high += span * 0.10

    width, height = 960, 560
    left, right, top, bottom = 78, 885, 25, 500
    image = Image.new("RGB", (width, height), "#111827")
    draw = ImageDraw.Draw(image)
    font = ImageFont.load_default() if ImageFont is not None else None

    def y(price: float) -> int:
        return int(bottom - (price - price_low) / (price_high - price_low) * (bottom - top))

    for fraction in range(1, 5):
        gy = top + (bottom - top) * fraction // 5
        draw.line((left, gy, right, gy), fill="#263244", width=1)
        value = price_high - (price_high - price_low) * fraction / 5
        draw.text((right + 8, gy - 6), f"{value:.5g}", fill="#9ca3af", font=font)

    # Muted cold colours keep the candles readable even when zones overlap.
    zone_colors = {60: "#163b57", 300: "#2e2452", 900: "#173f3d", 1800: "#3b2548"}
    for index, (level, zone_low, zone_high) in enumerate(zone_bounds):
        color = zone_colors.get(int(level["timeframe"]), "#94a3b8")
        y1, y2 = y(zone_high), y(zone_low)
        draw.rectangle((left, min(y1, y2), right, max(y1, y2)), fill=color, outline=color, width=1)
        label = timeframe_label(int(level["timeframe"])).upper()
        draw.text((left + 5, min(y1, y2) + 3 + index * 11), label, fill="#e2e8f0", font=font)

    step = (right - left) / len(candles)
    body_width = max(2, int(step * 0.58))
    for index, item in enumerate(candles):
        x = int(left + (index + 0.5) * step)
        open_y, close_y = y(float(item["open"])), y(float(item["close"]))
        high_y, low_y = y(float(item["high"])), y(float(item["low"]))
        up = float(item["close"]) >= float(item["open"])
        color = "#22c55e" if up else "#ef4444"
        draw.line((x, high_y, x, low_y), fill=color, width=1)
        draw.rectangle((x - body_width // 2, min(open_y, close_y), x + body_width // 2, max(open_y, close_y) or min(open_y, close_y) + 1), fill=color)
        if int(item["timestamp"]) == int(candle["timestamp"]):
            marker_left, marker_right = x - body_width // 2 - 2, x + body_width // 2 + 2
            draw.rectangle((min(marker_left, marker_right), min(top, bottom), max(marker_left, marker_right), max(top, bottom)), outline="#f8fafc", width=1)

    draw.text((left, height - 38), caption[:115], fill="#f8fafc", font=font)
    draw.text((left, height - 20), "M1 candles | highlighted candle = live signal", fill="#94a3b8", font=font)
    path = Path(tempfile.gettempdir()) / f"bot1m_signal_{uuid4().hex}.png"
    image.save(path, format="PNG", optimize=True)
    return path


def compute_rsi(candles: Iterable[dict[str, Any]], period: int) -> Optional[float]:
    period = max(1, int(period))
    closes = [candle["close"] for candle in (normalize_history_candle(c) for c in candles) if not is_zero_candle(candle)]
    if len(closes) < period + 1: return None
    deltas = [closes[i] - closes[i - 1] for i in range(1, len(closes))]
    avg_gain = sum(max(d, 0.0) for d in deltas[:period]) / period
    avg_loss = sum(max(-d, 0.0) for d in deltas[:period]) / period
    for d in deltas[period:]:
        avg_gain = ((avg_gain * (period - 1)) + max(d, 0.0)) / period
        avg_loss = ((avg_loss * (period - 1)) + max(-d, 0.0)) / period
    if avg_loss == 0.0: return 100.0 if avg_gain > 0.0 else 50.0
    return 100.0 - (100.0 / (1.0 + (avg_gain / avg_loss)))


def current_rsi_value(state: SymbolState, candle: dict[str, Any], config: dict[str, Any]) -> Optional[float]:
    if not config.get("RSI_ENABLED", False): return None
    period = int(config.get("RSI_PERIOD", 14))
    recent_history = list(state.candle_history)[-(period * 3 + 2):]
    return compute_rsi([*recent_history, normalize_history_candle(candle)], period)


def rsi_extreme_matches(rsi: float, direction: int, config: dict[str, Any], mode: str) -> bool:
    overbought, oversold = float(config.get("RSI_OVERBOUGHT", 70.0)), float(config.get("RSI_OVERSOLD", 30.0))
    if mode == "any_extreme": return rsi >= overbought or rsi <= oversold
    if mode == "overbought": return rsi >= overbought
    if mode == "oversold": return rsi <= oversold
    return rsi >= overbought if direction > 0 else rsi <= oversold if direction < 0 else rsi >= overbought or rsi <= oversold


def rsi_marker_label(state: SymbolState, candle: dict[str, Any], config: dict[str, Any]) -> Optional[str]:
    if not config.get("RSI_INFO_ENABLED", config.get("RSI_ENABLED", False)): return None
    rsi = current_rsi_value(state, candle, config)
    return str(config.get("RSI_LABEL", "RSI")) if rsi is not None and rsi_extreme_matches(rsi, 0, config, "any_extreme") else None


def rsi_filter_allows(state: SymbolState, candle: dict[str, Any], direction: int, config: dict[str, Any]) -> bool:
    if not config.get("RSI_FILTER_ENABLED", False) or not config.get("RSI_ENABLED", False): return True
    rsi = current_rsi_value(state, candle, config)
    if rsi is None: return False
    return rsi_extreme_matches(rsi, direction, config, config_mode(config, "RSI_FILTER_MODE", "directional", {"directional", "any_extreme", "overbought", "oversold"}))


def compute_bollinger_bands(candles: Iterable[dict[str, Any]], period: int, multiplier: float) -> Optional[tuple[float, float, float]]:
    period = max(1, int(period))
    closes = [candle["close"] for candle in (normalize_history_candle(c) for c in candles) if not is_zero_candle(candle)]
    if len(closes) < period: return None
    window = closes[-period:]
    sma = sum(window) / period
    std_dev = (sum((x - sma) ** 2 for x in window) / period) ** 0.5
    return sma + (multiplier * std_dev), sma, sma - (multiplier * std_dev)


def current_bollinger_bands(state: SymbolState, candle: dict[str, Any], config: dict[str, Any]) -> Optional[tuple[float, float, float]]:
    if not config.get("BB_ENABLED", False): return None
    period = int(config.get("BB_PERIOD", 20))
    return compute_bollinger_bands([*list(state.candle_history)[-(period + 2):], normalize_history_candle(candle)], period, float(config.get("BB_MULTIPLIER", 2.0)))


def bollinger_touch_matches(candle: dict[str, Any], bands: tuple[float, float, float], direction: int, mode: str) -> bool:
    upper, _, lower = bands
    curr = normalize_history_candle(candle)
    u_touch, l_touch = curr["high"] >= upper, curr["low"] <= lower
    if mode == "any_touch": return u_touch or l_touch
    if mode == "upper": return u_touch
    if mode == "lower": return l_touch
    return u_touch if direction > 0 else l_touch if direction < 0 else u_touch or l_touch


def bollinger_marker_label(state: SymbolState, candle: dict[str, Any], config: dict[str, Any]) -> Optional[str]:
    if not config.get("BB_INFO_ENABLED", config.get("BB_ENABLED", False)): return None
    bands = current_bollinger_bands(state, candle, config)
    return str(config.get("BB_LABEL", "BB")) if bands is not None and bollinger_touch_matches(candle, bands, 0, "any_touch") else None


def bollinger_filter_allows(state: SymbolState, candle: dict[str, Any], direction: int, config: dict[str, Any]) -> bool:
    if not config.get("BB_FILTER_ENABLED", False) or not config.get("BB_ENABLED", False): return True
    bands = current_bollinger_bands(state, candle, config)
    if bands is None: return False
    return bollinger_touch_matches(candle, bands, direction, config_mode(config, "BB_FILTER_MODE", "directional", {"directional", "any_touch", "upper", "lower"}))


def tokenize_signal_filter_expression(expression: str) -> list[str]:
    tokens = []
    i = 0
    while i < len(expression):
        char = expression[i]
        if char.isspace(): i += 1; continue
        if char in "()": tokens.append(char); i += 1; continue
        if char.isalnum() or char == "_":
            start = i
            while i < len(expression) and (expression[i].isalnum() or expression[i] == "_"): i += 1
            tokens.append(expression[start:i]); continue
        raise ValueError(f"invalid character: {char}")
    return tokens


def evaluate_signal_filter_expression(expression: str, facts: dict[str, bool]) -> bool:
    tokens = tokenize_signal_filter_expression(expression)
    pos = 0
    def peek(): return tokens[pos] if pos < len(tokens) else None
    def consume(): nonlocal pos; p = tokens[pos]; pos += 1; return p
    def parse_or():
        v = parse_and()
        while (peek() or "").lower() == "or": consume(); v = v or parse_and()
        return v
    def parse_and():
        v = parse_not()
        while (peek() or "").lower() == "and": consume(); v = v and parse_not()
        return v
    def parse_not():
        if (peek() or "").lower() == "not": consume(); return not parse_not()
        return parse_primary()
    def parse_primary():
        t = consume()
        if t == "(":
            v = parse_or()
            if peek() != ")": raise ValueError("missing closing ')'")
            consume(); return v
        n = t.upper()
        if n in {"AND", "OR"} or t == ")": raise ValueError(f"unexpected token: {t}")
        if n == "TRUE": return True
        if n == "FALSE": return False
        if n not in SIGNAL_FILTER_FACTS: raise ValueError(f"unknown condition: {t}")
        return bool(facts.get(n, False))
    return parse_or() if tokens else True


def level_marker_has_timeframe(marker: str, timeframe_seconds: int, is_zlevel: bool = False) -> bool:
    tf = timeframe_label(timeframe_seconds).upper()
    if is_zlevel:
        return marker.startswith(f"ZL{tf}[") or marker == "ZLVL"
    else:
        counted_light_marker = marker.endswith(tf) and marker[:-len(tf)].isdigit()
        return marker == tf or counted_light_marker or (marker.startswith(f"L{tf}[") and not marker.startswith("ZL")) or marker == "LVL"


def build_signal_filter_facts(state: SymbolState, candle: dict[str, Any], config: dict[str, Any], level_markers: list[str], order_block_marker: Optional[str]) -> dict[str, bool]:
    has_normal_level = any(level_marker_has_timeframe(marker, tf, is_zlevel=False) for marker in level_markers for tf in config.get("LEVEL_TIMEFRAMES_SECONDS", []))
    has_zlevel = any(m.startswith("ZL") or m == "ZLVL" for m in level_markers)

    facts = {
        "LVL": has_normal_level,
        "ZLVL": has_zlevel,
        "LEVEL": has_normal_level,
        "ZLEVEL": has_zlevel,
        "VAL": any(m.startswith("VAL") for m in level_markers),
        "VAH": any(m.startswith("VAH") for m in level_markers),
        "OB": bool(order_block_marker),
        "ORDER_BLOCK": bool(order_block_marker),
    }
    for tf in config.get("LEVEL_TIMEFRAMES_SECONDS", []):
        tfl = timeframe_label(tf).upper()
        lvl_match = any(level_marker_has_timeframe(m, tf, is_zlevel=False) for m in level_markers)
        zlvl_match = any(level_marker_has_timeframe(m, tf, is_zlevel=True) for m in level_markers)
        facts[f"LVL_{tfl}"] = lvl_match
        facts[f"LEVEL_{tfl}"] = lvl_match
        facts[f"ZLVL_{tfl}"] = zlvl_match
        facts[f"ZLEVEL_{tfl}"] = zlvl_match
        facts[f"VAL_{tfl}"] = any(m == f"VAL{tfl}" for m in level_markers)
        facts[f"VAH_{tfl}"] = any(m == f"VAH{tfl}" for m in level_markers)

    rsi = current_rsi_value(state, candle, config)
    if rsi is not None:
        ob, os = float(config.get("RSI_OVERBOUGHT", 70.0)), float(config.get("RSI_OVERSOLD", 30.0))
        facts["RSI_OVERBOUGHT"] = rsi >= ob
        facts["RSI_OVERSOLD"] = rsi <= os
        facts["RSI"] = facts["RSI_OVERBOUGHT"] or facts["RSI_OVERSOLD"]
    else:
        facts["RSI_OVERBOUGHT"] = facts["RSI_OVERSOLD"] = facts["RSI"] = False

    bands = current_bollinger_bands(state, candle, config)
    if bands is not None:
        upper, _, lower = bands
        curr = normalize_history_candle(candle)
        facts["BB_UPPER"] = curr["high"] >= upper
        facts["BB_LOWER"] = curr["low"] <= lower
        facts["BB"] = facts["BB_UPPER"] or facts["BB_LOWER"]
    else:
        facts["BB_UPPER"] = facts["BB_LOWER"] = facts["BB"] = False

    facts["TRUE"] = True
    facts["FALSE"] = False
    return facts


def validate_signal_filter_expression(config: dict[str, Any]) -> None:
    dummy_facts = {n: False for n in SIGNAL_FILTER_FACTS | {"TRUE", "FALSE"}}
    for p in config.get("STRATEGY_PROFILES", []):
        if not p.get("enabled", True) or not p.get("filter_enabled", False): continue
        expr = str(p.get("filter_expr", "")).strip()
        if expr: evaluate_signal_filter_expression(expr, dummy_facts)
    
    default_max = config.get("MAX_BODY_PERCENT_DEFAULT")
    if isinstance(default_max, dict) and default_max.get("filter_enabled", False):
        expr = str(default_max.get("filter_expr", "")).strip()
        if expr: evaluate_signal_filter_expression(expr, dummy_facts)

    for val in config.get("MAX_BODY_PERCENT_SERIES", {}).values():
        if isinstance(val, dict) and val.get("filter_enabled", False):
            expr = str(val.get("filter_expr", "")).strip()
            if expr: evaluate_signal_filter_expression(expr, dummy_facts)


def check_prev_candle_wick_s(state: SymbolState, config: dict[str, Any]) -> bool:
    if not config.get("WICK_S_ENABLED", False) or not state.candle_history: return False
    prev = state.candle_history[-1]
    po, ph, pl, pc = float(prev["open"]), float(prev["high"]), float(prev["low"]), float(prev["close"])
    pb = abs(pc - po)
    wick = ph - pc if pc >= po else pc - pl
    return (wick / pb) * 100.0 >= float(config.get("WICK_S_THRESHOLD_PERCENT", 50.0)) if pb > 0 else wick > 0


def config_mode(config: dict[str, Any], key: str, fallback: str, allowed: set[str]) -> str:
    m = str(config.get(key, fallback)).strip().lower()
    return m if m in allowed else fallback


def order_block_side_from_candle(block: dict[str, Any]) -> Optional[str]:
    d = candle_direction(block)
    return "bullish" if d < 0 else "bearish" if d > 0 else None


def order_block_reaction_direction(side: str) -> int:
    return 1 if side == "bullish" else -1


def order_block_zone_from_candle(block: dict[str, Any], side: str, zone_mode: str) -> tuple[float, float]:
    o, c, h, l = float(block["open"]), float(block["close"]), float(block["high"]), float(block["low"])
    bl, bh = min(o, c), max(o, c)
    if zone_mode == "body": return bl, bh
    if zone_mode == "wick_to_body": return (l, bh) if side == "bullish" else (bl, h)
    return l, h


def order_block_net_impulse(future: list[dict[str, float]], reaction_direction: int) -> float:
    return sum(candle_body(x) for x in future if candle_direction(x) == reaction_direction) - sum(candle_body(x) for x in future if candle_direction(x) == -reaction_direction)


def order_block_breaks_structure(history: list[dict[str, float]], index: int, future: list[dict[str, float]], side: str, config: dict[str, Any]) -> bool:
    if not config.get("ORDER_BLOCK_BREAK_ENABLED", False): return True
    lookback = max(1, int(config.get("ORDER_BLOCK_BREAK_LOOKBACK", 10)))
    prev = history[max(0, index - lookback):index]
    if not prev or not future: return False
    if side == "bullish": return max(float(x["high"]) for x in future) > max(float(x["high"]) for x in prev)
    return min(float(x["low"]) for x in future) < min(float(x["low"]) for x in prev)


def previous_nonzero_candle_index(history: list[dict[str, float]], before_index: int) -> Optional[int]:
    for i in range(before_index - 1, -1, -1):
        if candle_direction(history[i]) != 0: return i
    return None


def find_order_block_confirmation(history: list[dict[str, float]], block_index: int, impulse_start: int, side: str, reaction_dir: int, block_body: float, min_impulse: float, config: dict[str, Any], allowance: float, block_low: float, block_high: float) -> Optional[tuple[int, int, float]]:
    future = []
    for i in range(impulse_start, len(history)):
        c = history[i]
        if side == "bullish" and float(c["low"]) < block_low - allowance: return None
        if side == "bearish" and float(c["high"]) > block_high + allowance: return None
        future.append(c)
        net = order_block_net_impulse(future, reaction_dir)
        if net >= block_body and net >= min_impulse and order_block_breaks_structure(history, block_index, future, side, config):
            return i, len(future), net
    return None


def order_block_is_mitigated_by_candle(block: dict[str, Any], candle: dict[str, Any], mitigation_mode: str, allowance: float) -> bool:
    low, high, side = float(block["low"]), float(block["high"]), str(block["side"])
    cl, ch, cc = float(candle["low"]), float(candle["high"]), float(candle["close"])
    if mitigation_mode == "close_through": return cc <= low - allowance if side == "bullish" else cc >= high + allowance
    if mitigation_mode == "middle":
        mid = (low + high) / 2.0
        return cl <= mid + allowance and ch >= low - allowance if side == "bullish" else ch >= mid - allowance and cl <= high + allowance
    return zones_overlap(cl, ch, low - allowance, high + allowance)


def order_block_has_left_zone(block: dict[str, Any], candle: dict[str, Any], allowance: float) -> bool:
    low, high, side = float(block["low"]), float(block["high"]), str(block["side"])
    cc = float(candle["close"])
    return cc > high + allowance if side == "bullish" else cc < low - allowance


def add_active_order_block(active_blocks: list[dict[str, Any]], block: dict[str, Any], allowance: float) -> None:
    low, high, side = float(block["low"]), float(block["high"]), str(block["side"])
    for i, active in enumerate(active_blocks):
        if str(active["side"]) == side and zones_overlap(low - allowance, high + allowance, float(active["low"]) - allowance, float(active["high"]) + allowance):
            if int(block["timestamp"]) >= int(active["timestamp"]): active_blocks[i] = block
            return
    active_blocks.append(block)


# ================= RECOMPUTE RECENT STATES & CACHE =================

def _merge_recent_higher_timeframe_tail(
    state: SymbolState,
    timeframe_seconds: int,
    main_history: list[dict[str, float]],
    main_timeframe_seconds: int,
) -> None:
    """Replace only the two latest higher-TF buckets from the M1 tail.

    The older part was obtained at startup and never changes.  Rebuilding the
    complete M5/M15/M30 history after every history(1) reply was the main
    unnecessary cost in the previous implementation.
    """
    if not main_history:
        return
    candles_per_bucket = max(1, math.ceil(timeframe_seconds / main_timeframe_seconds))
    source_tail = main_history[-(candles_per_bucket * 2 + 1):]
    rebuilt_tail = aggregate_history_to_timeframe(source_tail, timeframe_seconds, exclude_latest_bucket=False)
    if not rebuilt_tail:
        return
    replace_from = int(rebuilt_tail[0]["timestamp"])
    existing = state.level_candle_histories.get(timeframe_seconds, [])
    preserved = [candle for candle in existing if int(candle["timestamp"]) < replace_from]
    merged = {int(candle["timestamp"]): candle for candle in preserved}
    merged.update({int(candle["timestamp"]): candle for candle in rebuilt_tail})
    state.level_candle_histories[timeframe_seconds] = [merged[key] for key in sorted(merged)]


def _level_is_broken_by_candle(
    level: dict[str, float],
    candle: dict[str, float],
    direction: int,
    timeframe_seconds: int,
    pivot_right: int,
    allowance: float,
    wick_only: bool,
    zone_mode: str,
    wick_tip_fraction: float,
) -> bool:
    """The same break rule as filter_active_levels, for one new candle."""
    if int(candle["timestamp"]) <= int(level["timestamp"]) + pivot_right * timeframe_seconds:
        return False
    zone_min, zone_max = level_zone_bounds(level, direction, False, wick_only, zone_mode, wick_tip_fraction)
    return float(candle["high"]) > zone_max + allowance if direction > 0 else float(candle["low"]) < zone_min - allowance


def _candidate_is_swing(
    candles: list[dict[str, float]],
    index: int,
    pivot_left: int,
    pivot_right: int,
    use_body: bool,
    direction: int,
) -> bool:
    if index < pivot_left or index + pivot_right >= len(candles):
        return False
    def price(bar: dict[str, float]) -> float:
        if use_body:
            return max(float(bar["open"]), float(bar["close"])) if direction > 0 else min(float(bar["open"]), float(bar["close"]))
        return float(bar["high"]) if direction > 0 else float(bar["low"])
    value = price(candles[index])
    left = candles[index - pivot_left:index]
    right = candles[index + 1:index + pivot_right + 1]
    if direction > 0:
        return all(value > price(item) for item in left) and all(value >= price(item) for item in right)
    return all(value < price(item) for item in left) and all(value <= price(item) for item in right)


def _make_level_from_candle(candle: dict[str, float], direction: int, use_body: bool) -> dict[str, float]:
    price = (max(float(candle["open"]), float(candle["close"])) if direction > 0 else min(float(candle["open"]), float(candle["close"]))) if use_body else (float(candle["high"]) if direction > 0 else float(candle["low"]))
    return {"timestamp": candle["timestamp"], "price": price, "open": candle["open"], "close": candle["close"], "high": candle["high"], "low": candle["low"]}


def _closed_candles_for_timeframe(state: SymbolState, timeframe_seconds: int, main_timeframe_seconds: int) -> list[dict[str, float]]:
    """Return only bars whose full interval has already closed."""
    if state.last_closed_candle_start is None:
        return []
    closed_until = int(state.last_closed_candle_start) + main_timeframe_seconds
    return [candle for candle in level_candles_for_timeframe(state, timeframe_seconds)
            if int(candle["timestamp"]) + timeframe_seconds <= closed_until]


def refresh_level_cache_incrementally(state: SymbolState, config: dict[str, Any], *, add_confirmed_levels: bool) -> None:
    """Update cached levels from one new M1 snapshot; never rescan old history.

    Existing levels are removed only when the new completed/live bar breaks
    them.  A new level is appended only after all its right-side pivot bars are
    closed.  This keeps the original level logic while separating cheap live
    updates from the one-time startup calculation.
    """
    main_tf = int(config.get("TIMEFRAME_SECONDS", 60))
    current_main_history = list(level_candles_for_timeframe(state, main_tf))
    level_settings = config.get("LEVEL_SETTINGS", {})
    global_multiplier = float(config.get("LEVEL_DISTANCE_BODY_MULTIPLIER", 0.05))

    if config.get("LEVEL_UPDATE_HIGHER_TF", True):
        for timeframe_seconds in config["LEVEL_TIMEFRAMES_SECONDS"]:
            if timeframe_seconds > main_tf:
                _merge_recent_higher_timeframe_tail(state, timeframe_seconds, current_main_history, main_tf)

    for timeframe_seconds in config["LEVEL_TIMEFRAMES_SECONDS"]:
        tf_settings = level_settings.get(timeframe_seconds, {
            "pivot_left": config.get("LEVEL_PIVOT_LEFT", 2),
            "pivot_right": config.get("LEVEL_PIVOT_RIGHT", 2),
            "use_body": False,
        })
        pivot_left = max(1, int(tf_settings["pivot_left"]))
        pivot_right = max(1, int(tf_settings["pivot_right"]))
        use_body = bool(tf_settings.get("use_body", False))
        wick_only = bool(tf_settings.get("wick_only", False))
        zone_mode = str(tf_settings.get("zone_mode", "body_to_wick")).strip().lower()
        wick_tip_fraction = float(tf_settings.get("wick_tip_fraction", 0.25))
        tf_candles = list(level_candles_for_timeframe(state, timeframe_seconds))
        if not tf_candles:
            continue

        # The allowance is based on closed bars, so it needs updating only
        # after an actual M1 close.  Live ticks reuse the last stable value.
        if add_confirmed_levels or timeframe_seconds not in state.cached_level_allowances:
            tf_multiplier = float(tf_settings.get("distance_body_multiplier", tf_settings.get("LEVEL_DISTANCE_BODY_MULTIPLIER", global_multiplier)))
            reference_period = int(tf_settings.get("allowance_body_period", config.get("LEVEL_ALLOWANCE_BODY_PERIOD", 50)))
            typical_body = typical_level_body(tf_candles[:-1], reference_period)
            state.cached_level_allowances[timeframe_seconds] = typical_body * tf_multiplier if typical_body > 0 else state.average_body * tf_multiplier
        allowance = state.cached_level_allowances.get(timeframe_seconds, state.average_body * global_multiplier)

        # Only the newest bar can change the active/inactive status of an old
        # level.  Do not re-check every historical candle against every level.
        latest = tf_candles[-1]
        latest_is_live = int(latest["timestamp"]) + timeframe_seconds > (int(state.last_closed_candle_start or 0) + main_tf)
        if not (latest_is_live and config.get("LEVEL_EXCLUDE_LATEST_CANDLE_FROM_FILTER", True)) and config.get("LEVEL_EXCLUDE_ON_BREAK", True):
            state.cached_swing_highs[timeframe_seconds] = [
                level for level in state.cached_swing_highs.get(timeframe_seconds, [])
                if not _level_is_broken_by_candle(level, latest, 1, timeframe_seconds, pivot_right, allowance, wick_only, zone_mode, wick_tip_fraction)
            ]
            state.cached_swing_lows[timeframe_seconds] = [
                level for level in state.cached_swing_lows.get(timeframe_seconds, [])
                if not _level_is_broken_by_candle(level, latest, -1, timeframe_seconds, pivot_right, allowance, wick_only, zone_mode, wick_tip_fraction)
            ]

        if not add_confirmed_levels:
            continue
        closed_candles = _closed_candles_for_timeframe(state, timeframe_seconds, main_tf)
        candidate_index = len(closed_candles) - pivot_right - 1
        if candidate_index < pivot_left:
            continue
        candidate = closed_candles[candidate_index]
        candidate_timestamp = int(candidate["timestamp"])
        seen = state.checked_level_candidates.setdefault(timeframe_seconds, set())
        if candidate_timestamp in seen:
            continue
        seen.add(candidate_timestamp)
        limit_minutes = int(config.get("LEVEL_HISTORY_LIMIT_MINUTES", 0))
        if limit_minutes and candidate_timestamp < int(closed_candles[-1]["timestamp"]) - limit_minutes * 60:
            continue
        for direction, active_levels in ((1, state.cached_swing_highs), (-1, state.cached_swing_lows)):
            if not _candidate_is_swing(closed_candles, candidate_index, pivot_left, pivot_right, use_body, direction):
                continue
            level = _make_level_from_candle(candidate, direction, use_body)
            # A fresh pivot can be invalidated by bars that arrived while its
            # right side was being confirmed.  This one local check replaces a
            # full historical filter of every level on every scan.
            observation = tf_candles if not config.get("LEVEL_EXCLUDE_LATEST_CANDLE_FROM_FILTER", True) else closed_candles
            if config.get("LEVEL_EXCLUDE_ON_BREAK", True) and any(
                _level_is_broken_by_candle(level, bar, direction, timeframe_seconds, pivot_right, allowance, wick_only, zone_mode, wick_tip_fraction)
                for bar in observation
            ):
                continue
            existing = active_levels.setdefault(timeframe_seconds, [])
            if not any(int(item["timestamp"]) == candidate_timestamp for item in existing):
                existing.append(level)

    # Order blocks use a separate all-history algorithm.  They are disabled in
    # the light profile; retain the old exact behaviour if explicitly enabled.
    if config.get("ORDER_BLOCK_ENABLED", False):
        recompute_order_block_cache(state, config)


def recompute_order_block_cache(state: SymbolState, config: dict[str, Any]) -> None:
    """Full order-block rebuild, used only when that optional feature is on."""
    state.cached_order_blocks = []
    if state.average_body <= 0:
        return
    main_tf = int(config.get("TIMEFRAME_SECONDS", 60))
    global_multiplier = config.get("LEVEL_DISTANCE_BODY_MULTIPLIER", 0.05)
    history = [normalize_history_candle(x) for x in state.candle_history if not is_zero_candle(normalize_history_candle(x))]
    if len(history) < 3:
        return
    impulse_threshold = state.average_body * config["ORDER_BLOCK_IMPULSE_MULTIPLIER"]
    lookahead = max(1, int(config["ORDER_BLOCK_LOOKAHEAD"]))
    slow_impulse_threshold = state.average_body * config["SLOW_ORDER_BLOCK_IMPULSE_MULTIPLIER"]
    zone_mode = config_mode(config, "ORDER_BLOCK_ZONE_MODE", "wick_to_body", {"full", "body", "wick_to_body"})
    mitigation_mode = config_mode(config, "ORDER_BLOCK_MITIGATION_MODE", "touch", {"touch", "middle", "close_through"})
    raw_order_blocks = []
    min_impulse = min(impulse_threshold, slow_impulse_threshold)
    global_allowance = state.average_body * global_multiplier
    for impulse_start in range(1, len(history)):
        reaction_dir = candle_direction(history[impulse_start])
        if reaction_dir == 0:
            continue
        block_idx = previous_nonzero_candle_index(history, impulse_start)
        if block_idx is None:
            continue
        block = history[block_idx]
        if candle_direction(block) != -reaction_dir:
            continue
        side = order_block_side_from_candle(block)
        if side is None or order_block_reaction_direction(side) != reaction_dir:
            continue
        block_body = candle_body(block)
        block_low, block_high = order_block_zone_from_candle(block, side, zone_mode)
        confirmation = find_order_block_confirmation(history, block_idx, impulse_start, side, reaction_dir, block_body, min_impulse, config, global_allowance, block_low, block_high)
        if confirmation is None:
            continue
        conf_idx, conf_candles, conf_impulse = confirmation
        raw_order_blocks.append({"timestamp": int(block["timestamp"]), "low": block_low, "high": block_high, "side": side, "reaction_direction": reaction_dir, "is_fast": conf_candles <= lookahead and conf_impulse >= impulse_threshold, "is_slow": not (conf_candles <= lookahead and conf_impulse >= impulse_threshold), "confirmation_timestamp": int(history[conf_idx]["timestamp"]), "confirmation_candles": conf_candles, "timeframe": main_tf})
    limit_minutes = config.get("LEVEL_HISTORY_LIMIT_MINUTES", 0)
    if limit_minutes > 0 and history:
        cutoff = int(history[-1]["timestamp"]) - (limit_minutes * 60)
        raw_order_blocks = [ob for ob in raw_order_blocks if ob["timestamp"] >= cutoff]
    for ob in raw_order_blocks:
        fix_ts = int(ob.get("confirmation_timestamp", ob["timestamp"] + main_tf))
        has_left = False
        is_mitigated = False
        for c in history:
            if int(c["timestamp"]) <= fix_ts:
                continue
            if not has_left:
                if order_block_has_left_zone(ob, c, global_allowance):
                    has_left = True
                else:
                    continue
            if order_block_is_mitigated_by_candle(ob, c, mitigation_mode, global_allowance):
                is_mitigated = True
                break
        if has_left and not is_mitigated:
            add_active_order_block(state.cached_order_blocks, ob, global_allowance)

def recompute_state_cache(state: SymbolState, config: dict[str, Any]) -> None:
    level_settings = config.get("LEVEL_SETTINGS", {})
    global_multiplier = config.get("LEVEL_DISTANCE_BODY_MULTIPLIER", 0.05)
    state.cached_swing_highs = {}
    state.cached_swing_lows = {}
    state.cached_level_allowances = {}
    state.cached_mirror_highs = {}
    state.cached_mirror_lows = {}
    main_tf = int(config.get("TIMEFRAME_SECONDS", 60))
    current_main_history = list(level_candles_for_timeframe(state, main_tf))

    for timeframe_seconds in config["LEVEL_TIMEFRAMES_SECONDS"]:
        if config.get("LEVEL_UPDATE_HIGHER_TF", True) and timeframe_seconds > main_tf:
            state.level_candle_histories[timeframe_seconds] = build_complete_higher_tf_history(
                state.raw_level_histories.get(timeframe_seconds, []), current_main_history, timeframe_seconds
            )
        tf_settings = level_settings.get(timeframe_seconds, {"pivot_left": config.get("LEVEL_PIVOT_LEFT", 2), "pivot_right": config.get("LEVEL_PIVOT_RIGHT", 2), "use_body": False})
        p_left, p_right, use_body, wick_only = tf_settings["pivot_left"], tf_settings["pivot_right"], bool(tf_settings.get("use_body", False)), bool(tf_settings.get("wick_only", False))
        
        tf_candles = list(level_candles_for_timeframe(state, timeframe_seconds))
        tf_multiplier = tf_settings.get("distance_body_multiplier", tf_settings.get("LEVEL_DISTANCE_BODY_MULTIPLIER", global_multiplier))
        reference_period = int(tf_settings.get("allowance_body_period", config.get("LEVEL_ALLOWANCE_BODY_PERIOD", 50)))
        # Последняя свеча каждого ТФ может быть незакрыта; допуск берём только из завершённых свечей.
        typical_body = typical_level_body(tf_candles[:-1], reference_period)
        allowance = typical_body * tf_multiplier if typical_body > 0 else state.average_body * tf_multiplier
        state.cached_level_allowances[timeframe_seconds] = allowance
        raw_highs, raw_lows = find_swing_level_points(tf_candles, lookback=0, pivot_left=p_left, pivot_right=p_right, use_body=use_body)
        limit_minutes = config.get("LEVEL_HISTORY_LIMIT_MINUTES", 0)
        if limit_minutes > 0 and tf_candles:
            cutoff = int(tf_candles[-1]["timestamp"]) - (limit_minutes * 60)
            raw_highs = [rh for rh in raw_highs if int(rh["timestamp"]) >= cutoff]
            raw_lows = [rl for rl in raw_lows if int(rl["timestamp"]) >= cutoff]
        exclude_latest = config.get("LEVEL_EXCLUDE_LATEST_CANDLE_FROM_FILTER", True)
        exclude_on_break = config.get("LEVEL_EXCLUDE_ON_BREAK", True)
        zone_mode = str(tf_settings.get("zone_mode", "body_to_wick")).strip().lower()
        wick_tip_fraction = float(tf_settings.get("wick_tip_fraction", 0.25))
        state.cached_swing_highs[timeframe_seconds] = filter_active_levels(raw_highs, tf_candles, 1, timeframe_seconds, p_right, allowance, wick_only, zone_mode, wick_tip_fraction, exclude_latest=exclude_latest, exclude_on_break=exclude_on_break)
        state.cached_swing_lows[timeframe_seconds] = filter_active_levels(raw_lows, tf_candles, -1, timeframe_seconds, p_right, allowance, wick_only, zone_mode, wick_tip_fraction, exclude_latest=exclude_latest, exclude_on_break=exclude_on_break)
        state.cached_mirror_highs[timeframe_seconds] = []
        state.cached_mirror_lows[timeframe_seconds] = []
    
    state.cached_order_blocks = []
    if not config.get("ORDER_BLOCK_ENABLED", False) or state.average_body <= 0: return
    history = [normalize_history_candle(x) for x in state.candle_history if not is_zero_candle(normalize_history_candle(x))]
    if len(history) < 3: return
    impulse_threshold = state.average_body * config["ORDER_BLOCK_IMPULSE_MULTIPLIER"]
    lookahead = max(1, int(config["ORDER_BLOCK_LOOKAHEAD"]))
    slow_impulse_threshold = state.average_body * config["SLOW_ORDER_BLOCK_IMPULSE_MULTIPLIER"]
    zone_mode = config_mode(config, "ORDER_BLOCK_ZONE_MODE", "wick_to_body", {"full", "body", "wick_to_body"})
    mitigation_mode = config_mode(config, "ORDER_BLOCK_MITIGATION_MODE", "touch", {"touch", "middle", "close_through"})
    raw_order_blocks = []
    min_impulse = min(impulse_threshold, slow_impulse_threshold)
    global_allowance = state.average_body * global_multiplier

    for impulse_start in range(1, len(history)):
        reaction_dir = candle_direction(history[impulse_start])
        if reaction_dir == 0: continue
        block_idx = previous_nonzero_candle_index(history, impulse_start)
        if block_idx is None: continue
        block = history[block_idx]
        if candle_direction(block) != -reaction_dir: continue
        side = order_block_side_from_candle(block)
        if side is None or order_block_reaction_direction(side) != reaction_dir: continue
        block_body = candle_body(block)
        block_low, block_high = order_block_zone_from_candle(block, side, zone_mode)
        confirmation = find_order_block_confirmation(history, block_idx, impulse_start, side, reaction_dir, block_body, min_impulse, config, global_allowance, block_low, block_high)
        if confirmation is None: continue
        conf_idx, conf_candles, conf_impulse = confirmation
        raw_order_blocks.append({
            "timestamp": int(block["timestamp"]), "low": block_low, "high": block_high, "side": side, "reaction_direction": reaction_dir,
            "is_fast": conf_candles <= lookahead and conf_impulse >= impulse_threshold,
            "is_slow": not (conf_candles <= lookahead and conf_impulse >= impulse_threshold),
            "confirmation_timestamp": int(history[conf_idx]["timestamp"]), "confirmation_candles": conf_candles, "timeframe": main_tf
        })
    limit_minutes = config.get("LEVEL_HISTORY_LIMIT_MINUTES", 0)
    if limit_minutes > 0 and history:
        cutoff = int(history[-1]["timestamp"]) - (limit_minutes * 60)
        raw_order_blocks = [ob for ob in raw_order_blocks if ob["timestamp"] >= cutoff]
    for ob in raw_order_blocks:
        fix_ts = int(ob.get("confirmation_timestamp", ob["timestamp"] + main_tf))
        has_left = False
        is_mitigated = False
        for c in history:
            if int(c["timestamp"]) <= fix_ts: continue
            if not has_left:
                if order_block_has_left_zone(ob, c, global_allowance): has_left = True
                else: continue
            if order_block_is_mitigated_by_candle(ob, c, mitigation_mode, global_allowance): is_mitigated = True; break
        if has_left and not is_mitigated: add_active_order_block(state.cached_order_blocks, ob, global_allowance)


def zones_overlap(first_low: float, first_high: float, second_low: float, second_high: float) -> bool:
    return max(first_low, second_low) <= min(first_high, second_high)


def order_block_marker_label(state: SymbolState, candle: dict[str, Any], direction: int, config: dict[str, Any]) -> Optional[str]:
    if not config.get("ORDER_BLOCK_ENABLED", False) or state.average_body <= 0 or direction == 0: return None
    curr = normalize_history_candle(candle)
    current_low, current_high = min(curr["open"], curr["close"], curr["low"]), max(curr["open"], curr["close"], curr["high"])
    matched_ranges = []; slow_matched_ranges = []
    allowance = state.average_body * config.get("LEVEL_DISTANCE_BODY_MULTIPLIER", 0.05)
    for block in state.cached_order_blocks:
        reaction_dir = int(block.get("reaction_direction", 0))
        if reaction_dir and direction != -reaction_dir: continue
        if not zones_overlap(current_low, current_high, block["low"] - allowance, block["high"] + allowance): continue
        block_start = block["timestamp"]
        block_end = block_start + int(block.get("timeframe", config.get("TIMEFRAME_SECONDS", 60)))
        time_str = format_ts(block_start)
        target = matched_ranges if block["is_fast"] else slow_matched_ranges
        if any(time_ranges_overlap(block_start, block_end, s, e) for s, e, _ in target): continue
        target.append((block_start, block_end, time_str))
    detailed = config.get("DETAILED_MARKERS", True)
    if not detailed:
        if matched_ranges or slow_matched_ranges:
            return "OB"
        return None
    markers = []
    for _, _, t in matched_ranges: markers.append(f"OB[{t}]")
    for _, _, t in slow_matched_ranges: markers.append(f"SOB[{t}]")
    return " ".join(markers) if markers else None


# ================= WORKER / CLIENT UTILS =================

def compute_worker_cycle_timeout(states_count: int, config: dict[str, Any]) -> float:
    configured = float(config.get("WORKER_CYCLE_TIMEOUT", 0))
    if configured > 0: return configured
    batch_size = max(1, int(config["REQUEST_BATCH_SIZE"]))
    batches_count = max(1, (states_count + batch_size - 1) // batch_size)
    return min((batches_count * (float(config["HISTORY_TIMEOUT"]) + 1.0)) + 2.0, 60.0)


async def create_client(ssid: str, config: Optional[dict[str, Any]] = None):
    if config and not await check_ip_allowed(config):
        raise RuntimeError("IP address filter IP address did not pass.")
    if PocketOptionAsync is None:
        raise ImportError("BinaryOptionsToolsV2 package is required")
    client = PocketOptionAsync(ssid)
    await client.wait_for_assets()
    return client


async def close_client(client) -> None:
    for method in ("close", "disconnect"):
        m = getattr(client, method, None)
        if m is not None:
            try:
                res = m()
                if asyncio.iscoroutine(res): await res
            except Exception: pass
            return


# ================= HISTORY FETCH =================

async def fetch_history_period(client, symbol: str, period: int, config: dict[str, Any]) -> list[dict[str, Any]]:
    if not await check_ip_allowed(config):
        return []
    candles = await asyncio.wait_for(client.history(symbol, period), timeout=config["HISTORY_TIMEOUT"])
    return normalize_and_sort_history(candles)


async def fetch_source_history(client, symbol: str, config: dict[str, Any]) -> list[dict[str, Any]]:
    if not await check_ip_allowed(config):
        return []
    return await fetch_history_period(client, symbol, config["HISTORY_SOURCE_PERIOD"], config)


async def fetch_timeframe_candle_timed(client, symbol: str, config: dict[str, Any]) -> HistoryFetchResult:
    """Receive raw history(1) only; all candle calculations run downstream."""
    started_at = time.perf_counter()
    if not await check_ip_allowed(config):
        return HistoryFetchResult(candle=None, elapsed=time.perf_counter() - started_at)
    try:
        raw_candles = list(await asyncio.wait_for(
            client.history(symbol, config["HISTORY_SOURCE_PERIOD"]), timeout=config["HISTORY_TIMEOUT"]
        ))
        return HistoryFetchResult(
            candle=None,
            elapsed=time.perf_counter() - started_at,
            raw_count=len(raw_candles),
            # Normalisation, timestamp extraction and all timeframe building
            # intentionally belong to the background analysis worker.
            raw_candles=raw_candles,
        )
    except Exception as exc:
        return HistoryFetchResult(candle=None, elapsed=time.perf_counter() - started_at, error=exc)


async def collect_worker_history(client, states: list[SymbolState], config: dict[str, Any]) -> dict[str, HistoryFetchResult]:
    """Network-only collector.  It never touches levels or signal logic."""
    collected: dict[str, HistoryFetchResult] = {}
    for batch in chunked([state.symbol for state in states], config["REQUEST_BATCH_SIZE"]):
        results = await asyncio.gather(*(fetch_timeframe_candle_timed(client, symbol, config) for symbol in batch))
        collected.update(zip(batch, results))
    return collected


async def get_current_payout(client, symbol: str, config: Optional[dict[str, Any]] = None) -> Optional[int]:
    if config and not await check_ip_allowed(config):
        return None
    try: payout = await client.payout(symbol)
    except Exception: return None
    if payout is None: return None
    try: return int(payout)
    except (TypeError, ValueError): return None


# ================= INITIALIZE SYMBOLS =================

async def initialize_symbol_state(client, symbol: str, config: dict[str, Any], name_map: dict[str, str]):
    if not await check_ip_allowed(config):
        return None, "IP address filter IP address did not pass."
    pairs_map = config.get("PAIRS_MAP", {})
    mapped_name = pairs_map.get(symbol, name_map.get(symbol, symbol))
    init_candles = await fetch_history_period(client, symbol, config["HISTORY_INIT_PERIOD"], config)
    if not init_candles: return None, f"{mapped_name} - empty init history"
    try: source_candles = await fetch_source_history(client, symbol, config)
    except Exception: source_candles = []
    timeframe_candles = merge_initial_timeframe_history(init_candles, source_candles, config["TIMEFRAME_SECONDS"])
    level_candles = normalize_and_sort_history(timeframe_candles)
    source_timeframe_candles = aggregate_history_to_timeframe(source_candles, config["TIMEFRAME_SECONDS"], exclude_latest_bucket=False)
    live_candle = source_timeframe_candles[-1] if source_timeframe_candles else None
    if live_candle is not None and level_candles and int(live_candle["timestamp"]) <= int(level_candles[-1]["timestamp"]):
        live_candle = None
    impulse_candles = level_candles[-config["HISTORY_PERIOD"]:]
    if not level_candles: return None, f"{mapped_name} - no aggregated history"
    main_tf = int(config.get("TIMEFRAME_SECONDS", 60))
    raw_level_histories = {}
    for tf in sorted(config["LEVEL_TIMEFRAMES_SECONDS"]):
        if tf <= main_tf: continue
        try: raw_level_histories[tf] = await fetch_history_period(client, symbol, tf, config)
        except Exception: raw_level_histories[tf] = []
    level_candle_histories = {}
    initial_main_level_history = [*level_candles, *([live_candle] if live_candle is not None else [])]
    for tf in sorted(config["LEVEL_TIMEFRAMES_SECONDS"]):
        if tf <= main_tf: continue
        level_candle_histories[tf] = build_complete_higher_tf_history(raw_level_histories.get(tf, []), initial_main_level_history, tf)
    body_history = deque((candle_body(c) for c in impulse_candles), maxlen=config["HISTORY_PERIOD"])
    active_profiles = [p for p in config.get("STRATEGY_PROFILES", []) if p.get("enabled", True)]
    all_keys = []
    for p in active_profiles: all_keys.extend(p.get("candle_multipliers", {}).keys())
    max_candles_count = max(8, max(all_keys) if all_keys else 8)
    state = SymbolState(
        symbol=symbol, display_name=short_display_name(name_map.get(symbol, symbol), symbol, pairs_map), candles_count=len(impulse_candles),
        average_body=compute_trimmed_mean_body_values(body_history, config["TRIM_LOW_COUNT"], config["TRIM_HIGH_COUNT"]), max_body=compute_max_body(impulse_candles),
        candle_history=deque(level_candles), level_candle_histories=level_candle_histories, body_history=body_history,
        recent_bodies=deque((candle_body(c) for c in impulse_candles[-max_candles_count:]), maxlen=max_candles_count),
        recent_directions=deque((candle_direction(c) for c in impulse_candles[-max_candles_count:]), maxlen=max_candles_count),
        recent_is_intermediate=deque((False for _ in impulse_candles[-max_candles_count:]), maxlen=max_candles_count),
        last_closed_candle_start=int(level_candles[-1]["timestamp"]), live_candle=live_candle, raw_level_histories=raw_level_histories,
    )
    recompute_state_cache(state, config)
    return state, None


async def initialize_worker_states(client, symbols: list[str], config: dict[str, Any], name_map: dict[str, str]):
    states = []; skipped = 0; skipped_reasons = []; pairs_map = config.get("PAIRS_MAP", {})
    if not await check_ip_allowed(config):
        return [], len(symbols), ["IP address filter IP address did not pass."]
    for batch in chunked(symbols, config["REQUEST_BATCH_SIZE"]):
        results = await asyncio.gather(*[initialize_symbol_state(client, s, config, name_map) for s in batch], return_exceptions=True)
        for symbol, result in zip(batch, results):
            mapped_name = pairs_map.get(symbol, name_map.get(symbol, symbol))
            if isinstance(result, Exception):
                skipped += 1; skipped_reasons.append(f"{mapped_name} - init error: {result}"); continue
            state, reason = result
            if state is None: skipped += 1; skipped_reasons.append(reason or f"{mapped_name} - skipped"); continue
            states.append(state)
    return states, skipped, skipped_reasons


# ================= SIGNAL DETECTION WITH PRIORITY DEDUPLICATION =================

def sort_signal_messages(messages: list[SignalMessage]) -> list[SignalMessage]:
    def sort_key(message: SignalMessage) -> tuple[int, float]:
        parts = message.text.split()
        for index, part in enumerate(parts):
            signal_type = None
            if part in SIGNAL_OUTPUT_PRIORITY: signal_type = part
            elif part.startswith("MAX") and part[3:].isdigit(): signal_type = part
            else:
                for candidate_type, label in SIGNAL_OUTPUT_LABELS.items():
                    if part == label: signal_type = candidate_type; break
            if signal_type is None: continue
            signal_value = 0.0
            if index + 1 < len(parts):
                raw_value = parts[index + 1].rstrip("x%")
                if "[" in raw_value: raw_value = raw_value.split("[", 1)[0]
                try: signal_value = float(raw_value)
                except ValueError: pass
            return SIGNAL_OUTPUT_PRIORITY.get(signal_type, 0), -signal_value
        return len(SIGNAL_OUTPUT_PRIORITY), 0.0
    return sorted(messages, key=sort_key)


def detect_signals(
    state: SymbolState,
    body: float,
    config: dict[str, Any],
    recent_bodies: Optional[list[float]] = None,
    recent_directions: Optional[list[int]] = None,
    filter_facts: Optional[dict[str, bool]] = None,
    include_all_profile_matches: bool = False,
) -> list[tuple[str, float]]:
    if state.average_body == 0: return []
    if recent_bodies is None: recent_bodies = list(state.recent_bodies)
    if recent_directions is None: recent_directions = list(state.recent_directions)
    found_signals = []
    has_max_signal = False
    current_dir = recent_directions[-1] if recent_directions else 0
    if current_dir != 0:
        same_color_count = 0
        for d in reversed(recent_directions):
            if d == current_dir: same_color_count += 1
            else: break
        max_series_config = config.get("MAX_BODY_PERCENT_SERIES", {})
        for N in range(1, same_color_count + 1):
            if N - 1 <= len(state.candle_history):
                curr_bodies = [candle_body(c) for c in list(state.candle_history)[-(N-1):]] if N > 1 else []
                curr_bodies.append(body)
                S_curr = sum(curr_bodies)
                history_candles = list(state.candle_history)
                if N > 1: history_candles = history_candles[:-(N-1)]
                history_candles = history_candles[-config["HISTORY_PERIOD"]:]
                is_max_series_valid = True
                target_ref_ts = None
                target_ratio = 0.0
                for k in range(1, N + 1):
                    val_k = max_series_config.get(k, config.get("MAX_BODY_PERCENT_DEFAULT", 100.0))
                    percent_limit_k = val_k.get("percent", 100.0) if isinstance(val_k, dict) else val_k
                    max_prev_sum_k = 0.0
                    ref_ts_k = None
                    num_hist = len(history_candles)
                    if num_hist >= k:
                        for i in range(num_hist - k + 1):
                            sub = history_candles[i : i + k]
                            first_sub_dir = candle_direction(sub[0])
                            if first_sub_dir != 0 and all(candle_direction(c) == first_sub_dir for c in sub):
                                sub_sum = sum(candle_body(c) for c in sub)
                                if sub_sum > max_prev_sum_k:
                                    max_prev_sum_k = sub_sum
                                    ref_ts_k = int(sub[0]["timestamp"])
                    if max_prev_sum_k > 0:
                        if S_curr < max_prev_sum_k * (percent_limit_k / 100.0): is_max_series_valid = False; break
                        if k == N: target_ratio = (S_curr / max_prev_sum_k) * 100.0; target_ref_ts = ref_ts_k
                    else:
                        if k == N: is_max_series_valid = False
                if is_max_series_valid:
                    sig_name = "MAX" if N == 1 else f"MAX{N}"
                    found_signals.append((sig_name, target_ratio))
                    if N == 1: has_max_signal = True
                    if target_ref_ts is not None: state.max_series_ref_ts[sig_name] = target_ref_ts
    active_profiles = [p for p in config.get("STRATEGY_PROFILES", []) if p.get("enabled", True)]
    for profile in active_profiles:
        profile_id = profile["id"]
        multipliers = profile.get("candle_multipliers", {})
        profile_multi_candle_rules = sorted([(f"CANDLE_{count}_{profile_id}", count, multiplier) for count, multiplier in multipliers.items() if count > 1], key=lambda x: x[1], reverse=True)
        matched_rule_name = None
        matched_value = 0.0
        for rule_name, candles_count, multiplier in profile_multi_candle_rules:
            if len(recent_bodies) < candles_count or len(recent_directions) < candles_count: continue
            last_bodies = recent_bodies[-candles_count:]
            last_directions = recent_directions[-candles_count:]
            if last_directions[0] in (-1, 1) and len(set(last_directions)) == 1 and sum(last_bodies) >= state.average_body * multiplier:
                matched_rule_name = rule_name
                matched_value = sum(last_bodies) / state.average_body
                break
        if matched_rule_name is None and 1 in multipliers:
            if body >= state.average_body * multipliers[1]:
                matched_rule_name = f"CANDLE_1_{profile_id}"
                matched_value = body / state.average_body

        if matched_rule_name is not None:
            filter_enabled = profile.get("filter_enabled", False)
            filter_expr = str(profile.get("filter_expr", "")).strip()
            filter_passed = True
            if filter_enabled and filter_expr and filter_facts is not None:
                filter_passed = evaluate_signal_filter_expression(filter_expr, filter_facts)
            
            if filter_passed:
                found_signals.append((matched_rule_name, matched_value))
                # A closed candle keeps the bot's established one-profile
                # behaviour.  For a live wick we need every matching colour
                # in order to allow a later, explicitly higher-priority one.
                if not include_all_profile_matches:
                    break

    if config.get("MAX_ONLY_LONGEST_SERIES", True):
        max_sigs = []
        other_sigs = []
        for name, val in found_signals:
            if name == "MAX":
                max_sigs.append((1, name, val))
            elif name.startswith("MAX") and name[3:].isdigit():
                max_sigs.append((int(name[3:]), name, val))
            else:
                other_sigs.append((name, val))
        
        if max_sigs:
            max_sigs.sort(key=lambda x: x[0], reverse=True)
            best_max = max_sigs[0]
            other_sigs.append((best_max[1], best_max[2]))
        
        found_signals = other_sigs

    return found_signals


# ================= SUB TIMEFRAME CHECKERS =================

def get_closed_sub_candles(raw_candles: list[dict[str, Any]], candle_start: int, sub_tf_seconds: int, max_raw_ts: float, duration_seconds: int = 60) -> list[dict[str, float]]:
    sub_candles = []
    num_chunks = duration_seconds // sub_tf_seconds
    for i in range(num_chunks):
        chunk_start = candle_start + (i * sub_tf_seconds)
        chunk_end = chunk_start + sub_tf_seconds
        if max_raw_ts < chunk_end - 1: continue
        chunk_raws = [c for c in raw_candles if chunk_start <= candle_timestamp(c) < chunk_end]
        if not chunk_raws: continue
        chunk_raws.sort(key=lambda c: candle_timestamp(c))
        sub_candles.append({
            "timestamp": float(chunk_start), "open": float(chunk_raws[0]["open"]), "high": max(float(c["high"]) for c in chunk_raws), "low": min(float(c["low"]) for c in chunk_raws), "close": float(chunk_raws[-1]["close"])
        })
    return sub_candles


def check_same_color_sub_candles(sub_candles: list[dict[str, float]], expected_direction: int = 0, allow_doji: bool = True) -> bool:
    if not sub_candles: return False
    colors = [1 if sc["close"] > sc["open"] else -1 if sc["close"] < sc["open"] else 0 for sc in sub_candles]
    colors_to_check = [c for c in colors if c != 0] if allow_doji else colors
    if not colors_to_check or (not allow_doji and 0 in colors): return False
    if len(set(colors_to_check)) != 1: return False
    return colors_to_check[0] == expected_direction if expected_direction != 0 else True


# ================= WORKER PROCESS =================

async def process_worker_cycle(
    client,
    states: list[SymbolState],
    config: dict[str, Any],
    *,
    prefetched_history: Optional[dict[str, HistoryFetchResult]] = None,
    sent_pair_signal_keys: Optional[set[Any]] = None,
    sent_exact_signal_keys: Optional[set[tuple[str, int, str]]] = None,
) -> CycleResult:
    if not await check_ip_allowed(config):
        return CycleResult(messages=[], errors=1)

    messages = []; processed = 0; missing = 0; errors = 0; signals_found = 0; payout_rejected = 0; duplicate_suppressed = 0; level_marked = 0; rsi_marked = 0
    body_min = body_max = raw_first_ts = raw_last_ts = None; history1_count = 0; history1_total = 0.0; history1_min = history1_max = None
    states_by_symbol = {state.symbol: state for state in states}
    timeframe_seconds = int(config["TIMEFRAME_SECONDS"])

    async def process_signal_candle(
        state: SymbolState,
        candle: dict[str, Any],
        signal_candle_start: int,
        *,
        is_previous_candle: bool = False,
        is_live_candle: bool = False,
    ) -> None:
        nonlocal body_min, body_max, signals_found, payout_rejected, duplicate_suppressed, level_marked, rsi_marked
        if is_live_candle and config["LIVE_CANDLE_WICK_SIGNALS"]:
            body, direction = live_wick_measure_and_direction(candle)
        else:
            body, direction = candle_body(candle), candle_direction(candle)
        body_min = body if body_min is None else min(body_min, body)
        body_max = body if body_max is None else max(body_max, body)
        level_markers = level_marker_labels(state, candle, direction, config)
        order_block_marker = order_block_marker_label(state, candle, direction, config)
        filter_facts = build_signal_filter_facts(state, candle, config, level_markers, order_block_marker)

        max_candles_count = state.recent_bodies.maxlen or 8
        eff_bodies, eff_dirs = list(state.recent_bodies) + [body], list(state.recent_directions) + [direction]
        eff_bodies, eff_dirs = eff_bodies[-max_candles_count:], eff_dirs[-max_candles_count:]
        signals = detect_signals(
            state, body, config, recent_bodies=eff_bodies,
            recent_directions=eff_dirs, filter_facts=filter_facts,
            include_all_profile_matches=is_live_candle and config["LIVE_CANDLE_WICK_SIGNALS"],
        )
        if is_live_candle and state.live_signal_candle_start != signal_candle_start:
            state.live_signal_candle_start = signal_candle_start
            state.live_last_emitted_measure = 0.0
            state.live_best_profile_priority = None
        for signal_type, value in signals:
            profile_id = None
            if signal_type.startswith("CANDLE_"):
                parts = signal_type.split("_")
                if len(parts) >= 3: profile_id = "_".join(parts[2:])
            pair_signal_key = (state.symbol, signal_candle_start, profile_id) if profile_id else (state.symbol, signal_candle_start)
            exact_signal_key = (state.symbol, signal_candle_start, signal_type)
            is_exempt_filter = signal_type in SIGNAL_FILTER_EXEMPT_SIGNAL_TYPES or (signal_type.startswith("MAX") and signal_type[3:].isdigit())
            if not is_exempt_filter and profile_id:
                profile = next((p for p in config.get("STRATEGY_PROFILES", []) if p["id"] == profile_id), None)
                if profile and profile.get("filter_enabled", False):
                    expr = str(profile.get("filter_expr", "")).strip()
                    if expr and not evaluate_signal_filter_expression(expr, filter_facts): continue
            if is_live_candle and profile_id and config["LIVE_CANDLE_WICK_SIGNALS"]:
                profile_priority = profile_live_priority(config, profile_id)
                # A colour may appear once per M1.  A further alert needs a
                # real new extreme and a strictly higher priority than the
                # best colour already sent.
                if body <= state.live_last_emitted_measure:
                    duplicate_suppressed += 1
                    continue
                if (state.live_best_profile_priority is not None
                        and profile_priority >= state.live_best_profile_priority):
                    duplicate_suppressed += 1
                    continue
            if signal_type == "MAX" or (signal_type.startswith("MAX") and signal_type[3:].isdigit()):
                N_val = 1 if signal_type == "MAX" else int(signal_type[3:])
                val_config = config.get("MAX_BODY_PERCENT_SERIES", {}).get(N_val, config.get("MAX_BODY_PERCENT_DEFAULT"))
                if isinstance(val_config, dict) and val_config.get("filter_enabled", False):
                    expr = str(val_config.get("filter_expr", "")).strip()
                    if expr and not evaluate_signal_filter_expression(expr, filter_facts): continue
            rsi_marker = rsi_marker_label(state, candle, config)
            bb_marker = bollinger_marker_label(state, candle, config)
            if not rsi_filter_allows(state, candle, direction, config) or not bollinger_filter_allows(state, candle, direction, config): continue
            signals_found += 1
            if sent_exact_signal_keys is not None and exact_signal_key in sent_exact_signal_keys: duplicate_suppressed += 1; continue
            if not (signal_type in SIGNAL_SEQUENCE_EXEMPT_SIGNAL_TYPES or (signal_type.startswith("MAX") and signal_type[3:].isdigit())) and not config["SEND_RULE_SEQUENCE_SIGNALS"] and sent_pair_signal_keys is not None and pair_signal_key in sent_pair_signal_keys: duplicate_suppressed += 1; continue
            if level_markers: level_marked += 1
            if rsi_marker: rsi_marked += 1
            payout_key = signal_candle_start
            if len(state.payout_cache) > 5:
                for k in sorted(state.payout_cache.keys())[:-2]: state.payout_cache.pop(k, None)
            if payout_key not in state.payout_cache:
                payout = await get_current_payout(client, state.symbol, config)
                state.payout_cache[payout_key] = payout if payout is not None else 0
            if state.payout_cache[payout_key] < config["MIN_PAYOUT"]: payout_rejected += 1; continue
            if sent_exact_signal_keys is not None: sent_exact_signal_keys.add(exact_signal_key)
            if sent_pair_signal_keys is not None: sent_pair_signal_keys.add(pair_signal_key)
            if is_live_candle and profile_id and config["LIVE_CANDLE_WICK_SIGNALS"]:
                state.live_last_emitted_measure = body
                state.live_best_profile_priority = profile_live_priority(config, profile_id)
            if signal_type == "MAX" or (signal_type.startswith("MAX") and signal_type[3:].isdigit()):
                ref = max_body_reference_timestamp(state) if signal_type == "MAX" else state.max_series_ref_ts.get(signal_type)
                if config.get("MAX_RULE_DETAILS_ENABLED", True):
                    signal_value = f"{value:.0f}%" + (f"[{format_ts(ref)}]" if ref is not None else "")
                else:
                    signal_value = f"{value:.0f}%"
            else: signal_value = f"{value:.2f}x"
            label = SIGNAL_OUTPUT_LABELS.get(signal_type, signal_type)
            suffixes = ["prev"] if is_previous_candle else []
            suffixes.extend(level_markers)
            if order_block_marker: suffixes.append(order_block_marker)
            if rsi_marker: suffixes.append(rsi_marker)
            if bb_marker: suffixes.append(bb_marker)
            sig_count = int(signal_type.split("_")[1]) if (signal_type.startswith("CANDLE_") and len(signal_type.split("_")) > 1 and signal_type.split("_")[1].isdigit()) else 0
            if config.get("LP_ENABLED", False) and sig_count >= 2 and len(eff_bodies) >= 2 and eff_bodies[-1] > eff_bodies[-2]: suffixes.append(config.get("LP_LABEL", "L>P"))
            if config.get("FL_ENABLED", False) and signal_type.startswith("CANDLE_") and sig_count >= 3 and len(eff_bodies) >= sig_count:
                sb = list(eff_bodies)[-sig_count:]
                if len(sb) >= 3 and sb[0] > max(sb[1:-1]) and sb[-1] > max(sb[1:-1]): suffixes.append(config.get("FL_LABEL", "FL"))
            if sig_count >= 2 and check_prev_candle_wick_s(state, config): suffixes.append(config.get("WICK_S_LABEL", "S"))
            if config.get("LM_ENABLED", False) and sig_count >= 2 and len(eff_bodies) >= sig_count and eff_bodies[-1] > max(list(eff_bodies)[-sig_count:-1]): suffixes.append(config.get("LM_LABEL", "LM"))
            if config.get("RN_ENABLED", False) and is_near_round_number(float(candle["high"] if direction > 0 else candle["low"]), state.average_body * config.get("LEVEL_DISTANCE_BODY_MULTIPLIER", 0.05)): suffixes.append(config.get("RN_LABEL", "RN"))
            if res.raw_candles and (sig_count >= 2 or config.get("SUB_TF_SUFFIX_FOR_SINGLE_CANDLE", False)):
                dur = max(1, sig_count) * timeframe_seconds
                opts = [(int(config.get(ek, d)), lk) for ek, lk, d in [("S30_TF_SECONDS", "S30_LABEL", 30), ("S15_TF_SECONDS", "S15_LABEL", 15), ("S10_TF_SECONDS", "S10_LABEL", 10)] if config.get(ek.replace("TF_SECONDS", "ENABLED"), False)]
                opts.sort(key=lambda x: x[0])
                for stf, lk in opts:
                    sc = get_closed_sub_candles(res.raw_candles, signal_candle_start - dur + timeframe_seconds, stf, res.raw_last_ts or time.time(), dur)
                    if sc and check_same_color_sub_candles(sc, direction, config.get("SUB_TF_ALLOW_DOJI", True)): suffixes.append(config.get(lk, f"S{stf}")); break
            payout_val = state.payout_cache.get(payout_key)
            signal_text = f"{state.display_name}" + (f" ({payout_val}%)" if payout_val else "") + f" {label} {signal_value}" + (f" {' '.join(suffixes)}" if suffixes else "")
            chart_path = render_signal_chart(
                state,
                candle,
                direction,
                matched_signal_levels(state, candle, direction, config),
                signal_text,
                config,
            )
            messages.append(SignalMessage(text=signal_text, chart_path=chart_path))

    for batch in chunked([s.symbol for s in states], config["REQUEST_BATCH_SIZE"]):
        if prefetched_history is None:
            # Kept for direct/test use.  The live runner uses the independent
            # collector below and never waits for this branch.
            results = await asyncio.gather(*[fetch_timeframe_candle_timed(client, s, config) for s in batch])
        else:
            results = [prefetched_history.get(symbol, HistoryFetchResult(candle=None, elapsed=0.0)) for symbol in batch]
        for symbol, res in zip(batch, results):
            state = states_by_symbol[symbol]
            history1_count += 1; history1_total += res.elapsed
            history1_min = res.elapsed if history1_min is None else min(history1_min, res.elapsed)
            history1_max = res.elapsed if history1_max is None else max(history1_max, res.elapsed)
            if res.error is not None:
                errors += 1
                continue
            try:
                source_candles = normalize_and_sort_history(res.raw_candles)
                raw_times = [candle_timestamp(candle) for candle in source_candles]
                res.raw_candles = source_candles
                res.raw_first_ts = min(raw_times) if raw_times else None
                res.raw_last_ts = max(raw_times) if raw_times else None
                res.timeframe_candles = aggregate_history_to_timeframe(source_candles, timeframe_seconds, exclude_latest_bucket=False)
                res.candle = res.timeframe_candles[-1] if res.timeframe_candles else None
            except Exception:
                errors += 1
                continue
            if res.raw_first_ts is not None: raw_first_ts = res.raw_first_ts if raw_first_ts is None else min(raw_first_ts, res.raw_first_ts)
            if res.raw_last_ts is not None: raw_last_ts = res.raw_last_ts if raw_last_ts is None else max(raw_last_ts, res.raw_last_ts)
            if res.candle is None: missing += 1; continue
            act = int(res.candle["timestamp"])
            candles_by_start = {int(c["timestamp"]): c for c in res.timeframe_candles if int(c["timestamp"]) < act}
            if state.last_closed_candle_start is not None:
                next_closed = int(state.last_closed_candle_start) + timeframe_seconds
                while next_closed < act:
                    cc = candles_by_start.get(next_closed) or zero_candle(next_closed)
                    if not is_zero_candle(cc): await process_signal_candle(state, cc, next_closed, is_previous_candle=True)
                    append_closed_candle_to_state(state, cc, config)
                    next_closed += timeframe_seconds
            processed += 1
            update_live_candle_in_state(state, res.candle, config)
            # Analyse the merged copy from state, not the raw reply.  This is
            # what makes an already observed intrabar high/low irreversible.
            await process_signal_candle(state, state.live_candle or res.candle, act, is_live_candle=True)
            if sent_pair_signal_keys is not None:
                sent_pair_signal_keys.difference_update(k for k in tuple(sent_pair_signal_keys) if k[0] == state.symbol and k[1] < act)
            if sent_exact_signal_keys is not None:
                sent_exact_signal_keys.difference_update(k for k in tuple(sent_exact_signal_keys) if k[0] == state.symbol and k[1] < act)
            # Keep the event loop responsive for the independent history(1)
            # collector even when a worker owns many symbols.
            await asyncio.sleep(0)
    return CycleResult(
        messages=messages, processed=processed, missing=missing, errors=errors, signals_found=signals_found, payout_rejected=payout_rejected,
        duplicate_suppressed=duplicate_suppressed, level_marked=level_marked, rsi_marked=rsi_marked, body_min=body_min, body_max=body_max,
        raw_first_ts=raw_first_ts, raw_last_ts=raw_last_ts, history1_count=history1_count, history1_total=history1_total, history1_min=history1_min, history1_max=history1_max
    )


async def process_all_workers_cycle(
    workers: list[ClientWorker],
    config: dict[str, Any],
    *,
    sent_pair_signal_keys: Optional[set[Any]] = None,
    sent_exact_signal_keys: Optional[set[tuple[str, int, str]]] = None,
) -> CycleResult:
    if not workers:
        return CycleResult(messages=[])

    if not await check_ip_allowed(config):
        return CycleResult(messages=[], errors=1)

    tasks = [
        asyncio.create_task(
            asyncio.wait_for(
                process_worker_cycle(
                    w.client,
                    w.states,
                    config,
                    sent_pair_signal_keys=sent_pair_signal_keys,
                    sent_exact_signal_keys=sent_exact_signal_keys,
                ),
                timeout=w.worker_cycle_timeout,
            )
        )
        for w in workers
    ]

    results = await asyncio.gather(*tasks, return_exceptions=True)

    combined_messages: list[str] = []
    processed = 0
    missing = 0
    errors = 0
    signals_found = 0
    payout_rejected = 0
    duplicate_suppressed = 0
    level_marked = 0
    rsi_marked = 0
    body_min: Optional[float] = None
    body_max: Optional[float] = None
    raw_first_ts: Optional[float] = None
    raw_last_ts: Optional[float] = None
    history1_count = 0
    history1_total = 0.0
    history1_min: Optional[float] = None
    history1_max: Optional[float] = None

    for res in results:
        if isinstance(res, Exception) or res is None:
            errors += 1
            continue

        combined_messages.extend(res.messages)
        processed += res.processed
        missing += res.missing
        errors += res.errors
        signals_found += res.signals_found
        payout_rejected += res.payout_rejected
        duplicate_suppressed += res.duplicate_suppressed
        level_marked += res.level_marked
        rsi_marked += res.rsi_marked

        if res.body_min is not None:
            body_min = res.body_min if body_min is None else min(body_min, res.body_min)
        if res.body_max is not None:
            body_max = res.body_max if body_max is None else max(body_max, res.body_max)
        if res.raw_first_ts is not None:
            raw_first_ts = res.raw_first_ts if raw_first_ts is None else min(raw_first_ts, res.raw_first_ts)
        if res.raw_last_ts is not None:
            raw_last_ts = res.raw_last_ts if raw_last_ts is None else max(raw_last_ts, res.raw_last_ts)

        history1_count += res.history1_count
        history1_total += res.history1_total
        if res.history1_min is not None:
            history1_min = res.history1_min if history1_min is None else min(history1_min, res.history1_min)
        if res.history1_max is not None:
            history1_max = res.history1_max if history1_max is None else max(history1_max, res.history1_max)

    return CycleResult(
        messages=combined_messages,
        processed=processed,
        missing=missing,
        errors=errors,
        signals_found=signals_found,
        payout_rejected=payout_rejected,
        duplicate_suppressed=duplicate_suppressed,
        level_marked=level_marked,
        rsi_marked=rsi_marked,
        body_min=body_min,
        body_max=body_max,
        raw_first_ts=raw_first_ts,
        raw_last_ts=raw_last_ts,
        history1_count=history1_count,
        history1_total=history1_total,
        history1_min=history1_min,
        history1_max=history1_max,
    )


def put_latest_snapshot(queue: asyncio.Queue, snapshot: tuple[float, float, dict[str, HistoryFetchResult]]) -> None:
    """Never let analysis backlog slow down the history(1) collector."""
    if queue.full():
        try:
            queue.get_nowait()
            queue.task_done()
        except asyncio.QueueEmpty:
            pass
    queue.put_nowait(snapshot)


async def collect_output_batch(
    *,
    request_started_at: float,
    fetch_elapsed: float,
    messages: list[SignalMessage],
    worker_count: int,
    config: dict[str, Any],
    output_lock: asyncio.Lock,
    signal_minutes: set[int],
    pending_batches: dict[float, OutputBatch],
) -> None:
    """Merge all workers' signals from one poll under a single time line."""
    async with output_lock:
        # A stale incomplete batch means one worker intentionally skipped an
        # obsolete snapshot.  It must not block newer live output forever.
        stale_before = request_started_at - max(5, int(config["SCAN_INTERVAL_SECONDS"])) * 2
        for key in tuple(pending_batches):
            if key < stale_before:
                pending_batches.pop(key, None)

        batch = pending_batches.setdefault(request_started_at, OutputBatch(fetch_elapsed=fetch_elapsed))
        batch.completed_workers += 1
        batch.messages.extend(messages)
        if batch.completed_workers < worker_count:
            return
        pending_batches.pop(request_started_at, None)

        if batch.messages:
            signal_minutes.add(int(request_started_at) // 60)
            request_line = f"{format_ts_with_seconds(request_started_at)} {batch.fetch_elapsed:.2f}s"
            await emit_message_block([request_line], config, send_to_telegram=False)
            signal_entries = sort_signal_messages(batch.messages)
            signal_lines = [entry.text for entry in signal_entries]
            # Console keeps its compact one-block format.  In Telegram, a
            # chart-enabled signal is delivered as a photo with its own
            # caption; the common request-time line is sent once above it.
            await emit_message_block(signal_lines, config, send_to_telegram=False, prefix_first=False)
            if any(entry.chart_path is not None for entry in signal_entries):
                await send_telegram_message_async(config, request_line)
                for entry in signal_entries:
                    if entry.chart_path is not None:
                        await send_telegram_photo_async(config, entry.chart_path, entry.text)
                    else:
                        await send_telegram_message_async(config, entry.text)
            else:
                await send_telegram_message_async(config, "\n".join([request_line, *signal_lines]))
            return

        if int(request_started_at) % 60 != 55:
            return
        minute_key = int(request_started_at) // 60
        if minute_key not in signal_minutes:
            await emit_message_block(
                [f"{format_ts_with_seconds(request_started_at)} {batch.fetch_elapsed:.2f}s -"],
                config,
                send_to_telegram=False,
            )
            # Marks that this minute's one allowed console line was printed.
            signal_minutes.add(minute_key)
        signal_minutes.difference_update(
            item for item in tuple(signal_minutes) if item < minute_key - 1
        )


async def run_background_analysis(
    worker: ClientWorker,
    config: dict[str, Any],
    snapshots: asyncio.Queue,
    sent_pair_signal_keys: set[Any],
    sent_exact_signal_keys: set[tuple[str, int, str]],
    output_lock: asyncio.Lock,
    signal_minutes: set[int],
    pending_batches: dict[float, OutputBatch],
    worker_count: int,
) -> None:
    """Consumes collected snapshots independently from the network collector."""
    while True:
        request_started_at, fetch_elapsed, prefetched_history = await snapshots.get()
        try:
            # If polling outran a previous calculation, analyse only the most
            # recent market image.  Old intrabar signals must not be delivered.
            while not snapshots.empty():
                snapshots.task_done()
                request_started_at, fetch_elapsed, prefetched_history = snapshots.get_nowait()
            started = time.perf_counter()
            result = await process_worker_cycle(
                worker.client,
                worker.states,
                config,
                prefetched_history=prefetched_history,
                sent_pair_signal_keys=sent_pair_signal_keys,
                sent_exact_signal_keys=sent_exact_signal_keys,
            )
            analysis_elapsed = time.perf_counter() - started
            if config["DEBUG_CYCLE"]:
                write_line(f"ANALYSIS {format_ts_with_seconds(request_started_at)} {analysis_elapsed:.3f}s "
                           f"processed={result.processed} missing={result.missing} errors={result.errors} "
                           f"{format_cycle_debug_stats(result)}")
            await collect_output_batch(
                request_started_at=request_started_at,
                fetch_elapsed=fetch_elapsed,
                messages=result.messages,
                worker_count=worker_count,
                config=config,
                output_lock=output_lock,
                signal_minutes=signal_minutes,
                pending_batches=pending_batches,
            )
        finally:
            snapshots.task_done()


async def main():
    config = load_python_config(CONFIG_PATH)
    validate_signal_filter_expression(config)

    if not await check_ip_allowed(config):
        return

    if not config["SSIDS"] or not config["SSIDS"][0]: raise RuntimeError("SSID or SSIDS must be configured in config.py")
    symbols = normalize_symbols(config["PAIRS"])
    if int(config.get("REQUEST_BATCH_SIZE", 0)) <= 0:
        config["REQUEST_BATCH_SIZE"] = max(1, math.ceil(len(symbols) / max(1, len(config["SSIDS"]))))
    assignments = assign_symbols_to_ssids(symbols, len(config["SSIDS"]))
    clients = []; workers = []; name_map = {}; skipped_reasons = []; analysis_tasks: list[asyncio.Task] = []
    try:
        for idx, assigned in enumerate(assignments):
            if not assigned: continue
            if not await check_ip_allowed(config):
                return
            client = await create_client(config["SSIDS"][idx], config); clients.append(client)
            if not name_map:
                if not await check_ip_allowed(config):
                    return
                name_map = {a["symbol"]: a["name"] for a in await client.active_assets()}
            states, skipped, init_skipped = await initialize_worker_states(client, assigned, config, name_map)
            skipped_reasons.extend(init_skipped)
            if states:
                workers.append(ClientWorker(client=client, ssid_index=idx, states=states, worker_cycle_timeout=compute_worker_cycle_timeout(len(states), config)))
        total_states = sum(len(w.states) for w in workers)
        write_line(f"PAIRS {total_states} REQUEST_BATCH_SIZE {config['REQUEST_BATCH_SIZE']}")
        for w in workers:
            write_line(f"SSID {w.ssid_index + 1} PAIRS {len(w.states)}")
            for s in w.states: write_line(f"{s.display_name} candles={s.candles_count} avg={s.average_body:.6f} max={s.max_body:.6f}")
        if skipped_reasons:
            write_line("SKIPPED")
            for r in skipped_reasons: write_line(r)
        scan = max(1, int(config["SCAN_INTERVAL_SECONDS"]))
        sent_pair: set[Any] = set(); sent_exact: set[tuple[str, int, str]] = set()
        output_lock = asyncio.Lock()
        signal_minutes: set[int] = set()
        pending_batches: dict[float, OutputBatch] = {}
        analysis_queues: list[asyncio.Queue] = []
        for worker in workers:
            # A full slot is replaced by the newer snapshot.  Therefore a
            # delayed calculation can never delay the next history(1) poll.
            snapshots: asyncio.Queue = asyncio.Queue(maxsize=1)
            analysis_queues.append(snapshots)
            analysis_tasks.append(asyncio.create_task(
                run_background_analysis(
                    worker, config, snapshots, sent_pair, sent_exact, output_lock,
                    signal_minutes, pending_batches, len(workers),
                )
            ))
        while True:
            # Retain the boundary calculated *before* sleeping.  Recomputing
            # it after the boundary moves the label five seconds into the
            # future and makes request/signal lines appear out of order.
            next_boundary = ((int(time.time()) // scan) + 1) * scan
            await asyncio.sleep(max(0, next_boundary - time.time()))
            request_started_at = time.time()
            start_t = time.perf_counter()
            collected = await asyncio.gather(*(collect_worker_history(worker.client, worker.states, config) for worker in workers))
            fetch_elapsed = time.perf_counter() - start_t
            if config["DEBUG_CYCLE"]:
                write_line(f"FETCH {format_ts_with_seconds(request_started_at)} {fetch_elapsed:.3f}s history(1)={sum(len(snapshot) for snapshot in collected)}")
            for queue, snapshot in zip(analysis_queues, collected):
                put_latest_snapshot(queue, (request_started_at, fetch_elapsed, snapshot))
    finally:
        for task in analysis_tasks:
            task.cancel()
        if analysis_tasks:
            await asyncio.gather(*analysis_tasks, return_exceptions=True)
        await asyncio.gather(*(close_client(c) for c in clients), return_exceptions=True)


if __name__ == "__main__":
    asyncio.run(main())
