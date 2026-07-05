# -*- coding: utf-8 -*-
"""
Pocket Options WebSocket Proxy Server для n8n
HTTP API сервер, который подключается к Pocket Options через WebSocket и предоставляет данные свечей

ИСПОЛЬЗОВАНИЕ:
1. Установите зависимости:
   pip install fastapi uvicorn

2. Запустите сервер:
   python pocket-options-proxy.py

3. Используйте в n8n:
   http://localhost:3000/api/candles?instrument=EURUSD&timeframe=1&sessionToken=YOUR_TOKEN&uid=YOUR_UID
"""

import sys
import io
import os
import json
import re
import asyncio
import hashlib
from contextlib import asynccontextmanager
from datetime import datetime
from typing import Any, Optional, List, Dict
from fastapi import FastAPI, HTTPException, Query, Body
from fastapi.responses import JSONResponse, Response
from fastapi.middleware.cors import CORSMiddleware
import uvicorn

# Импорты для генерации графиков
import matplotlib
matplotlib.use('Agg')  # Backend без GUI для сервера
import matplotlib.pyplot as plt
import matplotlib.dates as mdates
from datetime import datetime as dt

# Настройка кодировки для Windows консоли
if sys.platform == 'win32':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace', line_buffering=True)
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace', line_buffering=True)

# Добавляем путь к PocketOptionAPI-main в начало sys.path для правильных импортов
pocketoption_path = os.path.abspath(os.path.join(os.path.dirname(__file__), 'python', 'PocketOptionAPI-main'))
if pocketoption_path not in sys.path:
    sys.path.insert(0, pocketoption_path)

# Импортируем после добавления пути
import uuid

from pocketoptionapi_async import AsyncPocketOptionClient
from pocketoptionapi_async.constants import API_LIMITS, ASSETS
from pocketoptionapi_async.exceptions import InvalidParameterError
from pocketoptionapi_async.models import Order, OrderDirection

# Настройки сервера
PORT = 3000
HOST = "0.0.0.0"

# Фоновая задача инжеста (по одному WebSocket на актив, PLAN_RELEASE)
_ingest_task: Optional[asyncio.Task] = None


async def _shutdown_ingest():
    global _ingest_task
    if _ingest_task and not _ingest_task.done():
        _ingest_task.cancel()
        try:
            await _ingest_task
        except asyncio.CancelledError:
            pass
        _ingest_task = None
        print("[INGEST] stopped", flush=True)
    try:
        from ingest_stream import close_ingest_http_session

        await close_ingest_http_session()
    except Exception:
        pass


@asynccontextmanager
async def lifespan(app: FastAPI):
    global _ingest_task
    if os.environ.get("PLATFORM_URL"):
        try:
            from ingest_stream import run_ingest_forever

            _ingest_task = asyncio.create_task(run_ingest_forever())
            print("[INGEST] background task started (one WebSocket per instrument)", flush=True)
        except Exception as e:
            import traceback

            print(f"[INGEST] failed to start: {e}", flush=True)
            traceback.print_exc()
    yield
    await _shutdown_ingest()


# Создаем FastAPI приложение
app = FastAPI(
    title="Pocket Options WebSocket Proxy",
    version="1.0.0",
    lifespan=lifespan,
)


# Настройка CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Глобальный словарь для хранения клиентов (по SSID)
_clients: Dict[str, AsyncPocketOptionClient] = {}
# Ingest + HTTP в одном loop: не больше 2 одновременных place-order.
_place_order_semaphore = asyncio.Semaphore(2)

# Ожидание successopenOrder / json_data после openOrder (без доп. запросов к PO).
MIRROR_ORDER_CONFIRM_TIMEOUT_SEC = 25.0
# Доп. grace: PO иногда отвечает с задержкой, когда ingest грузит event loop.
MIRROR_ORDER_CONFIRM_GRACE_SEC = 5.0


def convert_ui_asset_to_websocket(ui_asset: str) -> str:
    """Конвертирует имя актива из UI в формат WebSocket API"""
    # Примеры: "NZD/USD OTC" -> "NZDUSD_otc", "EUR/USD" -> "EURUSD"
    asset = ui_asset.upper().strip()
    
    # Проверяем на OTC
    is_otc = ' OTC' in asset or asset.endswith(' OTC')
    if is_otc:
        asset = asset.replace(' OTC', '').replace('OTC', '')
    
    # Убираем слэши и пробелы
    asset = asset.replace('/', '').replace(' ', '').replace('-', '')
    
    # Добавляем _otc если нужно (API ожидает маленькие буквы)
    if is_otc:
        asset = f"{asset}_otc"
    
    return asset


def create_ssid_from_params(session_token: str, uid: str) -> tuple:
    """Создает SSID из sessionToken и uid для использования с AsyncPocketOptionClient
    
    Returns:
        tuple: (ssid, is_demo)
    """
    # Определяем, демо ли это (проверяем разные варианты, как в asset_scanner_websocket.py)
    # По умолчанию считаем, что это реальный аккаунт, если не указано иное
    # Формат SSID: 42["auth",{"session":"...","uid":...,"isDemo":0,"platform":2}]
    ssid = f'42["auth",{{"session":"{session_token}","uid":{int(uid) if uid else 0},"isDemo":1,"platform":2,"isFastHistory":true,"isOptimized":true}}]'
    is_demo = True  # Используем демо по умолчанию (как в предоставленных данных)
    return ssid, is_demo


import time
import aiohttp

# --- Proxy Cache System for pocket-options-proxy ---
_instruments_cache = []
_last_cache_update = 0
# Отпечаток proxy_url по session_token — чтобы сбрасывать WebSocket при смене endpoint.
_client_proxy_by_session: Dict[str, Optional[str]] = {}

async def get_proxy_for_session(session_token: str) -> Optional[str]:
    global _instruments_cache, _last_cache_update
    
    # Update cache every 5 minutes (300 seconds)
    if not _instruments_cache or time.time() - _last_cache_update > 300:
        platform_url = os.environ.get("PLATFORM_URL", "http://localhost:8080").rstrip("/")
        try:
            url = f"{platform_url}/api/proxy/instruments"
            async with aiohttp.ClientSession() as session:
                async with session.get(
                    url,
                    headers={"Accept": "application/json"},
                    timeout=10,
                ) as resp:
                    if resp.status == 200:
                        _instruments_cache = await resp.json()
                        _last_cache_update = time.time()
        except Exception as e:
            print(f"[WARNING] Failed to fetch instruments for proxy: {e}")
            
    for item in _instruments_cache:
        if item.get("session_token") == session_token:
            return item.get("proxy_url")
    return None

