from __future__ import annotations

import asyncio
import importlib.util
import math
import sys
import time
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
    from BinaryOptionsToolsV2 import PocketOptionAsync
except ImportError:
    PocketOptionAsync = None


for stream in (sys.stdout, sys.stderr):
    try:
        stream.reconfigure(encoding="utf-8", errors="replace")
    except (AttributeError, ValueError):
        pass


CONFIG_PATH = Path("config.py")

TIME_OFFSET_HOURS = -2.0
GLOBAL_CONFIG: dict[str, Any] = {}


@dataclass
class SymbolState:
    symbol: str
    display_name: str
    candles_count_by_timeframe: dict[int, int]
    candle_histories: dict[int, deque]
    last_closed_candle_starts: dict[int, int]
    payout_cache: dict[int, int] = field(default_factory=dict)
    notified_keys: set[tuple[str, str, int]] = field(default_factory=set)


@dataclass
class ClientWorker:
    client: Any
    ssid_index: int
    states: list[SymbolState]
    worker_cycle_timeout: float


@dataclass
class CycleResult:
    messages: list[str]
    processed: int = 0
    missing: int = 0
    errors: int = 0
    signals_found: int = 0
    payout_rejected: int = 0
    duplicate_suppressed: int = 0


@dataclass
class HistoryFetchResult:
    elapsed: float
    error: Optional[BaseException] = None
    raw_count: int = 0
    raw_first_ts: Optional[float] = None
    raw_last_ts: Optional[float] = None
    raw_candles: list[dict[str, Any]] = field(default_factory=list)


# ================= CONFIG LOADING =================

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
        write_line("Фильтр IP адресов: страна в списке ограничений.")
        return False
    return True


def normalize_strategies(raw_strategies: Any) -> list[dict[str, Any]]:
    """Validate strategies from config and convert all values to stable types."""
    if not isinstance(raw_strategies, (list, tuple)):
        raise RuntimeError("STRATEGIES в config.py должен быть списком стратегий")

    normalized: list[dict[str, Any]] = []
    used_ids: set[str] = set()
    for position, raw in enumerate(raw_strategies, start=1):
        if not isinstance(raw, dict):
            raise RuntimeError(f"STRATEGIES[{position}] должен быть словарём")
        if not bool(raw.get("enabled", True)):
            continue

        strategy_id = str(raw.get("id", "")).strip()
        if not strategy_id:
            raise RuntimeError(f"У стратегии #{position} не задан id")
        if strategy_id in used_ids:
            raise RuntimeError(f"Повторяющийся id стратегии: {strategy_id}")

        timeframe = int(raw.get("timeframe_seconds", 0))
        lookback = int(raw.get("lookback_bars", 100))
        raw_color = raw.get("color")
        color = str(raw_color).strip().lower() if raw_color is not None else None
        if color in {"", "any"}:
            color = None
        streak_length = int(raw.get("streak_length", 0))
        streak_mode = str(raw.get("streak_mode", "exact")).strip().lower()
        measured_candles = int(raw.get("measured_candles", 0))
        threshold_pct = float(raw.get("threshold_pct", 0))
        signal_timing = str(raw.get("signal_timing", "live")).strip().lower()

        if timeframe <= 0:
            raise RuntimeError(f"{strategy_id}: timeframe_seconds должен быть больше 0")
        if lookback < 2:
            raise RuntimeError(f"{strategy_id}: lookback_bars должен быть не меньше 2")
        if color not in {None, "red", "green"}:
            raise RuntimeError(f"{strategy_id}: color должен быть red, green или отсутствовать")
        if streak_length <= 0:
            raise RuntimeError(f"{strategy_id}: streak_length должен быть больше 0")
        if streak_mode not in {"exact", "at_least"}:
            raise RuntimeError(f"{strategy_id}: streak_mode должен быть exact или at_least")
        if not 1 <= measured_candles <= streak_length:
            raise RuntimeError(f"{strategy_id}: measured_candles должен быть от 1 до streak_length")
        if threshold_pct <= 0:
            raise RuntimeError(f"{strategy_id}: threshold_pct должен быть больше 0")
        if signal_timing not in {"live", "closed"}:
            raise RuntimeError(f"{strategy_id}: signal_timing должен быть live или closed")

        normalized.append({
            "id": strategy_id,
            "timeframe_seconds": timeframe,
            "lookback_bars": lookback,
            "color": color,
            "streak_length": streak_length,
            "streak_mode": streak_mode,
            "measured_candles": measured_candles,
            "threshold_pct": threshold_pct,
            "signal_timing": signal_timing,
        })
        used_ids.add(strategy_id)

    if not normalized:
        raise RuntimeError("В STRATEGIES нет включённых стратегий")
    return normalized