async def get_or_create_client(session_token: str, uid: str) -> AsyncPocketOptionClient:
    """Получает существующий клиент или создает новый"""
    ssid, is_demo = create_ssid_from_params(session_token, uid)
    client_key = f"{session_token}_{uid}"
    
    proxy_url = await get_proxy_for_session(session_token)
    if proxy_url:
        print(f"[INFO] Using assigned proxy for session {session_token[:10]}...")
    
    prev_proxy = _client_proxy_by_session.get(session_token)
    if prev_proxy != proxy_url and client_key in _clients:
        # После «Обновить прокси» в платформе нельзя переиспользовать старый WS-клиент.
        old_client = _clients.pop(client_key)
        try:
            await old_client.disconnect()
        except Exception:
            pass
        print(
            f"[INFO] Dropped cached client for session {session_token[:10]}... "
            f"(proxy changed)",
        )

    # Проверяем, есть ли уже клиент для этого SSID
    if client_key in _clients:
        client = _clients[client_key]
        # Проверяем, подключен ли клиент
        if client.is_connected:
            _client_proxy_by_session[session_token] = proxy_url
            return client
        else:
            # Пытаемся переподключиться
            try:
                await client.connect()
                _client_proxy_by_session[session_token] = proxy_url
                return client
            except Exception as e:
                print(f"[ERROR] Не удалось переподключиться: {e}")
                # Удаляем неработающий клиент
                del _clients[client_key]
                _client_proxy_by_session.pop(session_token, None)
    
    # Создаем новый клиент (используем тот же подход, что в asset_scanner_websocket.py)
    print(f"[INFO] Создание нового клиента для sessionToken={session_token[:20]}... (demo={is_demo})")
    client = AsyncPocketOptionClient(
        ssid,
        is_demo=is_demo,
        enable_logging=False,
        persistent_connection=False,
        auto_reconnect=True,
        proxy_url=proxy_url
    )
    
    # Подключаемся
    try:
        print(f"[INFO] Подключение к PocketOption через WebSocket...")
        success = await client.connect()
        if not success:
            raise Exception("Не удалось подключиться к PocketOption")
        print(f"[INFO] Успешно подключено к PocketOption")
        
        # Даем серверу время обработать данные об активах (как в asset_scanner_websocket.py)
        print(f"[INFO] Ожидание инициализации данных...")
        await asyncio.sleep(3)  # Увеличиваем задержку до 3 секунд, как в asset_scanner_websocket.py
        
        # Проверяем, что клиент действительно подключен
        if not client.is_connected:
            raise Exception("Клиент не подключен после ожидания")
        print(f"[INFO] Клиент готов к работе")
    except Exception as e:
        print(f"[ERROR] Ошибка подключения: {e}")
        import traceback
        traceback.print_exc()
        raise
    
    # Сохраняем клиент
    _clients[client_key] = client
    _client_proxy_by_session[session_token] = proxy_url

    return client


def _ssid_client_cache_key(ssid: str) -> str:
    """Ключ кэша клиента по полной строке SSID (без хранения SSID в ключе словаря целиком)."""
    return "ssid:" + hashlib.sha256(ssid.encode("utf-8")).hexdigest()[:24]


def normalize_pocket_ssid(raw: str, is_demo: bool = True) -> str:
    """Полный SSID 42[\"auth\",...] из строки auth, JSON sessionToken или голого токена."""
    raw = (raw or "").strip()
    if raw.startswith('42["auth",'):
        import re as _re

        token = None
        m = _re.search(r'"session"\s*:\s*"([^"]+)"', raw)
        if m:
            token = m.group(1)
        else:
            m = _re.search(r'"sessionToken"\s*:\s*"([^"]+)"', raw)
            if m:
                token = m.group(1)
        if token:
            uid_m = _re.search(r'"uid"\s*:\s*"?(\d+)"?', raw)
            uid = int(uid_m.group(1)) if uid_m else 0
            demo_m = _re.search(r'"isDemo"\s*:\s*(\d+)', raw)
            demo = int(demo_m.group(1)) if demo_m else (1 if is_demo else 0)
            return (
                f'42["auth",{{"session":"{token}","uid":{uid},"isDemo":{demo},'
                f'"platform":2,"isFastHistory":true,"isOptimized":true}}]'
            )
        return raw
    if raw.startswith("{") or "sessionToken" in raw:
        trimmed = re.sub(r"\}\]+\s*$", "}", raw.strip())
        if not trimmed.endswith("}"):
            trimmed += "}"
        try:
            data = json.loads(trimmed)
        except json.JSONDecodeError:
            data = {}
            m = re.search(r'"sessionToken"\s*:\s*"([^"]+)"', raw)
            if m:
                data["sessionToken"] = m.group(1)
            m = re.search(r'"uid"\s*:\s*"?(\d+)"?', raw)
            if m:
                data["uid"] = int(m.group(1))
        token = data.get("sessionToken") or data.get("session")
        if token:
            uid = int(data.get("uid") or data.get("userId") or 0)
            demo = int(data.get("isDemo", 1 if is_demo else 0))
            return (
                f'42["auth",{{"session":"{token}","uid":{uid},"isDemo":{demo},'
                f'"platform":2,"isFastHistory":true,"isOptimized":true}}]'
            )
    if len(raw) >= 16 and raw.replace("_", "").replace("-", "").isalnum():
        demo = 1 if is_demo else 0
        return (
            f'42["auth",{{"session":"{raw}","uid":0,"isDemo":{demo},'
            f'"platform":2,"isFastHistory":true,"isOptimized":true}}]'
        )
    raise HTTPException(status_code=400, detail="Invalid SSID format (expected 42[\"auth\",...] or JSON sessionToken)")


async def get_or_create_client_by_ssid(
    ssid: str,
    is_demo: bool = True,
    proxy_url: Optional[str] = None,
) -> AsyncPocketOptionClient:
    """
    Клиент Pocket Option по полной строке SSID из профиля пользователя (42["auth",...]).
    proxy_url — тот же прокси, что у ingest для пары (если прямое подключение недоступно).
    """
    ssid = normalize_pocket_ssid(ssid, is_demo=is_demo)

    proxy_tag = hashlib.sha256((proxy_url or "").encode("utf-8")).hexdigest()[:8]
    client_key = f"{_ssid_client_cache_key(ssid)}:{proxy_tag}"
    if client_key in _clients:
        client = _clients[client_key]
        if client.is_connected:
            return client
        try:
            await client.connect()
            if client.is_connected:
                return client
        except Exception:
            pass
        del _clients[client_key]

    print(
        f"[INFO] Создание клиента по SSID (demo={is_demo}, proxy={'yes' if proxy_url else 'no'})...",
        flush=True,
    )
    # persistent_connection — стабильнее openOrder под нагрузкой ingest.
    client = AsyncPocketOptionClient(
        ssid,
        is_demo=is_demo,
        enable_logging=False,
        persistent_connection=True,
        auto_reconnect=True,
        proxy_url=proxy_url,
    )
    try:
        success = await client.connect()
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"Pocket Option connect error: {exc}") from exc
    if not success:
        raise HTTPException(status_code=502, detail="Failed to connect to Pocket Option (connect returned false)")
    # Ingest и зеркало делят event loop — даём WS время на auth; при нагрузке 5 с мало.
    for attempt in range(2):
        await asyncio.sleep(6 if attempt == 0 else 4)
        if client.is_connected:
            _clients[client_key] = client
            return client
        try:
            await client.connect()
        except Exception:
            pass

    raise HTTPException(
        status_code=502,
        detail="Pocket Option client not connected after auth (SSID устарел — скопируйте новый 42[\"auth\",...] из DevTools кабинета PO)",
    )


async def get_candles_data(
    client: AsyncPocketOptionClient,
    instrument: str,
    timeframe: int,
    count: int = 100,
    end_time: Optional[datetime] = None,
) -> List[Dict[str, Any]]:
    """Получает данные свечей для актива (использует тот же подход, что в asset_scanner_websocket.py)
    Включает обработку тиков из stream для формирования текущей свечи"""
    # Словарь для формирования свечей из тиков (как в asset_scanner_websocket.py)
    stream_candles = {}  # {timestamp: candle_dict}
    all_candles_dict = {}  # {timestamp: candle_dict} - все свечи (завершенные + формирующиеся)
    on_json_data = None  # Инициализируем для доступа в except блоках
    
    try:
        # Конвертируем timeframe из минут в секунды
        period = timeframe * 60
        
        # Убираем префикс # из начала названия актива (API не принимает #)
        if instrument.startswith('#'):
            instrument_clean = instrument[1:]
        else:
            instrument_clean = instrument
        
        # Проверяем, нужно ли конвертировать имя актива
        if '/' not in instrument_clean and ' ' not in instrument_clean:
            # Актив уже в формате WebSocket (например, "EURUSD_otc")
            ws_asset_name = instrument_clean
        else:
            # Конвертируем имя актива в формат WebSocket
            ws_asset_name = convert_ui_asset_to_websocket(instrument_clean)
        
        # Определяем правильный ключ из ASSETS (улучшенная логика поиска)
        asset_key_for_api = None
        
        # 1. Прямой поиск
        if ws_asset_name in ASSETS:
            asset_key_for_api = ws_asset_name
        elif ('#' + ws_asset_name) in ASSETS:
            asset_key_for_api = '#' + ws_asset_name
        else:
            # 2. Поиск без суффикса _otc (если актив с _otc не найден)
            if ws_asset_name.endswith('_otc'):
                base_name = ws_asset_name[:-4]  # Убираем _otc
                if base_name in ASSETS:
                    asset_key_for_api = base_name
                elif ('#' + base_name) in ASSETS:
                    asset_key_for_api = '#' + base_name
            
            # 3. Поиск похожих активов (без учета регистра и префикса #)
            if not asset_key_for_api:
                similar = [a for a in ASSETS.keys() if ws_asset_name.upper() == a.upper().replace('#', '')]
                if similar:
                    asset_key_for_api = similar[0]
            
            # 4. Поиск по частичному совпадению (если все еще не найдено)
            if not asset_key_for_api:
                # Ищем активы, которые содержат название или наоборот
                for key in ASSETS.keys():
                    key_normalized = key.upper().replace('#', '').replace('_OTC', '').replace('_otc', '')
                    ws_normalized = ws_asset_name.upper().replace('_OTC', '').replace('_otc', '')
                    if key_normalized == ws_normalized:
                        asset_key_for_api = key
                        break
        
        if not asset_key_for_api:
            # Выводим похожие активы для отладки
            similar_debug = [a for a in ASSETS.keys() if ws_asset_name.upper().replace('_OTC', '').replace('_otc', '') in a.upper().replace('#', '').replace('_OTC', '').replace('_otc', '') or a.upper().replace('#', '').replace('_OTC', '').replace('_otc', '') in ws_asset_name.upper().replace('_OTC', '').replace('_otc', '')]
            error_msg = f"Актив {ws_asset_name} не найден в ASSETS"
            if similar_debug:
                error_msg += f". Похожие активы: {similar_debug[:5]}"
            raise ValueError(error_msg)
        
        # Определяем актив для запроса (как в asset_scanner_websocket.py)
        if asset_key_for_api.startswith('#'):
            asset_for_request = asset_key_for_api  # С префиксом # для команды changeSymbol
        else:
            # Используем найденный ключ из ASSETS, но если запрос был с _otc, пробуем добавить _otc
            if ws_asset_name.endswith('_otc') and not asset_key_for_api.endswith('_otc'):
                asset_for_request = asset_key_for_api + '_otc'
                if asset_for_request not in ASSETS and ('#' + asset_for_request) not in ASSETS:
                    asset_for_request = asset_key_for_api
            else:
                asset_for_request = asset_key_for_api
        
        # Проверяем подключение перед запросом
        if not client.is_connected:
            print(f"[WARNING] Клиент не подключен, пытаемся переподключиться...")
            try:
                await client.connect()
                await asyncio.sleep(2)  # Даем время на подключение
            except Exception as e:
                raise HTTPException(status_code=503, detail=f"WebSocket connection lost: {str(e)}")
        
        # Обработчик JSON data для получения готовых свечей и тиков (как в asset_scanner_websocket.py)
        def on_json_data(data):
            """Обработчик JSON data для получения тиков и формирования свечей"""
            try:
                # Обработка готовых свечей и истории
                if isinstance(data, dict) and 'asset' in data:
                    asset = data.get('asset', '')
                    
                    # Проверяем совпадение актива (как в asset_scanner_websocket.py)
                    asset_normalized = asset.replace('#', '').upper()
                    ws_asset_normalized = ws_asset_name.replace('#', '').upper()
                    asset_key_normalized = asset_key_for_api.replace('#', '').upper() if asset_key_for_api else ''
                    asset_request_normalized = asset_for_request.replace('#', '').upper() if asset_for_request else ''
                    
                    asset_match = (
                        asset == ws_asset_name or 
                        asset == asset_key_for_api or
                        asset == asset_for_request or
                        asset_normalized == ws_asset_normalized or
                        (asset_key_normalized and asset_normalized == asset_key_normalized) or
                        (asset_request_normalized and asset_normalized == asset_request_normalized)
                    )
                    
                    if asset_match:
                        # Обработка готовых свечей (формат [timestamp, open, close, high, low])
                        if 'candles' in data:
                            candles_data = data.get('candles', [])
                            data_period = data.get('period', 60)
                            if candles_data and data_period == period:
                                for candle_data in candles_data:
                                    if len(candle_data) >= 5:
                                        timestamp = int(float(candle_data[0]))
                                        all_candles_dict[timestamp] = {
                                            'timestamp': timestamp,
                                            'open': float(candle_data[1]),
                                            'close': float(candle_data[2]),
                                            'high': float(candle_data[3]),
                                            'low': float(candle_data[4]),
                                            'source': 'websocket_candles'
                                        }
                        
                        # Обработка истории (тики для формирования текущей свечи)
                        if 'history' in data:
                            history = data.get('history', [])
                            if history:
                                for tick in history:
                                    if len(tick) >= 2:
                                        tick_timestamp = int(float(tick[0]))
                                        tick_value = float(tick[1])
                                        # Округляем timestamp до начала периода
                                        candle_timestamp = (tick_timestamp // period) * period
                                        
                                        if candle_timestamp not in stream_candles:
                                            stream_candles[candle_timestamp] = {
                                                'timestamp': candle_timestamp,
                                                'open': tick_value,
                                                'high': tick_value,
                                                'low': tick_value,
                                                'close': tick_value,
                                                'source': 'websocket_stream'
                                            }
                                        else:
                                            # Обновляем только если это не готовая свеча
                                            if stream_candles[candle_timestamp].get('source') != 'websocket_candles':
                                                candle = stream_candles[candle_timestamp]
                                                candle['high'] = max(candle['high'], tick_value)
                                                candle['low'] = min(candle['low'], tick_value)
                                                candle['close'] = tick_value
                
                # Тики приходят в формате [['ASSET', timestamp, value], ...]
                if isinstance(data, list) and len(data) > 0:
                    for tick_data in data:
                        if isinstance(tick_data, list) and len(tick_data) >= 3:
                            symbol, timestamp, value = tick_data[0], tick_data[1], tick_data[2]
                            
                            # Проверяем совпадение символа
                            symbol_normalized = symbol.replace('#', '').upper()
                            ws_asset_normalized = ws_asset_name.replace('#', '').upper()
                            symbol_match = (
                                symbol == ws_asset_name or 
                                symbol == asset_key_for_api or
                                symbol == asset_for_request or
                                symbol_normalized == ws_asset_normalized
                            )
                            
                            if symbol_match:
                                tick_timestamp = int(float(timestamp))
                                tick_value = float(value)
                                
                                # Группируем тики в свечи
                                candle_timestamp = (tick_timestamp // period) * period
                                
                                if candle_timestamp not in stream_candles:
                                    stream_candles[candle_timestamp] = {
                                        'timestamp': candle_timestamp,
                                        'open': tick_value,
                                        'high': tick_value,
                                        'low': tick_value,
                                        'close': tick_value,
                                        'source': 'websocket_stream'
                                    }
                                else:
                                    candle = stream_candles[candle_timestamp]
                                    if candle.get('source') != 'websocket_candles':
                                        candle['high'] = max(candle['high'], tick_value)
                                        candle['low'] = min(candle['low'], tick_value)
                                        candle['close'] = tick_value
            except Exception as e:
                pass  # Игнорируем ошибки в обработчике
        
        # Подписываемся на json_data для получения тиков (как в asset_scanner_websocket.py)
        client._websocket.add_event_handler("json_data", on_json_data)
        
        # Один быстрый запрос через changeSymbol (макс. 100 свечей)
        request_end = end_time or datetime.now()
        candles_count = min(max(count, 1), 100)
        use_to = end_time is not None
        print(
            f"[INFO] Запрос свечей: asset={asset_for_request}, period={period}, count={candles_count}, "
            f"send_to={use_to} end={request_end if use_to else 'latest'}",
        )
        try:
            candles = await asyncio.wait_for(
                client._request_candles(
                    asset_for_request,
                    period,
                    candles_count,
                    request_end,
                    send_to=use_to,
                ),
                timeout=15.0,
            )
        except asyncio.TimeoutError:
            print(f"[WARNING] Таймаут при запросе свечей для {asset_for_request}")
            candles = []
        except Exception as e:
            print(f"[WARNING] Ошибка при запросе свечей: {e}")
            candles = []

        # Добавляем завершенные свечи в all_candles_dict
        if candles:
            for candle in candles:
                if hasattr(candle.timestamp, 'timestamp'):
                    timestamp = int(candle.timestamp.timestamp())
                else:
                    timestamp = int(candle.timestamp)
                
                all_candles_dict[timestamp] = {
                    'timestamp': timestamp,
                    'open': float(candle.open),
                    'close': float(candle.close),
                    'high': float(candle.high),
                    'low': float(candle.low),
                    'volume': float(candle.volume) if hasattr(candle, 'volume') else 0.0,
                    'source': 'request_candles'
                }
        
        # Краткое ожидание stream updates для формирующихся свечей (минимально для скорости)
        wait_for_stream = 1
        print(f"[INFO] Ожидание stream updates ({wait_for_stream} сек)...")
        await asyncio.sleep(wait_for_stream)
        
        # Добавляем формирующиеся свечи из stream (которые еще не закрыты)
        for ts, candle_dict in stream_candles.items():
            # Проверяем, что это свеча, которой нет в завершенных свечах
            if ts not in all_candles_dict:
                all_candles_dict[ts] = candle_dict
                all_candles_dict[ts]['volume'] = 0.0  # Формирующиеся свечи не имеют объема
        
        # ИЗМЕНЕНИЕ: Удаляем обработчик в основном коде (как в asset_scanner_websocket.py)
        client._websocket.remove_event_handler("json_data", on_json_data)
        
        # ИЗМЕНЕНИЕ: Проверяем данные из stream перед выбрасыванием исключения (как в asset_scanner_websocket.py)
        if not all_candles_dict:
            # Если нет данных вообще, только тогда выбрасываем исключение
            raise ValueError(f"Не получено свечей для актива {instrument}")
        
        # Конвертируем в список и сортируем по timestamp
        candles_data = []
        for ts in sorted(all_candles_dict.keys()):
            candle = all_candles_dict[ts]
            candles_data.append({
                "time": candle['timestamp'],
                "open": candle['open'],
                "close": candle['close'],
                "high": candle['high'],
                "low": candle['low'],
                "volume": candle.get('volume', 0.0)
            })
        
        print(f"[INFO] Получено {len(candles_data)} свечей (завершенных: {len([c for c in all_candles_dict.values() if c.get('source') != 'websocket_stream'])}), формирующихся: {len([c for c in all_candles_dict.values() if c.get('source') == 'websocket_stream'])})")
        
        return candles_data
        
    except ValueError as e:
        # ИЗМЕНЕНИЕ: Удаляем обработчик в блоке except (как в asset_scanner_websocket.py)
        try:
            if on_json_data is not None:
                client._websocket.remove_event_handler("json_data", on_json_data)
        except:
            pass
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        # ИЗМЕНЕНИЕ: Удаляем обработчик в блоке except (как в asset_scanner_websocket.py)
        try:
            if on_json_data is not None:
                client._websocket.remove_event_handler("json_data", on_json_data)
        except:
            pass
        print(f"[ERROR] Ошибка при получении свечей: {e}")
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=f"Error getting candles: {str(e)}")


async def generate_candlestick_chart(
    candles_data: List[Dict[str, Any]],
    instrument: str,
    timeframe: int
) -> bytes:
    """
    Генерирует свечной график из данных свечей (минутные свечи)
    """
    if not candles_data:
        raise ValueError("No candles data")
    
    # Берем последние 60 свечей для графика (1 час минутных свечей)
    last_candles = candles_data[-60:] if len(candles_data) > 60 else candles_data
    
    # Подготовка данных
    times = [dt.fromtimestamp(c['time']) for c in last_candles]
    opens = [c['open'] for c in last_candles]
    highs = [c['high'] for c in last_candles]
    lows = [c['low'] for c in last_candles]
    closes = [c['close'] for c in last_candles]
    
    # Создаем график с большим размером для лучшей читаемости
    fig, ax = plt.subplots(figsize=(16, 9), facecolor='white')
    
    # Рисуем свечи
    for i, (time, open_price, high, low, close) in enumerate(zip(times, opens, highs, lows, closes)):
        # Определяем цвет свечи (зеленый для роста, красный для падения)
        color = '#26a69a' if close >= open_price else '#ef5350'
        line_color = '#1b5e20' if close >= open_price else '#b71c1c'
        
        # Тень (wick) - вертикальная линия от high до low
        ax.plot([i, i], [low, high], color=line_color, linewidth=2, zorder=1, alpha=0.8)
        
        # Тело свечи
        body_height = abs(close - open_price)
        body_bottom = min(open_price, close)
        
        if body_height < 0.0001:  # Doji свеча (открытие = закрытие)
            ax.plot([i-0.35, i+0.35], [body_bottom, body_bottom], 
                   color=line_color, linewidth=2.5, zorder=2)
        else:
            # Рисуем тело свечи как прямоугольник
            rect = plt.Rectangle((i - 0.35, body_bottom), 0.7, body_height, 
                               facecolor=color, edgecolor=line_color, 
                               linewidth=1.5, zorder=2, alpha=0.9)
            ax.add_patch(rect)
    
    # Настройка графика
    ax.set_xlabel('Время (минуты)', fontsize=12, fontweight='bold', color='#333')
    ax.set_ylabel('Цена', fontsize=12, fontweight='bold', color='#333')
    ax.set_title(f'{instrument} - Минутные свечи ({timeframe}м) | Последние {len(last_candles)} свечей', 
                 fontsize=16, fontweight='bold', pad=20, color='#333')
    
    # Настройка сетки
    ax.grid(True, alpha=0.3, linestyle='--', linewidth=0.8, color='gray')
    ax.set_axisbelow(True)
    
    # Устанавливаем границы по X
    ax.set_xlim(-0.5, len(last_candles) - 0.5)
    
    # Добавляем ценовые метки
    price_min = min(lows)
    price_max = max(highs)
    price_range = price_max - price_min
    ax.set_ylim(price_min - price_range * 0.05, price_max + price_range * 0.05)
    
    # Форматирование оси Y (цены)
    ax.yaxis.set_major_formatter(plt.FuncFormatter(lambda x, p: f'{x:.5f}'))
    ax.tick_params(axis='y', labelsize=10)
    
    # Форматирование оси X (время)
    ax.tick_params(axis='x', labelsize=9)
    
    # Добавляем метки времени каждые 10 свечей
    if len(last_candles) > 10:
        step = max(1, len(last_candles) // 6)
        tick_positions = list(range(0, len(last_candles), step))
        tick_labels = [times[i].strftime('%H:%M') for i in tick_positions]
        ax.set_xticks(tick_positions)
        ax.set_xticklabels(tick_labels, rotation=45, ha='right')
    
    # Добавляем информацию о последней цене
    last_price = closes[-1]
    last_change = closes[-1] - opens[-1]
    last_change_pct = (last_change / opens[-1] * 100) if opens[-1] != 0 else 0
    
    # Текст с информацией
    info_text = f'Последняя цена: {last_price:.5f} | Изменение: {last_change:+.5f} ({last_change_pct:+.2f}%)'
    ax.text(0.02, 0.98, info_text, transform=ax.transAxes, 
           fontsize=11, verticalalignment='top',
           bbox=dict(boxstyle='round', facecolor='wheat', alpha=0.8))
    
    # Сохраняем в буфер
    img_buffer = io.BytesIO()
    plt.savefig(img_buffer, format='png', dpi=100, bbox_inches='tight', 
                facecolor='white', edgecolor='none', pad_inches=0.2)
    img_buffer.seek(0)
    plt.close(fig)
    
    return img_buffer.read()


@app.get("/api/chart")
async def get_chart(
    instrument: str = Query(..., description="Название пары (например, EURUSD, AUDCAD_otc)"),
    timeframe: int = Query(1, description="Таймфрейм в минутах (по умолчанию 1 для минутных свечей)"),
    sessionToken: str = Query(..., description="Session token из Pocket Options"),
    uid: str = Query("0", description="UID из Pocket Options (опционально)"),
    candles_count: int = Query(60, description="Количество свечей для отображения (макс. 100, по умолчанию 60)")
):
    """
    Генерирует свечной график минутных свечей и возвращает его как изображение PNG
    
    Параметры:
    - instrument: название пары
    - timeframe: таймфрейм в минутах (по умолчанию 1 для минутных свечей)
    - sessionToken: ваш sessionToken
    - uid: ваш UID (опционально)
    - candles_count: количество свечей для отображения (по умолчанию 60)
    
    Возвращает:
    PNG изображение графика
    """
    print(f"[HTTP] GET /api/chart?instrument={instrument}&timeframe={timeframe}&candles_count={candles_count}")
    
    try:
        client = await get_or_create_client(sessionToken, uid)
        candles_data = await get_candles_data(client, instrument, timeframe, min(candles_count, 100))
        
        if not candles_data:
            raise HTTPException(status_code=404, detail="No candles data")
        
        # Генерируем график (для минутных свечей используем timeframe=1)
        chart_image = await generate_candlestick_chart(candles_data, instrument, timeframe)
        
        print(f"[HTTP] Successfully generated chart for {instrument} ({timeframe}m)")
        
        return Response(content=chart_image, media_type="image/png")
        
    except HTTPException:
        raise
    except Exception as e:
        print(f"[ERROR] Ошибка в /api/chart: {e}")
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=f"Error generating chart: {str(e)}")


@app.post("/api/chart")
async def get_chart_from_data(
    request: dict = Body(...)
):
    """
    Генерирует свечной график из предоставленных данных свечей (без запроса к WebSocket)
    
    Body должен содержать:
    {
        "candles": [...],  # массив данных свечей
        "instrument": "EURUSD",  # название пары
        "timeframe": 1  # таймфрейм
    }
    
    Возвращает PNG изображение графика
    """
    print(f"[HTTP] POST /api/chart (from provided data)")
    
    try:
        candles_data = request.get("candles", [])
        instrument = request.get("instrument", "UNKNOWN")
        timeframe = request.get("timeframe", 1)
        
        if not candles_data:
            raise HTTPException(status_code=400, detail="No candles data provided")
        
        # Генерируем график из предоставленных данных
        chart_image = await generate_candlestick_chart(candles_data, instrument, timeframe)
        
        print(f"[HTTP] Successfully generated chart for {instrument} ({timeframe}m) from provided data")
        
        return Response(content=chart_image, media_type="image/png")
        
    except HTTPException:
        raise
    except Exception as e:
        print(f"[ERROR] Ошибка в POST /api/chart: {e}")
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=f"Error generating chart: {str(e)}")