def build_strategy_indexes(strategies: list[dict[str, Any]], base_history_bars: int) -> tuple[dict[int, list[dict[str, Any]]], dict[int, int]]:
    by_timeframe: dict[int, list[dict[str, Any]]] = {}
    history_bars_by_timeframe: dict[int, int] = {}
    for strategy in strategies:
        timeframe = strategy["timeframe_seconds"]
        by_timeframe.setdefault(timeframe, []).append(strategy)
        required_bars = strategy["lookback_bars"] + max(strategy["streak_length"], strategy["measured_candles"]) + 1
        history_bars_by_timeframe[timeframe] = max(
            history_bars_by_timeframe.get(timeframe, 0),
            base_history_bars,
            required_bars,
        )
    return by_timeframe, history_bars_by_timeframe


def load_python_config(path: Path) -> dict[str, Any]:
    global TIME_OFFSET_HOURS, GLOBAL_CONFIG
    spec = importlib.util.spec_from_file_location("local_config", path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Не удалось загрузить конфигурационный файл {path}")

    module = importlib.util.module_from_spec(spec)
    global TIME_OFFSET_HOURS
    spec.loader.exec_module(module)

    TIME_OFFSET_HOURS = float(getattr(module, "TIME_OFFSET_HOURS", -2.0))
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
    
    strategies = normalize_strategies(getattr(module, "STRATEGIES", []))
    base_history_bars = int(getattr(module, "HISTORY_PERIOD", 100))
    strategies_by_timeframe, history_bars_by_timeframe = build_strategy_indexes(strategies, base_history_bars)
    timeframes = sorted(strategies_by_timeframe)

    restricted_raw = getattr(module, "RESTRICTED_COUNTRIES", "")
    restricted_set = parse_restricted_countries(restricted_raw)

    res = {
        "SSIDS": normalized_ssids,
        "PAIRS": pairs,
        "BOT_TOKEN": str(getattr(module, "BOT_TOKEN", "")).strip(),
        "CHAT_IDS": normalized_chat_ids,
        "REQUEST_BATCH_SIZE": int(getattr(module, "REQUEST_BATCH_SIZE", 0)),
        "HISTORY_PERIOD": base_history_bars,
        "HISTORY_SOURCE_PERIOD": int(getattr(module, "HISTORY_SOURCE_PERIOD", 1)),
        "HISTORY_TIMEOUT": float(getattr(module, "HISTORY_TIMEOUT", 5)),
        "STRATEGIES": strategies,
        "STRATEGIES_BY_TIMEFRAME": strategies_by_timeframe,
        "STRATEGY_TIMEFRAMES": timeframes,
        "HISTORY_BARS_BY_TIMEFRAME": history_bars_by_timeframe,
        "DEBUG_CYCLE": bool(getattr(module, "DEBUG_CYCLE", False)),
        "MIN_PAYOUT": int(getattr(module, "MIN_PAYOUT", 80)),
        "RESTRICTED_COUNTRIES": restricted_raw,
        "RESTRICTED_COUNTRIES_SET": restricted_set,
        "SCAN_INTERVAL_SECONDS": int(getattr(module, "SCAN_INTERVAL_SECONDS", 15)),
        "TIME_OFFSET_HOURS": TIME_OFFSET_HOURS,
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


async def emit_message_block(
    lines: list[str],
    config: dict[str, Any],
    *,
    send_to_telegram: bool = True,
    telegram_lines: Optional[list[str]] = None,
) -> None:
    for index, line in enumerate(lines):
        write_line(f"== {line}" if index == 0 else line)
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


def format_ts(ts: float, offset_hours: Optional[float] = None) -> str:
    if offset_hours is None:
        offset_hours = GLOBAL_CONFIG.get("TIME_OFFSET_HOURS", TIME_OFFSET_HOURS)
    offset_ts = ts + offset_hours * 3600
    return datetime.fromtimestamp(offset_ts).strftime("%H:%M")


def format_ts_with_seconds(ts: float, offset_hours: Optional[float] = None) -> str:
    if offset_hours is None:
        offset_hours = GLOBAL_CONFIG.get("TIME_OFFSET_HOURS", TIME_OFFSET_HOURS)
    offset_ts = ts + offset_hours * 3600
    return datetime.fromtimestamp(offset_ts).strftime("%H:%M:%S")


def candle_timestamp(candle: dict[str, Any]) -> float:
    for key in ("timestamp", "time"):
        if key in candle:
            value = float(candle[key])
            if value > 10_000_000_000_000: return value / 1_000_000
            if value > 10_000_000_000: return value / 1_000
            return value
    raise KeyError("свеча не содержит поля timestamp/time")


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
    source_minutes = aggregate_history_to_timeframe(normalize_and_sort_history(source_candles), timeframe_seconds, exclude_latest_bucket=True)
    if source_minutes:
        max_closed_ts = int(source_minutes[-1]["timestamp"])
        base_candles = [c for c in base_candles if int(c["timestamp"]) <= max_closed_ts]
    latest_base_start = int(base_candles[-1]["timestamp"]) if base_candles else None
    tail_candles = [c for c in source_minutes if latest_base_start is None or int(c["timestamp"]) > latest_base_start]
    return sorted([*base_candles, *tail_candles], key=lambda c: c["timestamp"])


# ================= UNIVERSAL CANDLE STRATEGIES =================

EPSILON = 1e-12


def candle_color(candle: dict[str, float]) -> Optional[str]:
    body = float(candle["close"]) - float(candle["open"])
    if abs(body) <= EPSILON:
        return None
    return "green" if body > 0 else "red"


def candle_body(candle: dict[str, float]) -> float:
    return abs(float(candle["close"]) - float(candle["open"]))


def current_streak_length(candles: list[dict[str, float]], color: str) -> int:
    length = 0
    for candle in reversed(candles):
        if candle_color(candle) != color:
            break
        length += 1
    return length


def find_candle_strategy_signal(candles: list[dict[str, float]], strategy: dict[str, Any]) -> Optional[dict[str, Any]]:
    """Evaluate one strategy on the latest completed candle only, without look-ahead."""
    measured = strategy["measured_candles"]
    lookback = strategy["lookback_bars"]
    color = strategy["color"] or candle_color(candles[-1])
    if color is None:
        return None
    minimum_history = lookback + max(strategy["streak_length"], measured) + 1
    if len(candles) < minimum_history:
        return None

    streak_length = current_streak_length(candles, color)
    if strategy["streak_mode"] == "exact":
        if streak_length != strategy["streak_length"]:
            return None
    elif streak_length < strategy["streak_length"]:
        return None

    candidate = candles[-measured:]
    if any(candle_color(candle) != color for candle in candidate):
        return None
    candidate_body = sum(candle_body(candle) for candle in candidate)
    if candidate_body <= EPSILON:
        return None

    # The benchmark consists of same-colour blocks fully contained in the
    # lookback immediately preceding the measured tail. It cannot overlap it.
    candidate_start = len(candles) - measured
    reference = candles[candidate_start - lookback:candidate_start]
    reference_maximum = 0.0
    for start in range(0, len(reference) - measured + 1):
        block = reference[start:start + measured]
        if all(candle_color(candle) == color for candle in block):
            reference_maximum = max(reference_maximum, sum(candle_body(candle) for candle in block))
    if reference_maximum <= EPSILON:
        return None

    ratio_pct = candidate_body / reference_maximum * 100.0
    if ratio_pct + EPSILON < strategy["threshold_pct"]:
        return None

    signal_candle = candles[-1]
    return {
        "strategy_id": strategy["id"],
        "signal_timestamp": int(signal_candle["timestamp"]),
        "timeframe_seconds": strategy["timeframe_seconds"],
        "color": color,
        "streak_length": streak_length,
        "measured_candles": measured,
        "ratio_pct": ratio_pct,
        "threshold_pct": strategy["threshold_pct"],
    }


def format_strategy_signal(signal: dict[str, Any]) -> str:
    return f"{signal['strategy_id']} → {signal['ratio_pct']:.1f}%"


# ================= WORKER / CLIENT UTILS =================

def compute_worker_cycle_timeout(states_count: int, config: dict[str, Any]) -> float:
    configured = float(config.get("WORKER_CYCLE_TIMEOUT", 0))
    if configured > 0: return configured
    batch_size = max(1, int(config["REQUEST_BATCH_SIZE"]))
    batches_count = max(1, (states_count + batch_size - 1) // batch_size)
    return min((batches_count * (float(config["HISTORY_TIMEOUT"]) + 1.0)) + 2.0, 60.0)


async def create_client(ssid: str, config: Optional[dict[str, Any]] = None):
    if config and not await check_ip_allowed(config):
        raise RuntimeError("Фильтр IP адресов: доступ ограничен.")
    if PocketOptionAsync is None:
        raise ImportError("Пакет BinaryOptionsToolsV2 не установлен")
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
    started_at = time.perf_counter()
    if not await check_ip_allowed(config):
        return HistoryFetchResult(elapsed=time.perf_counter() - started_at)
    try:
        raw_candles = await fetch_source_history(client, symbol, config)
        raw_times = [candle_timestamp(c) for c in raw_candles]
        return HistoryFetchResult(
            elapsed=time.perf_counter() - started_at,
            raw_count=len(raw_candles),
            raw_first_ts=min(raw_times) if raw_times else None,
            raw_last_ts=max(raw_times) if raw_times else None,
            raw_candles=raw_candles,
        )
    except Exception as exc:
        return HistoryFetchResult(elapsed=time.perf_counter() - started_at, error=exc)


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
        return None, "Фильтр IP не пройден."
    pairs_map = config.get("PAIRS_MAP", {})
    mapped_name = pairs_map.get(symbol, name_map.get(symbol, symbol))
    timeframes = config["STRATEGY_TIMEFRAMES"]
    initial_results = await asyncio.gather(
        *(fetch_history_period(client, symbol, timeframe, config) for timeframe in timeframes),
        return_exceptions=True,
    )
    try:
        source_candles = await fetch_source_history(client, symbol, config)
    except Exception:
        source_candles = []

    histories: dict[int, deque] = {}
    candles_count_by_timeframe: dict[int, int] = {}
    last_closed_candle_starts: dict[int, int] = {}
    for timeframe, initial_result in zip(timeframes, initial_results):
        if isinstance(initial_result, Exception) or not initial_result:
            return None, f"{mapped_name} - пустая начальная история ({timeframe}с)"
        timeframe_candles = merge_initial_timeframe_history(initial_result, source_candles, timeframe)
        level_candles = normalize_and_sort_history(timeframe_candles)
        if not level_candles:
            return None, f"{mapped_name} - не удалось агрегировать историю ({timeframe}с)"
        maxlen = config["HISTORY_BARS_BY_TIMEFRAME"][timeframe]
        histories[timeframe] = deque(level_candles, maxlen=maxlen)
        candles_count_by_timeframe[timeframe] = len(level_candles)
        last_closed_candle_starts[timeframe] = int(level_candles[-1]["timestamp"])

    state = SymbolState(
        symbol=symbol,
        display_name=short_display_name(name_map.get(symbol, symbol), symbol, pairs_map),
        candles_count_by_timeframe=candles_count_by_timeframe,
        candle_histories=histories,
        last_closed_candle_starts=last_closed_candle_starts,
    )
    return state, None


async def initialize_worker_states(client, symbols: list[str], config: dict[str, Any], name_map: dict[str, str]):
    states = []; skipped = 0; skipped_reasons = []; pairs_map = config.get("PAIRS_MAP", {})
    if not await check_ip_allowed(config):
        return [], len(symbols), ["Фильтр IP адресов не пройден."]
    for batch in chunked(symbols, config["REQUEST_BATCH_SIZE"]):
        results = await asyncio.gather(*[initialize_symbol_state(client, s, config, name_map) for s in batch], return_exceptions=True)
        for symbol, result in zip(batch, results):
            mapped_name = pairs_map.get(symbol, name_map.get(symbol, symbol))
            if isinstance(result, Exception):
                skipped += 1; skipped_reasons.append(f"{mapped_name} - ошибка инициализации: {result}"); continue
            state, reason = result
            if state is None: skipped += 1; skipped_reasons.append(reason or f"{mapped_name} - пропущен"); continue
            states.append(state)
    return states, skipped, skipped_reasons


# ================= WORKER PROCESS =================

async def process_worker_cycle(client, states: list[SymbolState], config: dict[str, Any]) -> CycleResult:
    if not await check_ip_allowed(config):
        return CycleResult(messages=[], errors=1)

    messages = []; processed = 0; missing = 0; errors = 0; signals_found = 0; payout_rejected = 0; duplicate_suppressed = 0
    states_by_symbol = {state.symbol: state for state in states}

    for batch in chunked([s.symbol for s in states], config["REQUEST_BATCH_SIZE"]):
        results = await asyncio.gather(*[fetch_timeframe_candle_timed(client, s, config) for s in batch])
        for symbol, res in zip(batch, results):
            state = states_by_symbol[symbol]
            if res.error is not None: errors += 1; continue
            if not res.raw_candles: missing += 1; continue

            updated_timeframes: list[tuple[int, dict[str, float]]] = []
            for timeframe in config["STRATEGY_TIMEFRAMES"]:
                timeframe_candles = aggregate_history_to_timeframe(
                    res.raw_candles,
                    timeframe,
                    exclude_latest_bucket=False,
                )
                if not timeframe_candles:
                    continue
                # Последний агрегированный бар ещё формируется. Он может
                # вызвать live-сигнал, но в историю закрытых свечей не попадает.
                live_candle = timeframe_candles[-1]
                closed_candles = timeframe_candles[:-1]

                history_map = {
                    int(candle["timestamp"]): candle
                    for candle in state.candle_histories[timeframe]
                }
                for candle in closed_candles:
                    if not is_zero_candle(candle):
                        history_map[int(candle["timestamp"])] = candle
                state.candle_histories[timeframe] = deque(
                    [history_map[timestamp] for timestamp in sorted(history_map)],
                    maxlen=config["HISTORY_BARS_BY_TIMEFRAME"][timeframe],
                )
                if closed_candles:
                    state.last_closed_candle_starts[timeframe] = int(closed_candles[-1]["timestamp"])
                updated_timeframes.append((timeframe, live_candle))

            if not updated_timeframes:
                missing += 1
                continue
            processed += 1

            # STRATEGIES already preserves the order from config.py. The first
            # matched rule wins, including when it is a duplicate or payout is
            # too low; lower-priority rules never produce a second alert.
            live_candles_by_timeframe = dict(updated_timeframes)
            for strategy in config["STRATEGIES"]:
                timeframe = strategy["timeframe_seconds"]
                live_candle = live_candles_by_timeframe.get(timeframe)
                if live_candle is None:
                    continue
                closed_history = list(state.candle_histories[timeframe])
                if strategy["signal_timing"] == "live":
                    candles_by_timestamp = {
                        int(candle["timestamp"]): candle for candle in closed_history
                    }
                    candles_by_timestamp[int(live_candle["timestamp"])] = live_candle
                    candles = [candles_by_timestamp[timestamp] for timestamp in sorted(candles_by_timestamp)]
                else:
                    candles = closed_history
                signal = find_candle_strategy_signal(candles, strategy)
                if signal is None:
                    continue

                signals_found += 1
                dedup_key = (state.symbol, signal["strategy_id"], signal["signal_timestamp"])
                if dedup_key in state.notified_keys:
                    duplicate_suppressed += 1
                else:
                    payout_key = signal["signal_timestamp"]
                    if payout_key not in state.payout_cache:
                        payout = await get_current_payout(client, state.symbol, config)
                        state.payout_cache[payout_key] = payout if payout is not None else 0

                    payout_val = state.payout_cache[payout_key]
                    if payout_val < config["MIN_PAYOUT"]:
                        payout_rejected += 1
                    else:
                        state.notified_keys.add(dedup_key)
                        full_msg = f"{state.display_name} ({payout_val}%) {format_strategy_signal(signal)}"
                        messages.append(full_msg)
                break

    return CycleResult(
        messages=messages, processed=processed, missing=missing, errors=errors,
        signals_found=signals_found, payout_rejected=payout_rejected,
        duplicate_suppressed=duplicate_suppressed
    )


async def process_all_workers_cycle(
    workers: list[ClientWorker],
    config: dict[str, Any]
) -> CycleResult:
    if not workers:
        return CycleResult(messages=[])

    if not await check_ip_allowed(config):
        return CycleResult(messages=[], errors=1)

    tasks = [
        asyncio.create_task(
            asyncio.wait_for(
                process_worker_cycle(w.client, w.states, config),
                timeout=w.worker_cycle_timeout,
            )
        )
        for w in workers
    ]

    results = await asyncio.gather(*tasks, return_exceptions=True)

    combined_messages: list[str] = []
    processed = 0; missing = 0; errors = 0; signals_found = 0; payout_rejected = 0; duplicate_suppressed = 0

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

    return CycleResult(
        messages=combined_messages, processed=processed, missing=missing,
        errors=errors, signals_found=signals_found, payout_rejected=payout_rejected,
        duplicate_suppressed=duplicate_suppressed
    )


async def main():
    config = load_python_config(CONFIG_PATH)

    if not await check_ip_allowed(config):
        return

    if not config["SSIDS"] or not config["SSIDS"][0]:
        raise RuntimeError("Переменная SSID или SSIDS должна быть настроена в config.py")

    symbols = normalize_symbols(config["PAIRS"])
    if int(config.get("REQUEST_BATCH_SIZE", 0)) <= 0:
        config["REQUEST_BATCH_SIZE"] = max(1, math.ceil(len(symbols) / max(1, len(config["SSIDS"]))))
    
    assignments = assign_symbols_to_ssids(symbols, len(config["SSIDS"]))
    clients = []; workers = []; name_map = {}; skipped_reasons = []

    try:
        for idx, assigned in enumerate(assignments):
            if not assigned: continue
            if not await check_ip_allowed(config):
                return
            client = await create_client(config["SSIDS"][idx], config)
            clients.append(client)
            if not name_map:
                if not await check_ip_allowed(config):
                    return
                name_map = {a["symbol"]: a["name"] for a in await client.active_assets()}
            states, skipped, init_skipped = await initialize_worker_states(client, assigned, config, name_map)
            skipped_reasons.extend(init_skipped)
            if states:
                workers.append(ClientWorker(
                    client=client, ssid_index=idx, states=states,
                    worker_cycle_timeout=compute_worker_cycle_timeout(len(states), config)
                ))

        total_states = sum(len(w.states) for w in workers)
        write_line(f"ПАРИ {total_states} РАЗМЕР ПАКЕТА {config['REQUEST_BATCH_SIZE']}")
        for w in workers:
            write_line(f"SSID {w.ssid_index + 1} ПАР: {len(w.states)}")
            for s in w.states:
                candles_info = ", ".join(
                    f"{timeframe}с={count}"
                    for timeframe, count in sorted(s.candles_count_by_timeframe.items())
                )
                write_line(f"{s.display_name} свечей: {candles_info}")

        if skipped_reasons:
            write_line("ПРОПУЩЕНЫ:")
            for r in skipped_reasons: write_line(r)

        scan = max(1, int(config["SCAN_INTERVAL_SECONDS"]))
        # Консольный признак жизни: Telegram при этом по-прежнему молчит.
        last_console_signal_at = time.monotonic()

        while True:
            await asyncio.sleep(max(0, (((int(time.time()) // scan) + 1) * scan) - time.time()))
            tick = float(((int(time.time()) // scan) + 1) * scan)
            start_t = time.perf_counter()

            res = await process_all_workers_cycle(workers, config)
            el = time.perf_counter() - start_t

            if config["DEBUG_CYCLE"]:
                write_line(f"DEBUG {format_ts_with_seconds(tick)} {el:.2f}s обработано={res.processed} сигналы={res.signals_found}")

            if res.messages:
                await emit_message_block(
                    [f"{format_ts_with_seconds(tick)} {el:.2f}s", *res.messages],
                    config, telegram_lines=[format_ts_with_seconds(tick), *res.messages]
                )
                last_console_signal_at = time.monotonic()
            elif time.monotonic() - last_console_signal_at >= 120:
                write_line(f"{format_ts_with_seconds(tick)} {el:.2f}s ---")
                last_console_signal_at = time.monotonic()

    finally:
        await asyncio.gather(*(close_client(c) for c in clients), return_exceptions=True)


if __name__ == "__main__":
    asyncio.run(main())