def draw_candles_ascii(candles_data: List[Dict[str, Any]], num_candles: int = 15) -> str:
    """Рисует ASCII-график свечей для вывода в консоль (адаптировано из asset_scanner_websocket.py)
    
    Args:
        candles_data: список словарей с данными свечей {time, open, close, high, low}
        num_candles: количество свечей для отображения (по умолчанию 15)
    
    Returns:
        str: многострочная строка с ASCII-графиком
    """
    if not candles_data or len(candles_data) < 2:
        return ""
    
    # Сортируем свечи по времени (от старых к новым)
    sorted_candles = sorted(candles_data, key=lambda c: c.get('time', 0))
    last_candles = sorted_candles[-num_candles:] if len(sorted_candles) >= num_candles else sorted_candles
    
    if len(last_candles) < 2:
        return ""
    
    # Находим min и max для масштабирования
    all_prices = []
    for candle in last_candles:
        all_prices.extend([candle.get('open', 0), candle.get('close', 0), 
                          candle.get('high', 0), candle.get('low', 0)])
    
    if not all_prices:
        return ""
    
    min_price = min(all_prices)
    max_price = max(all_prices)
    price_range = max_price - min_price
    
    if price_range == 0:
        price_range = 0.0001  # Избегаем деления на ноль
    
    # Высота графика (в строках)
    height = 12
    width_per_candle = 6  # Ширина одной свечи
    
    # Создаем матрицу для графика
    grid = [[' ' for _ in range(len(last_candles) * width_per_candle)] for _ in range(height)]
    
    # Рисуем каждую свечу
    for candle_idx, candle in enumerate(last_candles):
        x_start = candle_idx * width_per_candle + 2  # Отступ слева
        
        # Масштабируем цены
        def price_to_y(price):
            normalized = (price - min_price) / price_range
            return int(height - 1 - normalized * (height - 2))  # Инвертируем (0 внизу)
        
        high_y = price_to_y(candle.get('high', 0))
        low_y = price_to_y(candle.get('low', 0))
        open_y = price_to_y(candle.get('open', 0))
        close_y = price_to_y(candle.get('close', 0))
        
        # Определяем цвет свечи
        is_green = candle.get('close', 0) > candle.get('open', 0)
        body_top = min(open_y, close_y)
        body_bottom = max(open_y, close_y)
        
        # Рисуем тень (wick) - вертикальная линия от high до low
        wick_x = x_start + 3
        for y in range(low_y, high_y + 1):
            if 0 <= y < height:
                grid[y][wick_x] = '│'
        
        # Рисуем тело свечи (открытие и закрытие)
        body_left = x_start + 1
        body_right = x_start + 5
        
        # Если тело очень маленькое (doji), рисуем горизонтальную линию
        if abs(body_top - body_bottom) <= 0:
            body_y = body_top
            if 0 <= body_y < height:
                for x in range(body_left, body_right + 1):
                    if 0 <= x < len(grid[0]):
                        grid[body_y][x] = '─'
        else:
            # Рисуем тело свечи
            for y in range(body_top, body_bottom + 1):
                if 0 <= y < height:
                    for x in range(body_left, body_right + 1):
                        if 0 <= x < len(grid[0]):
                            grid[y][x] = '█'
    
    # Формируем строку с графиком
    lines = []
    
    # Добавляем ценовые метки слева
    for y in range(height):
        if y == 0:
            price_label = f"{max_price:.5f}"
        elif y == height - 1:
            price_label = f"{min_price:.5f}"
        else:
            price_label = ""
        
        line = f"  {price_label:>10} │" + "".join(grid[y])
        lines.append(line)
    
    # Добавляем время под графиком
    time_line = "  " + " " * 12 + "│"
    for candle_idx, candle in enumerate(last_candles):
        x_start = candle_idx * width_per_candle + 2
        # Форматируем время из Unix timestamp
        timestamp = candle.get('time', 0)
        if timestamp:
            try:
                from datetime import datetime, timezone
                dt_obj = datetime.fromtimestamp(timestamp, tz=timezone.utc)
                time_str = dt_obj.strftime('%H:%M')
            except:
                time_str = str(timestamp)[:5]
        else:
            time_str = "??:??"
        
        # Центрируем время под свечой
        time_pos = x_start + 1
        time_line += " " * max(0, (time_pos - len(time_line) + 12)) + time_str
    
    lines.append(time_line)
    
    # Добавляем цвета свечей под временем
    color_line = "  " + " " * 12 + "│"
    for candle_idx, candle in enumerate(last_candles):
        x_start = candle_idx * width_per_candle + 2
        # Определяем цвет свечи
        if candle.get('close', 0) > candle.get('open', 0):
            color_char = "🟢"
        elif candle.get('close', 0) < candle.get('open', 0):
            color_char = "🔴"
        else:
            color_char = "⚪"
        
        color_pos = x_start + 2
        color_line += " " * max(0, (color_pos - len(color_line) + 12)) + color_char
    
    lines.append(color_line)
    
    return "\n".join(lines)


def get_all_assets_from_category(category_name: Optional[str] = None) -> List[str]:
    """Получает список активов из указанной категории или всех активов
    
    Args:
        category_name: Название категории или None для всех активов
    
    Returns:
        Список названий активов
    """
    # Используем статический список из ASSETS (как в asset_scanner_websocket.py)
    if category_name:
        # Если категория указана, фильтруем активы по категориям
        category_assets = {
            'Валюты': [a for a in ASSETS.keys() if '_otc' in a or any(fx in a for fx in ['EUR', 'GBP', 'USD', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD', 'RUB', 'HUF', 'NOK'])],
            'Криптовалюты': [a for a in ASSETS.keys() if any(crypto in a for crypto in ['BTC', 'ETH', 'DASH', 'BCH', 'DOT', 'LNK'])],
            'Сырьевые товары': [a for a in ASSETS.keys() if any(comm in a for comm in ['XAU', 'XAG', 'BRENT', 'CRUDE', 'XNG', 'XPT', 'XPD'])],
            'Акции': [a for a in ASSETS.keys() if a.startswith('#')],
            'Индексы': [a for a in ASSETS.keys() if any(idx in a for idx in ['SP500', 'NAS', 'DJI', 'JPN', 'D30', 'E50', 'F40', 'E35', '100GBP', 'AUS200', 'CAC', 'AEX', 'SMI', 'H33'])]
        }
        return category_assets.get(category_name, [])
    else:
        # Возвращаем все доступные активы
        return list(ASSETS.keys())


@app.get("/api/assets")
async def get_assets(
    category: Optional[str] = Query(None, description="Название категории (Валюты, Криптовалюты, Сырьевые товары, Акции, Индексы) или None для всех активов")
):
    """
    Получает список всех активов и категорий (как в asset_scanner_websocket.py)
    
    Параметры:
    - category: название категории (опционально). Если не указано, возвращает все активы и категории
    
    Возвращает:
    JSON объект с категориями и активами
    """
    print(f"[HTTP] GET /api/assets?category={category}")
    
    try:
        available_categories = ['Валюты', 'Криптовалюты', 'Сырьевые товары', 'Акции', 'Индексы']
        
        if category:
            # Возвращаем активы из указанной категории
            if category not in available_categories:
                raise HTTPException(status_code=400, detail=f"Категория '{category}' не найдена. Доступные категории: {', '.join(available_categories)}")
            
            assets = get_all_assets_from_category(category)
            return JSONResponse(content={
                "category": category,
                "assets": assets,
                "count": len(assets)
            })
        else:
            # Возвращаем все категории с их активами
            result = {
                "categories": {},
                "all_assets": list(ASSETS.keys()),
                "total_assets": len(ASSETS.keys())
            }
            
            for cat in available_categories:
                assets = get_all_assets_from_category(cat)
                result["categories"][cat] = {
                    "assets": assets,
                    "count": len(assets)
                }
            
            return JSONResponse(content=result)
        
    except HTTPException:
        raise
    except Exception as e:
        print(f"[ERROR] Ошибка в /api/assets: {e}")
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=f"Internal server error: {str(e)}")


@app.get("/api/candles")
async def get_candles(
    instrument: str = Query(..., description="Название пары (например, EURUSD, AUDCAD_otc, ETHUSD_otc)"),
    timeframe: int = Query(..., description="Таймфрейм в минутах (1, 5, 15, 30, 60, 240)"),
    sessionToken: str = Query(..., description="Session token из Pocket Options"),
    uid: str = Query("0", description="UID из Pocket Options (опционально)"),
    count: int = Query(100, description="Желаемое количество свечей (при >100 делаем несколько запросов в прошлое)"),
    include_chart: bool = Query(False, description="Включить график в ответ (только для timeframe=1, возвращает изображение вместо JSON)")
):
    """
    Получает данные свечей для указанного актива
    
    Параметры:
    - instrument: название пары (например, EURUSD, AUDCAD_otc, ETHUSD_otc)
    - timeframe: таймфрейм в минутах (1, 5, 15, 30, 60, 240)
    - sessionToken: ваш sessionToken из Pocket Options
    - uid: ваш UID из Pocket Options (опционально)
    - include_chart: если True и timeframe=1, возвращает PNG изображение графика вместо JSON
    
    Возвращает:
    - Если include_chart=True и timeframe=1: PNG изображение графика
    - Иначе: JSON объект с массивом свечей
    """
    print(f"[HTTP] GET /api/candles?instrument={instrument}&timeframe={timeframe}&count={count}&uid={uid}&include_chart={include_chart}")
    
    try:
        client = await get_or_create_client(sessionToken, uid)
        candles_count = 60 if include_chart and timeframe == 1 else min(max(count, 1), 1000)
        candles_data = await get_candles_data(client, instrument, timeframe, candles_count)
        
        print(f"[HTTP] Successfully retrieved {len(candles_data)} candles for {instrument}")
        
        # Если запрошен график для M1, возвращаем изображение
        if include_chart and timeframe == 1:
            if not candles_data:
                raise HTTPException(status_code=404, detail="No candles data")
            
            # Генерируем график
            chart_image = await generate_candlestick_chart(candles_data, instrument, timeframe)
            print(f"[HTTP] Successfully generated chart for {instrument} ({timeframe}m)")
            return Response(content=chart_image, media_type="image/png")
        
        # Иначе возвращаем JSON с данными свечей
        # Выводим ASCII-график в консоль
        if candles_data:
            candle_chart = draw_candles_ascii(candles_data, num_candles=15)
            if candle_chart:
                print(f"\n📈 График свечей для {instrument} ({timeframe}м):", flush=True)
                print(candle_chart, flush=True)
                print()  # Пустая строка для разделения
        
        return JSONResponse(content={
            "candles": candles_data,
            "instrument": instrument,
            "timeframe": str(timeframe)
        })
        
    except HTTPException:
        raise
    except Exception as e:
        print(f"[ERROR] Ошибка в /api/candles: {e}")
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=f"Internal server error: {str(e)}")


def _normalize_order_asset(instrument: str) -> str:
    """Имя актива для openOrder: как в ingest (без #), без жёсткого whitelist ASSETS."""
    name = (instrument or "").strip()
    if name.startswith("#"):
        name = name[1:]
    if "/" in name or " " in name:
        name = convert_ui_asset_to_websocket(name)
    return name.replace("#", "")


def _mirror_order_confirmed(result: Any) -> bool:
    """
    PO API при таймауте ответа создаёт «fallback» OrderResult со status=ACTIVE,
    но сделки в кабинете нет — не считаем такой ордер успешным.
    """
    err = getattr(result, "error_message", None) or ""
    if err and "Timeout waiting for server confirmation" in str(err):
        return False
    return True


async def place_mirror_order(
    client: AsyncPocketOptionClient,
    instrument: str,
    amount: float,
    direction: OrderDirection,
    duration: int,
) -> Any:
    """
    Размещение ордера без проверки asset in ASSETS — ingest уже торгует теми же парами.
    """
    asset = _normalize_order_asset(instrument)
    if (
        amount < API_LIMITS["min_order_amount"]
        or amount > API_LIMITS["max_order_amount"]
    ):
        raise InvalidParameterError(
            f"Amount must be between {API_LIMITS['min_order_amount']} and {API_LIMITS['max_order_amount']}"
        )
    if (
        duration < API_LIMITS["min_duration"]
        or duration > API_LIMITS["max_duration"]
    ):
        raise InvalidParameterError(
            f"Duration must be between {API_LIMITS['min_duration']} and {API_LIMITS['max_duration']} seconds"
        )

    request_id = str(uuid.uuid4())
    order = Order(
        asset=asset,
        amount=amount,
        direction=direction,
        duration=duration,
        request_id=request_id,
    )
    await client._send_order(order)
    result = await client._wait_for_order_result(
        request_id, order, timeout=MIRROR_ORDER_CONFIRM_TIMEOUT_SEC
    )
    if not _mirror_order_confirmed(result):
        # Ещё несколько секунд ждём ответ PO в том же WS — без active-orders и без нового openOrder.
        result = await client._wait_for_order_result(
            request_id, order, timeout=MIRROR_ORDER_CONFIRM_GRACE_SEC
        )
    if not _mirror_order_confirmed(result):
        raise HTTPException(
            status_code=502,
            detail="Pocket Option не подтвердил ордер (нет successopenOrder). "
            "Проверьте SSID, demo/real и что пара доступна для торговли.",
        )
    return result


@app.post("/api/test-auth")
async def test_auth_api(payload: Dict[str, Any] = Body(...)):
    """
    Проверка SSID: подключение к PO без ордера (для профиля и artisan profile:test-po-connection).
    """
    ssid = str(payload.get("ssid") or "").strip()
    is_demo = bool(payload.get("is_demo", True))
    proxy_url = payload.get("proxy_url")
    if proxy_url is not None:
        proxy_url = str(proxy_url).strip() or None

    if not ssid:
        raise HTTPException(status_code=400, detail="Required: ssid")

    try:
        await get_or_create_client_by_ssid(ssid, is_demo=is_demo, proxy_url=proxy_url)
        return {"ok": True}
    except HTTPException as exc:
        return {"ok": False, "error": exc.detail}
    except Exception as exc:
        return {"ok": False, "error": str(exc)}


@app.post("/api/active-orders")
async def active_orders_api(payload: Dict[str, Any] = Body(...)):
    """
    Активные ордера на PO (ещё не истекли по таймеру сделки).
    Использует тот же persistent WebSocket-клиент, что и place-order.
    """
    ssid = str(payload.get("ssid") or "").strip()
    is_demo = bool(payload.get("is_demo", True))
    proxy_url = payload.get("proxy_url")
    if proxy_url is not None:
        proxy_url = str(proxy_url).strip() or None

    if not ssid:
        raise HTTPException(status_code=400, detail="Required: ssid")

    try:
        client = await get_or_create_client_by_ssid(ssid, is_demo=is_demo, proxy_url=proxy_url)
        raw_orders = await client.get_active_orders()
        now = datetime.now()
        active: List[Dict[str, Any]] = []
        for order in raw_orders:
            expires_at = order.expires_at
            if expires_at <= now:
                continue
            direction = order.direction
            direction_str = direction.value if hasattr(direction, "value") else str(direction)
            active.append(
                {
                    "order_id": str(order.order_id),
                    "asset": str(order.asset),
                    "amount": float(order.amount),
                    "direction": direction_str,
                    "duration": int(order.duration),
                    "expires_at": expires_at.isoformat(),
                    "seconds_remaining": max(0, int((expires_at - now).total_seconds())),
                }
            )
        return JSONResponse(content={"ok": True, "count": len(active), "orders": active})
    except HTTPException:
        raise
    except Exception as exc:
        print(f"[ERROR] active-orders: {exc}", flush=True)
        return JSONResponse(content={"ok": False, "count": 0, "error": str(exc)}, status_code=502)


@app.post("/api/place-order")
async def place_order_api(
    payload: Dict[str, Any] = Body(...),
):
    """
    Размещает бинарный опцион (зеркало paper-сделки бота на платформе).
    Тело: ssid, instrument, side (CALL|PUT), amount, duration (сек), is_demo (bool).
    """
    ssid = str(payload.get("ssid") or "").strip()
    instrument = str(payload.get("instrument") or "").strip()
    side = str(payload.get("side") or "").upper().strip()
    amount = float(payload.get("amount") or 0)
    duration = int(payload.get("duration") or 0)
    is_demo = bool(payload.get("is_demo", True))
    proxy_url = payload.get("proxy_url")
    if proxy_url is not None:
        proxy_url = str(proxy_url).strip() or None

    if not ssid or not instrument or side not in ("CALL", "PUT") or amount <= 0 or duration <= 0:
        raise HTTPException(
            status_code=400,
            detail="Required: ssid, instrument, side (CALL|PUT), amount > 0, duration > 0",
        )

    asset_clean = _normalize_order_asset(instrument)
    direction = OrderDirection.CALL if side == "CALL" else OrderDirection.PUT

    print(
        f"[HTTP] POST /api/place-order instrument={asset_clean} side={side} amount={amount} duration={duration}s demo={is_demo}",
        flush=True,
    )

    try:
        async with _place_order_semaphore:
            client = await get_or_create_client_by_ssid(ssid, is_demo=is_demo, proxy_url=proxy_url)
            result = await place_mirror_order(
                client,
                asset_clean,
                amount,
                direction,
                duration,
            )
        order_id = getattr(result, "order_id", None) or getattr(result, "id", None)
        status = getattr(result, "status", None)
        return JSONResponse(
            content={
                "ok": True,
                "confirmed": True,
                "order_id": str(order_id) if order_id is not None else None,
                "status": str(status) if status is not None else None,
                "instrument": asset_clean,
                "side": side,
                "duration": duration,
            }
        )
    except HTTPException:
        raise
    except Exception as e:
        print(f"[ERROR] place-order: {e}", flush=True)
        import traceback

        traceback.print_exc()
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/")
async def root():
    """Корневой endpoint"""
    return {
        "service": "Pocket Options WebSocket Proxy",
        "version": "1.0.0",
        "endpoints": {
            "/api/assets": "GET - Получить список всех активов и категорий",
            "/api/candles": "GET - Получить данные свечей (может возвращать график PNG при include_chart=true и timeframe=1)",
            "/api/chart": "GET - Получить свечной график (PNG изображение)",
            "/api/test-auth": "POST - Проверить SSID (без ордера)",
            "/api/active-orders": "POST - Активные ордера на PO (для мартингейла)",
            "/api/place-order": "POST - Разместить ордер (зеркало сделки бота)",
        }
    }


@app.get("/health")
async def health():
    """Health check endpoint"""
    return {"status": "ok", "clients": len(_clients)}


if __name__ == "__main__":
    print("=" * 60)
    print("Pocket Options WebSocket Proxy Server")
    print("=" * 60)
    print(f"Running on http://{HOST}:{PORT}")
    print(f"Available at:")
    print(f"  - http://localhost:{PORT}")
    print(f"  - http://127.0.0.1:{PORT}")
    print(f"  - http://[::1]:{PORT} (IPv6)")
    print("=" * 60)
    
    uvicorn.run(app, host=HOST, port=PORT, log_level="info")


