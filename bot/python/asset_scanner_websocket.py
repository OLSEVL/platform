# -*- coding: utf-8 -*-
"""
Скрипт для сканирования активов и поиска наиболее выгодных точек входа по price action
Использует WebSocket API вместо веб-интерфейса

ИСПОЛЬЗОВАНИЕ:
1. Установите зависимости:
   pip install -r PocketOptionAPI-main/requirements.txt

2. Получите SSID из браузера:
   - Откройте PocketOption в браузере
   - Нажмите F12 (Developer Tools)
   - Перейдите на вкладку Network
   - Отфильтруйте по WebSocket (WS)
   - Найдите исходящее сообщение (стрелка ↑) с 42["auth"
   - Пример: ↑ 42["auth", {"session":"...","uid":"...",...}]
   - Скопируйте полное сообщение, начиная с 42["auth" и до закрывающей скобки ]

   Пример: 42["auth",{"session":"clke08kq3aoo658bg04itgkftf","isDemo":1,"uid":115392503,"platform":2,"isFastHistory":true,"isOptimized":true}]

3. Запустите скрипт:
   python asset_scanner_websocket.py

4. Введите SSID при запросе (или нажмите Enter для демо-режима)

ОСОБЕННОСТИ:
- Работает через WebSocket API (без Selenium)
- Быстрое получение данных
- Поддержка всех категорий активов
"""
import sys
import io
# Настройка кодировки для Windows консоли
if sys.platform == 'win32':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace', line_buffering=True)
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace', line_buffering=True)

import asyncio
import time
import os
from datetime import datetime, timezone
from typing import List, Dict, Optional, Any
from collections import defaultdict

# Selenium больше не используется - payout получается из WebSocket

# Импорт GUI (если доступен)
try:
    import tkinter as tk
    from gui_results_viewer import init_gui, get_gui
    GUI_AVAILABLE = True
except ImportError:
    GUI_AVAILABLE = False
    print("⚠ Tkinter недоступен. Будет использован вывод в терминал.")

# Импорт PocketOption API
# Добавляем путь к PocketOptionAPI-main в начало sys.path для правильных импортов
pocketoption_path = os.path.abspath(os.path.join(os.path.dirname(__file__), 'PocketOptionAPI-main'))
if pocketoption_path not in sys.path:
    sys.path.insert(0, pocketoption_path)

# Импортируем после добавления пути
from pocketoptionapi_async import AsyncPocketOptionClient
from pocketoptionapi_async.constants import ASSETS

# Настройки сканирования
SCAN_PERIOD = 60  # Период для анализа (секунды)
MIN_DATA_POINTS = 6  # Минимальное количество свечей для анализа (нужно минимум 6)
MIN_PAYOUT = 80  # Минимальный процент выплаты для фильтрации активов

# Глобальный словарь для хранения payout по активам
payout_cache: Dict[str, float] = {}


class PayoutCache:
    """Класс для управления кэшем payout данных"""
    
    def __init__(self):
        self._cache: Dict[str, float] = {}
    
    def add(self, symbol: str, payout: float) -> None:
        """Добавляет payout в кэш со всеми вариантами имени"""
        if not symbol or payout is None:
            return
        
        payout_float = float(payout)
        self._cache[symbol] = payout_float
        
        # Сохраняем варианты с префиксом # и без него
        if symbol.startswith('#'):
            self._cache[symbol[1:]] = payout_float
        else:
            self._cache[f'#{symbol}'] = payout_float
        
        # Дополнительно сохраняем вариант без префикса # и с префиксом (если есть в ASSETS)
        if symbol.startswith('#'):
            symbol_without_hash = symbol[1:]
            if symbol_without_hash in ASSETS:
                self._cache[symbol_without_hash] = payout_float
        else:
            symbol_with_hash = f'#{symbol}'
            if symbol_with_hash in ASSETS:
                self._cache[symbol_with_hash] = payout_float
    
    def get(self, asset_name: str, debug: bool = False) -> Optional[float]:
        """Получает payout из кэша (проверяет все возможные варианты имени)"""
        if debug:
            gui_log(f"[DEBUG] get_payout: ищем '{asset_name}'", "DEBUG")
            gui_log(f"[DEBUG] Размер кэша: {len(self._cache)}", "DEBUG")
        
        # Сначала проверяем точное совпадение
        if asset_name in self._cache:
            if debug:
                gui_log(f"[DEBUG] ✓ Найдено точное совпадение: '{asset_name}' -> {self._cache[asset_name]}%", "DEBUG")
            return self._cache[asset_name]
        
        # Проверяем вариант с префиксом # и без (только если есть в ASSETS)
        if asset_name.startswith('#'):
            asset_without_hash = asset_name[1:]
            if asset_without_hash in ASSETS:
                result = self._cache.get(asset_without_hash)
                if result and debug:
                    gui_log(f"[DEBUG] ✓ Найдено без префикса #: '{asset_without_hash}' -> {result}%", "DEBUG")
                return result
        else:
            asset_with_hash = f'#{asset_name}'
            if asset_with_hash in ASSETS:
                result = self._cache.get(asset_with_hash)
                if result and debug:
                    gui_log(f"[DEBUG] ✓ Найдено с префиксом #: '{asset_with_hash}' -> {result}%", "DEBUG")
                return result
        
        return None
    
    def has(self, asset_name: str) -> bool:
        """Проверяет наличие payout для актива"""
        return self.get(asset_name) is not None
    
    def __len__(self) -> int:
        return len(self._cache)
    
    def __getitem__(self, key: str) -> float:
        return self._cache[key]
    
    def __setitem__(self, key: str, value: float) -> None:
        self.add(key, value)
    
    def __contains__(self, key: str) -> bool:
        return key in self._cache
    
    def get_dict(self) -> Dict[str, float]:
        """Возвращает внутренний словарь (для совместимости)"""
        return self._cache


# Глобальный экземпляр кэша payout
payout_cache = PayoutCache()


def safe_print(*args, **kwargs):
    """Безопасный вывод в терминал (обрабатывает ошибки с закрытым файлом)"""
    # Формируем сообщение для вывода
    message = ' '.join(str(arg) for arg in args)
    
    # Пытаемся вывести в терминал
    try:
        # Убеждаемся, что stdout доступен и не закрыт
        if sys.stdout and not sys.stdout.closed:
            print(*args, **kwargs, flush=True, file=sys.stdout)
    except (ValueError, OSError, AttributeError, io.UnsupportedOperation):
        # Игнорируем ошибки с закрытым файлом или недоступным stdout
        pass
    
    # Также выводим в GUI, если доступен (для всех сообщений)
    if GUI_AVAILABLE and get_gui():
        try:
            # Определяем уровень логирования на основе содержимого сообщения
            level = "INFO"
            if "✓" in message or "успешно" in message.lower() or "завершен" in message.lower():
                level = "SUCCESS"
            elif "⚠" in message or "предупреждение" in message.lower() or "warning" in message.lower():
                level = "WARNING"
            elif "❌" in message or "ошибка" in message.lower() or "error" in message.lower():
                level = "ERROR"
            elif "[DEBUG]" in message or "debug" in message.lower():
                level = "DEBUG"
            
            get_gui().add_log(message, level)
        except:
            pass


def gui_log(message: str, level: str = "INFO"):
    """Отправить сообщение в GUI (если доступен) и в терминал"""
    if GUI_AVAILABLE and get_gui():
        try:
            get_gui().add_log(message, level)
        except Exception:
            pass  # Игнорируем ошибки GUI
    
    # Пытаемся вывести в терминал, но обрабатываем ошибки с закрытым файлом
    try:
        print(message, flush=True)
    except (ValueError, OSError):
        # Файл закрыт или недоступен - игнорируем
        pass


class SimpleCandle:
    """Простой класс для представления свечи (совместим с объектами Candle из API)"""
    def __init__(self, timestamp, open_price, close_price, high_price, low_price):
        # Конвертируем timestamp в datetime, если это int
        if isinstance(timestamp, (int, float)):
            self.timestamp = datetime.fromtimestamp(timestamp, tz=timezone.utc)
        else:
            self.timestamp = timestamp
        self.open = open_price
        self.close = close_price
        self.high = high_price
        self.low = low_price


def analyze_price_action(candles: List[Any], period: int) -> Optional[Dict[str, Any]]:
    """Простой анализ: проверка на 6 свечей подряд одного цвета
    
    Args:
        candles: список объектов свечей (Candle objects с open, close, high, low, timestamp)
        period: период свечи в секундах (не используется в упрощенной версии)
    
    Returns:
        dict: Результат анализа с сигналом CALL/PUT/WAIT
    """
    # Нужно минимум 6 свечей для проверки
    if len(candles) < MIN_DATA_POINTS:
        return None
    
    analysis = {
        'asset': None,
        'data_points': len(candles),
        'payout': None,
        'recommended_action': None,  # 'CALL', 'PUT', 'WAIT'
        'candle_colors': []  # Цвета последних 6 свечей: 'green' или 'red'
    }
    
    # ВАЖНО: Сортируем свечи по timestamp (от старых к новым) для гарантированного правильного порядка
    sorted_candles = sorted(candles, key=lambda c: c.timestamp)
    
    # Берем ПОСЛЕДНИЕ 6 свечей (самые новые)
    last_6_candles_list = sorted_candles[-MIN_DATA_POINTS:] if len(sorted_candles) >= MIN_DATA_POINTS else sorted_candles
    
    candle_colors = []
    for candle in last_6_candles_list:
        # Определяем цвет свечи: зеленая если close > open, красная если close < open
        # Это стандартное определение цвета свечи в трейдинге
        if not hasattr(candle, 'open') or not hasattr(candle, 'close'):
            candle_colors.append('neutral')
            continue
        
        open_price = float(candle.open)
        close_price = float(candle.close)
        
        if close_price > open_price:
            candle_colors.append('green')  # Зеленая свеча (рост)
        elif close_price < open_price:
            candle_colors.append('red')  # Красная свеча (падение)
        else:
            candle_colors.append('neutral')  # Нейтральная (без изменений)
    
    # Проверяем, что у нас ровно 6 свечей
    if len(candle_colors) < MIN_DATA_POINTS:
        analysis['candle_colors'] = candle_colors
        analysis['recommended_action'] = 'WAIT'
        return analysis
    
    analysis['candle_colors'] = candle_colors
    
    # Проверяем, все ли 6 свечей одного цвета
    if len(candle_colors) == MIN_DATA_POINTS:
        if all(color == 'green' for color in candle_colors):
            # Все 6 свечей зеленые - сигнал CALL
            analysis['recommended_action'] = 'CALL'
        elif all(color == 'red' for color in candle_colors):
            # Все 6 свечей красные - сигнал PUT
            analysis['recommended_action'] = 'PUT'
        else:
            # Свечи разного цвета - ждем
            analysis['recommended_action'] = 'WAIT'
    else:
        # Недостаточно данных
        analysis['recommended_action'] = 'WAIT'
    
    return analysis


# Функция convert_ui_asset_to_websocket удалена - используйте normalize_asset_name вместо неё


# Функции normalize_asset_name и get_asset_name_variants удалены - теперь используются только пары из словаря ASSETS


async def get_asset_data(client: AsyncPocketOptionClient, asset_name: str, period: int = 60, count: int = 100, wait_for_stream: int = 1, is_debug: bool = False):
    """Получает данные по активу через WebSocket API, включая формирующиеся свечи
    
    Args:
        client: Экземпляр AsyncPocketOptionClient
        asset_name: Название актива
        period: Период свечи в секундах
        count: Количество свечей для получения
        wait_for_stream: Время ожидания stream updates в секундах
        is_debug: Включить детальное логирование для отладки
    
    Returns:
        tuple: (список цен закрытия, список объектов свечей, период свечи)
    """
    try:
        # Убираем префикс # из начала названия актива (API не принимает #)
        if asset_name.startswith('#'):
            asset_name_clean = asset_name[1:]  # Убираем первый символ #
            if is_debug:
                print(f"    [DEBUG] Убран префикс # из названия: {asset_name} -> {asset_name_clean}", flush=True)
        else:
            asset_name_clean = asset_name
        
        # Проверяем, что актив есть в ASSETS (используем имя как есть или с префиксом #)
        asset_key_for_api = None
        
        # Сначала проверяем точное совпадение
        if asset_name_clean in ASSETS:
            asset_key_for_api = asset_name_clean
        elif ('#' + asset_name_clean) in ASSETS:
            asset_key_for_api = '#' + asset_name_clean
        elif asset_name_clean.startswith('#') and asset_name_clean[1:] in ASSETS:
            asset_key_for_api = asset_name_clean[1:]
        
        if is_debug:
            if asset_key_for_api:
                safe_print(f"    [DEBUG] Актив {asset_name_clean} найден в ASSETS (ключ: {asset_key_for_api}, ID: {ASSETS[asset_key_for_api]})")
            else:
                safe_print(f"    [DEBUG] ВНИМАНИЕ: Актив {asset_name_clean} НЕ найден в ASSETS!")
        
        # Если актив не найден в ASSETS, выбрасываем исключение
        if not asset_key_for_api:
            raise ValueError(f"Актив {asset_name_clean} не найден в ASSETS. Используйте только активы из словаря ASSETS.")
        
        ws_asset_name = asset_key_for_api
        
        # Определяем актив для запроса
        # ВАЖНО: Для акций с префиксом # (например, #AAPL_otc, #MCD_otc) API может требовать префикс # в changeSymbol
        # Но request_id формируется из asset в ответе, который может быть без префикса
        # Пробуем отправлять с префиксом # для акций, которые есть в ASSETS с префиксом
        if asset_key_for_api and asset_key_for_api.startswith('#'):
            # Для акций с префиксом # отправляем с префиксом в changeSymbol
            asset_for_request = asset_key_for_api  # С префиксом # для команды changeSymbol
            asset_for_request_id = ws_asset_name  # Без префикса для request_id (если API вернет без #)
        else:
            asset_for_request = ws_asset_name  # Без префикса
            asset_for_request_id = ws_asset_name
        
        all_candles = []  # Список всех свечей (завершенных + формирующихся)
        stream_candles = {}  # Словарь для формирования свечей из тиков: {timestamp: candle_dict}
        json_data_received = []  # Для отладки: список полученных json_data
        
        # Обработчик JSON data для получения готовых свечей и тиков
        # ВАЖНО: Этот обработчик должен быть неблокирующим, чтобы не мешать обработке ответов в _on_json_data клиента
        def on_json_data(data):
            """Обработчик JSON data для получения тиков и формирования свечей"""
            try:
                # ЛОГИРУЕМ ВСЕ входящие сообщения для отладки
                if is_debug:
                    json_data_received.append(str(type(data))[:100])
                    # Логируем ВСЕ сообщения, даже если они не dict
                    print(f"    [DEBUG] ===== ВХОДЯЩЕЕ json_data сообщение =====", flush=True)
                    print(f"    [DEBUG] Тип данных: {type(data)}", flush=True)
                    if isinstance(data, dict):
                        asset_in_data = data.get('asset', 'N/A')
                        all_keys = list(data.keys())
                        print(f"    [DEBUG] json_data (dict): asset={asset_in_data}, keys={all_keys}", flush=True)
                        # Выводим полное содержимое для анализа
                        print(f"    [DEBUG] Полное содержимое: {data}", flush=True)
                        # Логируем содержимое для анализа
                        if 'candles' in data:
                            candles_count = len(data.get('candles', []))
                            print(f"    [DEBUG]   candles: {candles_count} штук", flush=True)
                            if candles_count > 0:
                                print(f"    [DEBUG]   первая свеча: {data.get('candles', [])[0] if data.get('candles') else 'N/A'}", flush=True)
                        if 'history' in data:
                            history_count = len(data.get('history', []))
                            print(f"    [DEBUG]   history: {history_count} тиков", flush=True)
                            if history_count > 0:
                                print(f"    [DEBUG]   первый тик: {data.get('history', [])[0] if data.get('history') else 'N/A'}", flush=True)
                        if 'payout' in data:
                            payout_val = data.get('payout')
                            print(f"    [DEBUG]   ⚠ PAYOUT НАЙДЕН В ДАННЫХ: {payout_val}%", flush=True)
                            # Сохраняем payout в кэш
                            asset_in_data = data.get('asset', '')
                            if asset_in_data:
                                payout_cache.add(asset_in_data, payout_val)
                                if is_debug:
                                    safe_print(f"    [DEBUG]   ✓ Payout сохранен в кэш для {asset_in_data}")
                        # Логируем period для проверки совпадения
                        if 'period' in data:
                            print(f"    [DEBUG]   period: {data.get('period')} (ожидаем {period})", flush=True)
                    elif isinstance(data, list):
                        print(f"    [DEBUG] json_data (list): список из {len(data)} элементов", flush=True)
                        print(f"    [DEBUG] Полное содержимое списка: {data}", flush=True)
                        if len(data) > 0 and isinstance(data[0], list) and len(data[0]) >= 3:
                            print(f"    [DEBUG]   первый элемент: {data[0][:3]}", flush=True)
                    else:
                        print(f"    [DEBUG] json_data (другой тип): {data}", flush=True)
                    print(f"    [DEBUG] ===== КОНЕЦ json_data сообщения =====", flush=True)
                
                # Обработка тиков в формате списка [['ASSET', timestamp, price], ...]
                # Это тиковые данные, которые приходят для разных активов одновременно
                if isinstance(data, list) and len(data) > 0:
                    for tick_data in data:
                        if isinstance(tick_data, list) and len(tick_data) >= 3:
                            tick_asset = tick_data[0]  # Название актива
                            tick_timestamp = float(tick_data[1])  # Timestamp
                            tick_price = float(tick_data[2])  # Цена
                            
                            # Проверяем, относится ли тик к нашему активу
                            tick_asset_normalized = str(tick_asset).replace('#', '').upper()
                            ws_asset_normalized = ws_asset_name.replace('#', '').upper()
                            
                            if tick_asset_normalized == ws_asset_normalized:
                                # Округляем timestamp до начала свечи
                                candle_timestamp = int((int(tick_timestamp) // period) * period)
                                
                                if is_debug and len(stream_candles) < 5:
                                    print(f"    [DEBUG] Обработка тика: asset={tick_asset}, timestamp={tick_timestamp}, price={tick_price}, candle_ts={candle_timestamp}", flush=True)
                                
                                # Создаем или обновляем свечу
                                if candle_timestamp not in stream_candles:
                                    stream_candles[candle_timestamp] = {
                                        'timestamp': candle_timestamp,
                                        'open': tick_price,
                                        'high': tick_price,
                                        'low': tick_price,
                                        'close': tick_price,
                                        'source': 'websocket_tick'
                                    }
                                else:
                                    # Обновляем только если это не готовая свеча
                                    if stream_candles[candle_timestamp].get('source') != 'websocket_candles':
                                        candle = stream_candles[candle_timestamp]
                                        candle['high'] = max(candle['high'], tick_price)
                                        candle['low'] = min(candle['low'], tick_price)
                                        candle['close'] = tick_price  # Обновляем close на последнюю цену
                
                # Обработка готовых свечей и истории
                if isinstance(data, dict) and 'asset' in data:
                    asset = data.get('asset', '')
                    
                    if is_debug:
                        # Логируем все сообщения с asset для анализа
                        period_in_data = data.get('period', 'N/A')
                        has_candles = 'candles' in data
                        has_history = 'history' in data
                        print(f"    [DEBUG] json_data с asset: {asset}, period={period_in_data}, candles={has_candles}, history={has_history}", flush=True)
                        if has_candles:
                            candles_count = len(data.get('candles', []))
                            print(f"    [DEBUG]   количество candles: {candles_count}", flush=True)
                    
                    # API может возвращать активы как с префиксом #, так и без него
                    # Сравниваем с обоими вариантами: ws_asset_name (без #) и asset_key_for_api (с # если есть)
                    # Также сравниваем с asset_for_request (который был отправлен в changeSymbol)
                    # Улучшенная логика совпадения: проверяем все возможные варианты названий
                    asset_normalized = asset.replace('#', '').upper()
                    ws_asset_normalized = ws_asset_name.replace('#', '').upper()
                    asset_key_normalized = asset_key_for_api.replace('#', '').upper() if asset_key_for_api else ''
                    asset_request_normalized = asset_for_request.replace('#', '').upper() if asset_for_request else ''
                    
                    asset_match = (
                        asset == ws_asset_name or 
                        asset == asset_key_for_api or
                        asset == asset_for_request or
                        (ws_asset_name.startswith('#') and asset == ws_asset_name[1:]) or
                        (not ws_asset_name.startswith('#') and asset == '#' + ws_asset_name) or
                        # Дополнительные проверки с нормализацией (без учета регистра и префикса #)
                        asset_normalized == ws_asset_normalized or
                        (asset_key_normalized and asset_normalized == asset_key_normalized) or
                        (asset_request_normalized and asset_normalized == asset_request_normalized)
                    )
                    
                    if is_debug:
                        if asset_match:
                            print(f"    [DEBUG] ✓ Совпадение актива! asset={asset} совпадает с одним из: {ws_asset_name}, {asset_key_for_api}, {asset_for_request}", flush=True)
                        else:
                            print(f"    [DEBUG] ✗ Несовпадение актива: asset={asset} (ожидаем {ws_asset_name} или {asset_key_for_api} или {asset_for_request})", flush=True)
                            print(f"    [DEBUG]   Нормализованные: asset={asset_normalized}, ws={ws_asset_normalized}, key={asset_key_normalized}, request={asset_request_normalized}", flush=True)
                    if asset_match:
                        # Проверяем, есть ли payout в данных
                        if 'payout' in data:
                            payout_value = data.get('payout')
                            if payout_value is not None:
                                # Сохраняем payout в кэш для этого актива
                                payout_cache.add(asset, payout_value)
                        
                        # Обработка готовых свечей (как в po_bot.py)
                        if 'candles' in data:
                            candles_data = data.get('candles', [])
                            data_period = data.get('period', 60)
                            if candles_data and data_period == period:
                                # Свечи приходят в формате [timestamp, open, close, high, low]
                                for candle_data in candles_data:
                                    if len(candle_data) >= 5:
                                        timestamp = int(float(candle_data[0]))
                                        if timestamp not in stream_candles:
                                            stream_candles[timestamp] = {
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
                                # Группируем тики по свечам (1-минутные)
                                for tick in history:
                                    if len(tick) >= 2:
                                        tick_timestamp = int(float(tick[0]))
                                        tick_value = float(tick[1])
                                        # Округляем timestamp до начала минуты
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
                            # API может возвращать активы как с префиксом #, так и без него
                            # Сравниваем с обоими вариантами: ws_asset_name (без #) и asset_key_for_api (с # если есть)
                            # Также сравниваем с asset_for_request (который был отправлен в changeSymbol)
                            symbol_match = (symbol == ws_asset_name or 
                                          symbol == asset_key_for_api or
                                          symbol == asset_for_request or
                                          (ws_asset_name.startswith('#') and symbol == ws_asset_name[1:]) or
                                          (not ws_asset_name.startswith('#') and symbol == '#' + ws_asset_name))
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
        
        # Подписываемся на json_data для получения тиков
        client._websocket.add_event_handler("json_data", on_json_data)
        
        # Получаем завершенные свечи (это также активирует stream для актива)
        # asset_for_request уже определен выше (с префиксом # для акций, если нужно)
        if is_debug:
            print(f"    [DEBUG] Запрос свечей: asset={asset_for_request} (для changeSymbol), period={period}, count={max(count, 150)}", flush=True)
            print(f"    [DEBUG] asset_for_request_id={asset_for_request_id} (для совпадения request_id в ответе)", flush=True)
            # Проверяем подключение (is_connected - это метод)
            try:
                is_conn = client.is_connected()
            except (TypeError, AttributeError):
                # Если is_connected не метод, пробуем как свойство
                is_conn = getattr(client, 'is_connected', False)
            print(f"    [DEBUG] Подключение к WebSocket: {is_conn}", flush=True)
            print(f"    [DEBUG] request_id в запросе будет: {asset_for_request}_{period}", flush=True)
            print(f"    [DEBUG] НО: request_id в ответе может быть: {asset_for_request_id}_{period} (если API вернет без #)", flush=True)
        try:
            # Используем _request_candles напрямую с правильным форматом актива
            # Для акций передаем с префиксом #, для других - без
            # Добавляем таймаут, чтобы избежать зависания
            if is_debug:
                print(f"    [DEBUG] Отправка запроса свечей с таймаутом 15 секунд...", flush=True)
                print(f"    [DEBUG] Отправляем changeSymbol с asset={asset_for_request}", flush=True)
                print(f"    [DEBUG] request_id в запросе: {asset_for_request}_{period}", flush=True)
                print(f"    [DEBUG] Ожидаем ответ с asset={asset_for_request} или {asset_for_request_id} или {ws_asset_name}", flush=True)
                print(f"    [DEBUG] ВНИМАНИЕ: Слушаем ВСЕ входящие json_data сообщения для анализа...", flush=True)
            
            # Проверяем, какие request_id уже есть в _candle_requests
            if hasattr(client, '_candle_requests') and is_debug:
                existing_requests = list(client._candle_requests.keys())
                if existing_requests:
                    print(f"    [DEBUG] Существующие запросы: {existing_requests}", flush=True)
            
            candles = await asyncio.wait_for(
                client._request_candles(
                    asset_for_request, period, max(count, 150), None
                ),
                timeout=15.0,
            )
            if is_debug:
                print(f"    [DEBUG] Получено свечей из API: {len(candles) if candles else 0}", flush=True)
                if not candles or len(candles) == 0:
                    print(f"    [DEBUG] ВНИМАНИЕ: API вернул пустой список свечей для {ws_asset_name}", flush=True)
                    print(f"    [DEBUG] asset_for_request={asset_for_request}, ws_asset_name={ws_asset_name}, asset_key_for_api={asset_key_for_api}", flush=True)
                    print(f"    [DEBUG] Это может означать, что актив недоступен или не имеет данных", flush=True)
                    print(f"    [DEBUG] Будем ждать данных через WebSocket stream...", flush=True)
                else:
                    if is_debug:
                        print(f"    [DEBUG] Первая свеча: timestamp={candles[0].timestamp if candles else 'N/A'}, open={candles[0].open if candles else 'N/A'}", flush=True)
        except asyncio.TimeoutError:
            if is_debug:
                print(f"    [DEBUG] Таймаут при запросе свечей для {asset_for_request} (15 сек)", flush=True)
                print(f"    [DEBUG] Получено json_data сообщений во время ожидания: {len(json_data_received)}", flush=True)
                if json_data_received:
                    print(f"    [DEBUG] Типы полученных сообщений: {json_data_received[:10]}", flush=True)
                print(f"    [DEBUG] Проверяем, есть ли данные через WebSocket stream...", flush=True)
            candles = []
        except Exception as e:
            if is_debug:
                print(f"    [DEBUG] ИСКЛЮЧЕНИЕ при запросе свечей для {ws_asset_name}: {type(e).__name__}: {e}", flush=True)
                import traceback
                traceback.print_exc()
            # Не выбрасываем исключение, продолжаем работу и ждем данных через stream
            candles = []
        
        if candles:
            for candle in candles:
                if hasattr(candle.timestamp, 'timestamp'):
                    ts = int(candle.timestamp.timestamp())
                else:
                    ts = int(candle.timestamp)
                
                all_candles.append(SimpleCandle(
                    timestamp=ts,
                    open_price=float(candle.open),
                    close_price=float(candle.close),
                    high_price=float(candle.high),
                    low_price=float(candle.low)
                ))
        
        # Ждем stream updates для получения формирующихся свечей
        if is_debug:
            print(f"    [DEBUG] Ожидание WebSocket stream ({wait_for_stream} сек)...", flush=True)
        await asyncio.sleep(wait_for_stream)
        
        if is_debug:
            print(f"    [DEBUG] После ожидания: stream_candles={len(stream_candles)}, json_data сообщений={len(json_data_received)}", flush=True)
            if stream_candles:
                print(f"    [DEBUG] Примеры stream_candles timestamps: {list(stream_candles.keys())[:5]}", flush=True)
            else:
                print(f"    [DEBUG] ВНИМАНИЕ: Нет данных через WebSocket stream для {ws_asset_name}", flush=True)
                print(f"    [DEBUG] Получено json_data сообщений: {len(json_data_received)}", flush=True)
                if json_data_received:
                    print(f"    [DEBUG] Типы json_data сообщений: {set(json_data_received[:10])}", flush=True)
                print(f"    [DEBUG] Это может означать, что актив не торгуется или недоступен", flush=True)
                print(f"    [DEBUG] Проверьте, что актив доступен на платформе PocketOption", flush=True)
        
        # Добавляем формирующиеся свечи из stream (которые еще не закрыты)
        for ts, candle_dict in stream_candles.items():
            # Проверяем, что это свеча, которой нет в завершенных свечах
            # Сравниваем timestamp как int (конвертируем datetime в timestamp)
            ts_int = ts
            if not any(int(c.timestamp.timestamp()) == ts_int if hasattr(c.timestamp, 'timestamp') else c.timestamp == ts_int for c in all_candles):
                all_candles.append(SimpleCandle(
                    timestamp=candle_dict['timestamp'],
                    open_price=candle_dict['open'],
                    close_price=candle_dict['close'],
                    high_price=candle_dict['high'],
                    low_price=candle_dict['low']
                ))
        
        # Удаляем обработчик
        client._websocket.remove_event_handler("json_data", on_json_data)
        
        # Сортируем свечи по timestamp
        all_candles = sorted(all_candles, key=lambda c: c.timestamp)
        
        if not all_candles or len(all_candles) < MIN_DATA_POINTS:
            return [], [], period
        
        # Извлекаем цены закрытия
        values = [candle.close for candle in all_candles]
        
        return values, all_candles, period
        
    except Exception as e:
        # Детальное логирование для отладки
        if is_debug:
            print(f"    [DEBUG] ОШИБКА для {asset_name}: {e}", flush=True)
            import traceback
            print(f"    [DEBUG] Traceback:", flush=True)
            traceback.print_exc()
        else:
            print(f"    ⚠ Ошибка при получении данных для {asset_name}: {e}")
        # Пытаемся удалить обработчик в случае ошибки
        try:
            client._websocket.remove_event_handler("json_data", on_json_data)
        except:
            pass
        return [], [], period


def get_payout_from_cache(asset_name: str, debug: bool = False) -> Optional[float]:
    """Получает payout из кэша (проверяет все возможные варианты имени)
    
    Args:
        asset_name: Имя актива для поиска
        debug: Включить отладочный вывод
    """
    return payout_cache.get(asset_name, debug=debug)


def print_websocket_assets_table(assets: Dict[str, int]):
    """Выводит таблицу со всеми WebSocket активами, сгруппированными по категориям
    
    Args:
        assets: Словарь {asset_name: asset_id} из ASSETS
    """
    if not assets:
        print("  ⚠ Нет данных о WebSocket активах для отображения", flush=True)
        return
    
    # Определяем категории активов
    categories = {
        'Валюты': [],
        'Криптовалюты': [],
        'Сырьевые товары': [],
        'Акции': [],
        'Индексы': [],
        'Другие': []
    }
    
    # Группируем активы по категориям
    for asset_name, asset_id in assets.items():
        asset_upper = asset_name.upper()
        
        # Определяем категорию
        if asset_name.startswith('#'):
            categories['Акции'].append((asset_name, asset_id))
        elif any(fx in asset_upper for fx in ['EUR', 'GBP', 'USD', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD', 'RUB', 'HUF', 'NOK', 'TRY', 'ZAR', 'MXN', 'THB', 'SGD', 'HKD', 'INR', 'CNH', 'KRW', 'ILS', 'AED', 'CNY', 'LBP']):
            categories['Валюты'].append((asset_name, asset_id))
        elif any(crypto in asset_upper for crypto in ['BTC', 'ETH', 'DASH', 'BCH', 'DOT', 'LNK', 'LTC', 'XRP', 'DOGE', 'ADA', 'SOL', 'MATIC', 'LINK', 'UNI', 'AVAX', 'ATOM', 'ALGO', 'XLM', 'EOS', 'TRX', 'XMR', 'ZEC', 'ETC', 'BNB', 'SHIB', 'FIL', 'XTZ', 'VET', 'GRT', 'AAVE', 'MKR', 'COMP', 'YFI', 'SUSHI', 'CRV', '1INCH']):
            categories['Криптовалюты'].append((asset_name, asset_id))
        elif any(comm in asset_upper for comm in ['XAU', 'XAG', 'BRENT', 'CRUDE', 'XNG', 'XPT', 'XPD', 'XCU', 'GASOLINE', 'HEATINGOIL', 'NATGAS']):
            categories['Сырьевые товары'].append((asset_name, asset_id))
        elif any(idx in asset_upper for idx in ['SP500', 'SPX', 'NAS', 'DJI', 'JPN', 'D30', 'E50', 'F40', 'E35', '100GBP', 'AUS200', 'CAC', 'AEX', 'SMI', 'H33', 'NIKKEI', 'FTSE', 'HSI', 'SSE', 'ASX', 'TSX', 'IBEX', 'EUROSTOXX']):
            categories['Индексы'].append((asset_name, asset_id))
        else:
            categories['Другие'].append((asset_name, asset_id))
    
    # Сортируем активы в каждой категории по названию
    for category in categories:
        categories[category].sort(key=lambda x: x[0])
    
    # Выводим таблицу для каждой категории
    print("\n" + "=" * 100, flush=True)
    print("ВСЕ WEBSOCKET АКТИВЫ", flush=True)
    print("=" * 100, flush=True)
    
    total_assets = 0
    total_with_payout = 0
    
    for category_name, assets_list in categories.items():
        if not assets_list:
            continue
        
        total_assets += len(assets_list)
        print(f"\n📂 {category_name} ({len(assets_list)} активов):", flush=True)
        print("─" * 100, flush=True)
        print(f"{'№':<5} {'ID':<10} {'Актив':<50} {'Payout':<15}", flush=True)
        print("─" * 100, flush=True)
        
        for idx, (asset_name, asset_id) in enumerate(assets_list, 1):
            # Проверяем наличие payout
            payout = get_payout_from_cache(asset_name)
            payout_str = f"{payout:.1f}%" if payout is not None else "N/A"
            
            if payout is not None:
                total_with_payout += 1
            
            # Ограничиваем длину имени актива для читаемости
            asset_display = asset_name[:48] if len(asset_name) <= 48 else asset_name[:45] + "..."
            
            print(f"{idx:<5} {asset_id:<10} {asset_display:<50} {payout_str:<15}", flush=True)
        
        # Статистика по категории
        if assets_list:
            category_with_payout = sum(1 for a, _ in assets_list if get_payout_from_cache(a) is not None)
            print("─" * 100, flush=True)
            print(f"  Всего: {len(assets_list)} | С payout: {category_with_payout} | Без payout: {len(assets_list) - category_with_payout}", flush=True)
    
    print("\n" + "=" * 100, flush=True)
    print(f"ИТОГО: {total_assets} WebSocket активов | С payout: {total_with_payout} | Без payout: {total_assets - total_with_payout}", flush=True)
    print("=" * 100 + "\n", flush=True)


def print_payout_table(payouts: Dict[str, float]):
    """Выводит таблицу с payout для всех активов, сгруппированных по категориям
    
    Args:
        payouts: Словарь {asset_name: payout_percentage}
    """
    if not payouts:
        print("  ⚠ Нет данных о payout для отображения", flush=True)
        return
    
    # Определяем категории активов
    categories = {
        'Валюты': [],
        'Криптовалюты': [],
        'Сырьевые товары': [],
        'Акции': [],
        'Индексы': [],
        'Другие': []
    }
    
    # Группируем активы по категориям
    for asset_name, payout in payouts.items():
        asset_upper = asset_name.upper()
        
        # Определяем категорию
        if asset_name.startswith('#'):
            categories['Акции'].append((asset_name, payout))
        elif any(fx in asset_upper for fx in ['EUR', 'GBP', 'USD', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD', 'RUB', 'HUF', 'NOK', 'TRY', 'ZAR', 'MXN', 'THB', 'SGD', 'HKD', 'INR', 'CNH', 'KRW', 'ILS', 'AED', 'CNY', 'LBP']):
            categories['Валюты'].append((asset_name, payout))
        elif any(crypto in asset_upper for crypto in ['BTC', 'ETH', 'DASH', 'BCH', 'DOT', 'LNK', 'LTC', 'XRP', 'DOGE', 'ADA', 'SOL', 'MATIC', 'LINK', 'UNI', 'AVAX', 'ATOM', 'ALGO', 'XLM', 'EOS', 'TRX', 'XMR', 'ZEC', 'ETC', 'BNB', 'SHIB', 'FIL', 'XTZ', 'VET', 'GRT', 'AAVE', 'MKR', 'COMP', 'YFI', 'SUSHI', 'CRV', '1INCH']):
            categories['Криптовалюты'].append((asset_name, payout))
        elif any(comm in asset_upper for comm in ['XAU', 'XAG', 'BRENT', 'CRUDE', 'XNG', 'XPT', 'XPD', 'XCU', 'GASOLINE', 'HEATINGOIL', 'NATGAS']):
            categories['Сырьевые товары'].append((asset_name, payout))
        elif any(idx in asset_upper for idx in ['SP500', 'SPX', 'NAS', 'DJI', 'JPN', 'D30', 'E50', 'F40', 'E35', '100GBP', 'AUS200', 'CAC', 'AEX', 'SMI', 'H33', 'NIKKEI', 'FTSE', 'HSI', 'SSE', 'ASX', 'TSX', 'IBEX', 'EUROSTOXX']):
            categories['Индексы'].append((asset_name, payout))
        else:
            categories['Другие'].append((asset_name, payout))
    
    # Сортируем активы в каждой категории по payout (по убыванию)
    for category in categories:
        categories[category].sort(key=lambda x: x[1], reverse=True)
    
    # Выводим таблицу для каждой категории
    # Отправляем данные в GUI (если доступен)
    if GUI_AVAILABLE and get_gui():
        # Создаем словарь категорий для GUI
        category_dict = {}
        for category_name, assets in categories.items():
            for asset_name, payout in assets:
                category_dict[asset_name] = category_name
        get_gui().add_payout_data(payouts, category_dict)
        gui_log("\n" + "=" * 80)
        gui_log("ТАБЛИЦА PAYOUT ПО АКТИВАМ")
        gui_log("=" * 80)
    else:
        print("\n" + "=" * 80, flush=True)
        print("ТАБЛИЦА PAYOUT ПО АКТИВАМ", flush=True)
        print("=" * 80, flush=True)
    
    total_assets = 0
    for category_name, assets in categories.items():
        if not assets:
            continue
        
        total_assets += len(assets)
        print(f"\n📂 {category_name} ({len(assets)} активов):", flush=True)
        print("─" * 80, flush=True)
        print(f"{'№':<4} {'Актив':<35} {'Payout':<10} {'Статус':<15}", flush=True)
        print("─" * 80, flush=True)
        
        for idx, (asset_name, payout) in enumerate(assets, 1):
            # Определяем статус для отображения в таблице (НЕ фильтрация, только визуализация)
            # Реальная фильтрация происходит позже в функции scan_assets (строки 1358-1395)
            if payout >= MIN_PAYOUT:
                status = "✓ Проходит"
            else:
                status = "✗ Пропущен"
            
            # Ограничиваем длину имени актива для читаемости
            asset_display = asset_name[:34] if len(asset_name) <= 34 else asset_name[:31] + "..."
            
            print(f"{idx:<4} {asset_display:<35} {payout:>6.1f}%   {status:<15}", flush=True)
        
        # Статистика по категории
        if assets:
            avg_payout = sum(p[1] for p in assets) / len(assets)
            max_payout = max(p[1] for p in assets)
            min_payout = min(p[1] for p in assets)
            passed_count = sum(1 for p in assets if p[1] >= MIN_PAYOUT)
            print("─" * 80, flush=True)
            print(f"  Средний payout: {avg_payout:.1f}% | Макс: {max_payout:.1f}% | Мин: {min_payout:.1f}% | Проходит фильтр: {passed_count}/{len(assets)}", flush=True)
    
    print("\n" + "=" * 80, flush=True)
    print(f"ИТОГО: {total_assets} активов с данными о payout", flush=True)
    print(f"Минимальный payout для фильтрации: {MIN_PAYOUT}%", flush=True)
    print("=" * 80 + "\n", flush=True)


# Все функции для работы с DOM удалены - payout теперь получается из WebSocket


def draw_candles_ascii(candles: List[Any], candle_colors: List[str], num_candles: int = 15) -> str:
    """Рисует ASCII-график свечей для визуального сравнения
    
    Args:
        candles: список объектов свечей
        candle_colors: список цветов свечей ('green', 'red', 'neutral')
        num_candles: количество свечей для отображения (по умолчанию 15)
    
    Returns:
        str: многострочная строка с ASCII-графиком
    """
    if not candles or len(candles) < 2:
        return ""
    
    # Сортируем свечи по timestamp (от старых к новым)
    sorted_candles = sorted(candles, key=lambda c: c.timestamp)
    last_candles = sorted_candles[-num_candles:] if len(sorted_candles) >= num_candles else sorted_candles
    
    if len(last_candles) < 2:
        return ""
    
    # Находим min и max для масштабирования
    all_prices = []
    for candle in last_candles:
        all_prices.extend([candle.open, candle.close, candle.high, candle.low])
    
    if not all_prices:
        return ""
    
    min_price = min(all_prices)
    max_price = max(all_prices)
    price_range = max_price - min_price
    
    if price_range == 0:
        price_range = 0.0001  # Избегаем деления на ноль
    
    # Высота графика (в строках)
    height = 12
    width_per_candle = 6  # Ширина одной свечи (уменьшена для 15 свечей)
    
    # Создаем матрицу для графика
    grid = [[' ' for _ in range(len(last_candles) * width_per_candle)] for _ in range(height)]
    
    # Рисуем каждую свечу
    for candle_idx, candle in enumerate(last_candles):
        x_start = candle_idx * width_per_candle + 2  # Отступ слева
        
        # Масштабируем цены
        def price_to_y(price):
            normalized = (price - min_price) / price_range
            return int(height - 1 - normalized * (height - 2))  # Инвертируем (0 внизу)
        
        high_y = price_to_y(candle.high)
        low_y = price_to_y(candle.low)
        open_y = price_to_y(candle.open)
        close_y = price_to_y(candle.close)
        
        # Определяем цвет свечи
        is_green = candle.close > candle.open
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
        # Форматируем время (timestamp теперь с UTC timezone после исправления библиотеки)
        if hasattr(candle.timestamp, 'strftime'):
            if candle.timestamp.tzinfo is None:
                # Если все еще naive (не должно быть после исправления), считаем UTC
                from datetime import timezone
                utc_time = candle.timestamp.replace(tzinfo=timezone.utc)
                local_time = utc_time.astimezone()
                time_str = local_time.strftime('%H:%M')
            else:
                # Timestamp с timezone (UTC), конвертируем в локальное
                local_time = candle.timestamp.astimezone()
                time_str = local_time.strftime('%H:%M')
        else:
            time_str = str(candle.timestamp)[:5]
        
        # Центрируем время под свечой
        time_pos = x_start + 1
        time_line += " " * max(0, (time_pos - len(time_line) + 12)) + time_str
    
    lines.append(time_line)
    
    # Добавляем цвета свечей под временем (только для последних 6, которые анализируются)
    color_line = "  " + " " * 12 + "│"
    # Показываем цвета только для последних 6 свечей
    last_6_for_colors = last_candles[-6:] if len(last_candles) >= 6 else last_candles
    for candle_idx, candle in enumerate(last_candles):
        x_start = candle_idx * width_per_candle + 2
        # Определяем цвет свечи
        if candle in last_6_for_colors:
            color_idx = last_6_for_colors.index(candle)
            if color_idx < len(candle_colors):
                color = candle_colors[color_idx]
                color_char = "🟢" if color == 'green' else "🔴" if color == 'red' else "⚪"
            else:
                # Если цвет не найден в анализе, определяем по open/close текущей свечи
                if candle.close > candle.open:
                    color_char = "🟢"
                elif candle.close < candle.open:
                    color_char = "🔴"
                else:
                    color_char = "⚪"
        else:
            # Для остальных свечей определяем цвет по open/close текущей свечи
            if candle.close > candle.open:
                color_char = "🟢"
            elif candle.close < candle.open:
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
    # Используем статический список из ASSETS
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


def _filter_assets_by_payout(asset_names: List[str], min_payout: float) -> tuple[List[str], List[tuple], List[str]]:
    """Фильтрует активы по минимальному payout
    
    Returns:
        tuple: (filtered_assets, skipped_low_payout, no_payout_data)
    """
    filtered_assets = []
    skipped_low_payout = []
    no_payout_data = []
    
    for asset_name in asset_names:
        payout = payout_cache.get(asset_name)
        if payout is not None:
            if payout >= min_payout:
                filtered_assets.append(asset_name)
            else:
                skipped_low_payout.append((asset_name, payout))
        else:
            no_payout_data.append(asset_name)
    
    return filtered_assets, skipped_low_payout, no_payout_data


def _print_scan_header(category_name: Optional[str] = None) -> None:
    """Выводит заголовок сканирования"""
    if GUI_AVAILABLE and get_gui():
        get_gui().clear_results()
        get_gui().add_log("=" * 80)
        get_gui().add_log("СКАНЕР АКТИВОВ - Поиск лучших точек входа по Price Action (WebSocket)")
        get_gui().add_log("=" * 80)
        get_gui().add_log(f"Время начала: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        get_gui().add_log(f"Период анализа: {SCAN_PERIOD} секунд")
        get_gui().add_log("=" * 80)
    
    safe_print("=" * 80)
    safe_print("СКАНЕР АКТИВОВ - Поиск лучших точек входа по Price Action (WebSocket)")
    safe_print("=" * 80)
    safe_print(f"Время начала: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    safe_print(f"Период анализа: {SCAN_PERIOD} секунд")
    safe_print("=" * 80)
    safe_print()


# Функция _process_single_asset удалена - логика встроена в scan_assets для упрощения


def _print_analysis_result(analysis: Dict[str, Any], candles: List[Any]) -> None:
    """Выводит результат анализа актива"""
    action_emoji = "🟢 CALL" if analysis['recommended_action'] == 'CALL' else "🔴 PUT" if analysis['recommended_action'] == 'PUT' else "⚪ WAIT"
    colors_str = " → ".join([("🟢" if c == 'green' else "🔴" if c == 'red' else "⚪") for c in analysis.get('candle_colors', [])])
    
    # Рисуем ASCII-график свечей
    candle_chart = ""
    if candles and len(candles) >= 6:
        sorted_candles = sorted(candles, key=lambda c: c.timestamp)
        candle_colors_list = analysis.get('candle_colors', [])
        candle_chart = draw_candles_ascii(sorted_candles, candle_colors_list, num_candles=15)
    
    payout = analysis.get('payout')
    asset_name = analysis.get('asset', 'Unknown')
    
    safe_print("  " + "─" * 76)
    safe_print(f"  ✓ АНАЛИЗ ЗАВЕРШЕН: {asset_name}")
    safe_print("  " + "─" * 76)
    safe_print(f"  📊 Последние 6 свечей: {colors_str}")
    if candle_chart:
        safe_print("  📈 График свечей:")
        safe_print(candle_chart)
    safe_print(f"  💵 Payout: {payout}%" if payout else "  💵 Payout: N/A")
    safe_print(f"  {action_emoji} Рекомендация: {analysis['recommended_action']}")
    safe_print("  " + "─" * 76)
    safe_print()


async def scan_assets(client: AsyncPocketOptionClient, category_name: Optional[str] = None):
    """Сканирует активы из указанной категории или всех категорий, анализируя каждый актив
    
    Args:
        client: Экземпляр AsyncPocketOptionClient
        category_name: Название категории для сканирования или None для всех
    """
    _print_scan_header(category_name)
    
    # Определяем список категорий для сканирования
    available_categories = ['Валюты', 'Криптовалюты', 'Сырьевые товары', 'Акции', 'Индексы']
    
    if category_name:
        if category_name in available_categories:
            categories_to_scan = [category_name]
            safe_print(f"Сканирование категории: {category_name}\n")
        else:
            safe_print(f"⚠ Категория '{category_name}' не найдена в списке доступных категорий")
            safe_print(f"Доступные категории: {', '.join(available_categories)}\n")
            return []
    else:
        categories_to_scan = available_categories
        safe_print(f"Сканирование всех категорий: {', '.join(categories_to_scan)}\n")
    
    results = []
    total_assets_processed = 0
    scanned_assets = []  # Список всех просканированных активов
    successful_assets = []  # Список успешно проанализированных активов
    failed_assets = []  # Список активов, которые не удалось проанализировать
    
    # Проходим по каждой категории
    for category in categories_to_scan:
        safe_print(f"\n{'=' * 80}")
        safe_print(f"📂 КАТЕГОРИЯ: {category}")
        safe_print(f"{'=' * 80}")
        
        # Получаем список активов из категории
        asset_names_list = get_all_assets_from_category(category)
        
        safe_print(f"  ✓ Найдено активов в категории '{category}': {len(asset_names_list)}")
        
        # Фильтруем активы по payout (если MIN_PAYOUT установлен)
        if MIN_PAYOUT > 0:
            filtered_assets, skipped_low_payout, no_payout_data = _filter_assets_by_payout(asset_names_list, MIN_PAYOUT)
            
            # Выводим статистику фильтрации
            safe_print(f"  📊 Фильтрация по payout (>= {MIN_PAYOUT}%):")
            safe_print(f"    ✓ Проходит фильтр: {len(filtered_assets)} активов")
            if skipped_low_payout:
                safe_print(f"    ✗ Пропущено (payout < {MIN_PAYOUT}%): {len(skipped_low_payout)} активов")
                for asset, payout_val in skipped_low_payout[:10]:  # Показываем первые 10
                    safe_print(f"      - {asset}: {payout_val}%")
            if no_payout_data:
                safe_print(f"    ✗ Пропущено (payout не получен): {len(no_payout_data)} активов")
                for asset in no_payout_data[:10]:  # Показываем первые 10
                    safe_print(f"      - {asset}: N/A")
            
            asset_names_list = filtered_assets
        
        # Обрабатываем каждый актив последовательно
        debug_assets = ['#AAPL_otc', 'AAPL_otc', '#MSFT_otc', 'MSFT_otc', '#TSLA_otc', 'TSLA_otc']
        
        for asset_name in asset_names_list:
            total_assets_processed += 1
            scanned_assets.append(asset_name)
            is_debug = asset_name in debug_assets
            
            try:
                # Получаем данные по активу
                values, candles, asset_period = await get_asset_data(
                    client, asset_name, SCAN_PERIOD, count=100, wait_for_stream=2, is_debug=is_debug
                )
                
                # Проверяем, достаточно ли данных
                if len(values) < MIN_DATA_POINTS:
                    if is_debug:
                        safe_print(f"  [DEBUG] Недостаточно данных: {len(values)} < {MIN_DATA_POINTS}")
                    safe_print(f"  ⚠ Недостаточно данных: {len(values)} < {MIN_DATA_POINTS}")
                    failed_assets.append((asset_name, "Недостаточно данных"))
                    continue
                
                # Анализируем price action
                analysis = analyze_price_action(candles, asset_period)
                
                if analysis:
                    analysis['asset'] = asset_name
                    analysis['payout'] = payout_cache.get(asset_name)
                    # Сохраняем данные свечей для отображения графиков в GUI
                    analysis['candles'] = candles
                    analysis['asset_period'] = asset_period
                    
                    results.append(analysis)
                    successful_assets.append(asset_name)
                    
                    # Отправляем результат в GUI
                    if GUI_AVAILABLE and get_gui():
                        get_gui().add_result(analysis)
                    
                    # Выводим результат
                    _print_analysis_result(analysis, candles)
                else:
                    safe_print(f"  ❌ Не удалось проанализировать\n")
                    failed_assets.append((asset_name, "Не удалось проанализировать"))
                    
            except Exception as e:
                safe_print(f"  ❌ Ошибка при обработке актива '{asset_name}': {e}\n")
                failed_assets.append((asset_name, f"Ошибка: {str(e)[:50]}"))
                continue
    
    # Выводим итоговую статистику
    safe_print(f"\n{'=' * 80}")
    safe_print(f"📊 СКАНИРОВАНИЕ ЗАВЕРШЕНО")
    safe_print(f"{'=' * 80}")
    safe_print(f"Всего обработано активов: {total_assets_processed}")
    safe_print(f"Успешно проанализировано: {len(results)}")
    safe_print(f"Не удалось проанализировать: {len(failed_assets)}")
    safe_print(f"{'=' * 80}")
    
    # Выводим список всех просканированных активов с указанием успешных и неуспешных
    if scanned_assets:
        safe_print(f"\n📋 СПИСОК ВСЕХ ПРОСКАНИРОВАННЫХ АКТИВОВ ({len(scanned_assets)}):")
        safe_print("─" * 80)
        
        # Группируем активы по категориям для удобства чтения
        assets_by_category = {}
        for category in categories_to_scan:
            category_assets = get_all_assets_from_category(category)
            assets_by_category[category] = [a for a in scanned_assets if a in category_assets]
        
        for category, assets in assets_by_category.items():
            if assets:
                safe_print(f"\n  📂 {category} ({len(assets)} активов):")
                
                # Разделяем на успешные и неуспешные
                category_successful = [a for a in assets if a in successful_assets]
                category_failed = [a for a in assets if a in [f[0] for f in failed_assets]]
                
                # Выводим успешные активы
                if category_successful:
                    safe_print(f"    ✓ Успешно проанализировано ({len(category_successful)}):")
                    for i in range(0, len(category_successful), 5):
                        line_assets = category_successful[i:i+5]
                        safe_print(f"      {', '.join(line_assets)}")
                
                # Выводим неуспешные активы с причинами
                if category_failed:
                    safe_print(f"    ✗ Не удалось проанализировать ({len(category_failed)}):")
                    failed_dict = {f[0]: f[1] for f in failed_assets}
                    for i in range(0, len(category_failed), 3):
                        line_assets = category_failed[i:i+3]
                        for asset in line_assets:
                            reason = failed_dict.get(asset, "Неизвестная причина")
                            safe_print(f"      {asset} ({reason})")
        
        safe_print("\n" + "─" * 80)
        
        # Итоговая сводка по успешным и неуспешным
        safe_print(f"\n📈 ИТОГОВАЯ СВОДКА:")
        safe_print(f"  ✓ Успешно: {len(successful_assets)} активов")
        safe_print(f"  ✗ Неудачно: {len(failed_assets)} активов")
        if failed_assets:
            safe_print(f"\n  Детали неудачных попыток:")
            failed_reasons = {}
            for asset, reason in failed_assets:
                if reason not in failed_reasons:
                    failed_reasons[reason] = []
                failed_reasons[reason].append(asset)
            for reason, assets_list in failed_reasons.items():
                safe_print(f"    {reason}: {len(assets_list)} активов")
    
    safe_print()
    
    return results


def print_results(results):
    """Выводит итоговую сводку результатов анализа"""
    if not results:
        safe_print("\n❌ Не удалось получить результаты анализа")
        return
    
    # Фильтруем только активы с сигналами (CALL или PUT)
    signals = [r for r in results if r['recommended_action'] in ['CALL', 'PUT']]
    
    # Разделяем на CALL и PUT
    call_assets = [r for r in signals if r['recommended_action'] == 'CALL']
    put_assets = [r for r in signals if r['recommended_action'] == 'PUT']
    
    safe_print("\n" + "=" * 80)
    safe_print("ИТОГОВАЯ СВОДКА - АКТИВЫ С СИГНАЛАМИ")
    safe_print("=" * 80)
    safe_print(f"Время завершения: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    safe_print(f"Всего проанализировано активов: {len(results)}")
    safe_print(f"Найдено сигналов: {len(signals)} (CALL: {len(call_assets)}, PUT: {len(put_assets)})")
    safe_print("=" * 80)
    safe_print()
    
    # Выводим активы с сигналом CALL
    if call_assets:
        safe_print("=" * 80)
        safe_print("🟢 АКТИВЫ С СИГНАЛОМ CALL (6 зеленых свечей подряд):")
        safe_print("=" * 80)
        for i, asset in enumerate(call_assets, 1):
            colors_str = " → ".join([("🟢" if c == 'green' else "🔴" if c == 'red' else "⚪") for c in asset.get('candle_colors', [])])
            payout_str = f" | Payout: {asset['payout']}%" if asset.get('payout') else ""
            safe_print(f"{i}. {asset['asset']:20s} | {colors_str}{payout_str}")
        safe_print()
    
    # Выводим активы с сигналом PUT
    if put_assets:
        safe_print("=" * 80)
        safe_print("🔴 АКТИВЫ С СИГНАЛОМ PUT (6 красных свечей подряд):")
        safe_print("=" * 80)
        for i, asset in enumerate(put_assets, 1):
            colors_str = " → ".join([("🟢" if c == 'green' else "🔴" if c == 'red' else "⚪") for c in asset.get('candle_colors', [])])
            payout_str = f" | Payout: {asset['payout']}%" if asset.get('payout') else ""
            safe_print(f"{i}. {asset['asset']:20s} | {colors_str}{payout_str}")
        safe_print()
    
    if not signals:
        safe_print("⚠ Сигналов не найдено (нет активов с 6 свечами одного цвета подряд)")
        safe_print()
    
    safe_print("=" * 80)
    safe_print("Анализ завершен!")
    safe_print("=" * 80)


async def _run_async_logic():
    """Асинхронная логика выполнения скрипта (вызывается из main или из потока для GUI)"""
    client = None  # Инициализируем для безопасной проверки в except блоках
    try:
        # Принудительно выводим в терминал и GUI для проверки
        try:
            print("=" * 80, flush=True, file=sys.stdout)
            print("СКАНЕР АКТИВОВ - WebSocket версия", flush=True, file=sys.stdout)
            print("=" * 80, flush=True, file=sys.stdout)
        except:
            pass
        
        # Получаем SSID от пользователя
        safe_print("=" * 80)
        safe_print("СКАНЕР АКТИВОВ - WebSocket версия")
        safe_print("=" * 80)
        # Проверяем, передан ли SSID через аргументы командной строки или файл
        import sys
        import os
        if len(sys.argv) > 1:
            ssid = sys.argv[1]
            safe_print(f"Используется SSID из аргументов командной строки")
        elif os.path.exists('ssid.txt'):
            # Читаем SSID из файла
            with open('ssid.txt', 'r', encoding='utf-8') as f:
                ssid = f.read().strip()
            safe_print(f"Используется SSID из файла ssid.txt")
        else:
            safe_print("\nДля работы необходимо получить SSID из браузера:")
            safe_print("1. Откройте PocketOption в браузере")
            safe_print("2. Нажмите F12 (Developer Tools)")
            safe_print("3. Перейдите на вкладку Network")
            safe_print("4. Отфильтруйте по WebSocket (WS)")
            safe_print('5. Найдите сообщение, начинающееся с 42["auth"')
            safe_print("6. Скопируйте полное сообщение\n")
            
            ssid = input("Введите SSID (или нажмите Enter для использования демо): ").strip()
            
            if not ssid:
                # Используем демо SSID
                ssid = r'42["auth",{"session":"demo_session","isDemo":1,"uid":0,"platform":1}]'
                safe_print("Используется демо SSID")
        
        # Определяем, демо ли это (проверяем разные варианты)
        is_demo = (
            'isDemo":1' in ssid or 
            'isDemo":true' in ssid.lower() or
            '"isDemo":1' in ssid or
            '"isDemo":true' in ssid.lower()
        )
        
        safe_print(f"\nИнициализация клиента (demo={is_demo})...")
        client = AsyncPocketOptionClient(
            ssid,
            is_demo=is_demo,
            enable_logging=False,  # Включаем логирование для отладки
            persistent_connection=False,  # Временно отключаем persistent connection из-за проблем с импортами
            auto_reconnect=True
        )
        
        safe_print("Подключение к PocketOption через WebSocket...")
        try:
            success = await client.connect()
            
            if not success:
                safe_print("❌ Не удалось подключиться к PocketOption")
                safe_print("Проверьте SSID и подключение к интернету")
                return
        except Exception as e:
            safe_print(f"❌ Ошибка при подключении: {e}")
            import traceback
            try:
                traceback.print_exc()
            except (ValueError, OSError):
                pass
            return
        
        gui_log("✓ Успешно подключено к PocketOption", "SUCCESS")
        
        # Подписываемся на события payout_update для автоматического получения данных
        payout_received_count = [0]  # Используем список для изменения из вложенной функции
        
        def on_payout_update_global(payout_info: Dict[str, Any]):
            """Глобальный обработчик payout_update для сохранения в кэш"""
            try:
                symbol = payout_info.get('symbol', '')
                payout = payout_info.get('payout')
                asset_id = payout_info.get('id')
                asset_name = payout_info.get('name', '')
                asset_type = payout_info.get('type', '')
                
                gui_log(f"[DEBUG PAYOUT] Получено payout_update: symbol={symbol}, payout={payout}, id={asset_id}, name={asset_name}, type={asset_type}", "DEBUG")
                
                if symbol and payout is not None:
                    # Сохраняем payout в кэш (со всеми вариантами имени)
                    payout_cache.add(symbol, payout)
                    payout_received_count[0] += 1
                    gui_log(f"✓ Payout получен через WebSocket: {symbol} = {payout}% (всего получено: {payout_received_count[0]})", "SUCCESS")
                else:
                    gui_log(f"[DEBUG PAYOUT] payout_update пропущен: symbol={symbol}, payout={payout}", "DEBUG")
            except Exception as e:
                gui_log(f"⚠ Ошибка в on_payout_update_global: {e}", "WARNING")
                import traceback
                try:
                    gui_log(f"[DEBUG PAYOUT] Traceback: {traceback.format_exc()}", "DEBUG")
                except:
                    pass
        
        # Подписываемся на события payout_update
        client._websocket.add_event_handler("payout_update", on_payout_update_global)
        gui_log("✓ Подписались на события payout_update", "INFO")
        
        # Пытаемся запросить payout данные через WebSocket
        try:
            gui_log("Запрос payout данных через WebSocket...", "INFO")
            gui_log(f"[DEBUG PAYOUT] Текущий размер payout_cache: {len(payout_cache)}", "DEBUG")
            updated = await client.request_payment_update(timeout=5.0)
            gui_log(f"[DEBUG PAYOUT] После запроса: размер payout_cache: {len(payout_cache)}, получено событий: {payout_received_count[0]}", "DEBUG")
            if updated:
                gui_log(f"✓ Получены payout данные через WebSocket (получено: {payout_received_count[0]} активов)", "SUCCESS")
            else:
                gui_log(f"⚠ Payout данные через WebSocket не получены (получено событий: {payout_received_count[0]}, размер кэша: {len(payout_cache)})", "WARNING")
                gui_log("[DEBUG PAYOUT] Возможные причины: сервер не поддерживает запрос, данные придут автоматически позже", "DEBUG")
        except Exception as e:
            gui_log(f"⚠ Ошибка при запросе payout через WebSocket: {e}", "WARNING")
            import traceback
            try:
                gui_log(f"[DEBUG PAYOUT] Traceback: {traceback.format_exc()}", "DEBUG")
            except:
                pass
        
        # Даем серверу время обработать данные об активах и получить payout данные
        gui_log("Ожидание инициализации данных...", "INFO")
        # Увеличиваем время ожидания для получения payout данных
        # Payout данные могут приходить автоматически с задержкой
        for i in range(10):  # Ждем до 10 секунд (10 итераций по 1 секунде)
            await asyncio.sleep(1)
            if len(payout_cache) > 0:
                gui_log(f"✓ Получены payout данные автоматически (размер кэша: {len(payout_cache)}, событий: {payout_received_count[0]})", "SUCCESS")
                break
            if i == 4:  # После 5 секунд пробуем еще раз запросить
                try:
                    gui_log("Повторный запрос payout данных...", "INFO")
                    await client.request_payment_update(timeout=3.0)
                except:
                    pass
        
        if len(payout_cache) == 0:
            gui_log(f"⚠ Payout данные не получены после ожидания (размер кэша: {len(payout_cache)})", "WARNING")
            gui_log("[DEBUG PAYOUT] Возможно, сервер не отправляет payout данные в демо-режиме или требуется больше времени", "DEBUG")
        else:
            gui_log(f"✓ Итого получено payout данных: {len(payout_cache)} активов", "SUCCESS")
        
        # Загружаем WebSocket активы в GUI (после попытки получить payout)
        if GUI_AVAILABLE and get_gui():
            # Используем глобальный payout_cache, который может быть заполнен из WebSocket
            get_gui().add_websocket_assets(ASSETS, payout_cache.get_dict())
            gui_log(f"Загружено {len(ASSETS)} WebSocket активов в GUI", "INFO")
        
        # Выводим все WebSocket активы в терминал
        try:
            print_websocket_assets_table(ASSETS)
        except (ValueError, OSError):
            pass  # Игнорируем ошибки с закрытым файлом
        
        # Payout данные теперь получаются автоматически из WebSocket
        
        # Можно указать категорию для сканирования, или None для всех категорий
        category_to_scan = None  # None = все категории
        # category_to_scan = 'Акции'  # Раскомментируйте для сканирования только акций
        
        # Сканируем активы
        results = await scan_assets(client, category_to_scan)
        
        # Выводим итоговые результаты
        print_results(results)
        
        # Отключаемся
        await client.disconnect()
        safe_print("\n✓ Отключено от PocketOption")
        
    except KeyboardInterrupt:
        gui_log("⚠ Прервано пользователем", "WARNING")
        # Пытаемся корректно отключиться от WebSocket
        try:
            if client:
                await client.disconnect()
        except:
            pass
    except asyncio.CancelledError:
        gui_log("⚠ Операция отменена", "WARNING")
        # Пытаемся корректно отключиться от WebSocket
        try:
            if client:
                await client.disconnect()
        except:
            pass
    except Exception as e:
        gui_log(f"❌ Критическая ошибка: {e}", "ERROR")
        try:
            import traceback
            traceback.print_exc()
        except (ValueError, OSError):
            pass  # Игнорируем ошибки с закрытым файлом
        # Пытаемся корректно отключиться от WebSocket даже при ошибке
        try:
            if client:
                await client.disconnect()
        except:
            pass


async def main():
    """Основная функция - точка входа в программу"""
    # Инициализируем GUI (если доступен) - GUI должен быть в главном потоке
    if GUI_AVAILABLE:
        try:
            gui_root = init_gui()
            if gui_root:
                print("✓ Графический интерфейс создан", flush=True)
                if get_gui():
                    get_gui().add_log("Графический интерфейс инициализирован", "SUCCESS")
                
                # Запускаем асинхронный код в отдельном потоке, чтобы не блокировать GUI
                import threading
                def run_async():
                    # Убеждаемся, что stdout доступен в отдельном потоке
                    try:
                        # Проверяем и настраиваем stdout для отдельного потока
                        if sys.platform == 'win32' and hasattr(sys.stdout, 'buffer'):
                            try:
                                # Пытаемся получить доступ к stdout
                                sys.stdout.write("")  # Проверка доступности
                            except:
                                # Если stdout недоступен, создаем новый
                                import sys
                                sys.stdout = io.TextIOWrapper(io.BufferedWriter(io.FileIO(1, 'wb')), encoding='utf-8', errors='replace', line_buffering=True)
                    except:
                        pass
                    
                    try:
                        asyncio.run(_run_async_logic())
                    except KeyboardInterrupt:
                        safe_print("⚠ Прервано пользователем")
                        if get_gui():
                            try:
                                get_gui().add_log("⚠ Прервано пользователем", "WARNING")
                            except:
                                pass
                    except asyncio.CancelledError:
                        safe_print("⚠ Операция отменена")
                        if get_gui():
                            try:
                                get_gui().add_log("⚠ Операция отменена", "WARNING")
                            except:
                                pass
                    except Exception as e:
                        safe_print(f"❌ Ошибка в асинхронном коде: {e}")
                        if get_gui():
                            try:
                                get_gui().add_log(f"Ошибка в асинхронном коде: {e}", "ERROR")
                            except:
                                pass
                
                async_thread = threading.Thread(target=run_async, daemon=True)
                async_thread.start()
                
                # Запускаем GUI mainloop в главном потоке (блокирующий вызов)
                try:
                    gui_root.mainloop()
                except KeyboardInterrupt:
                    safe_print("\n⚠ Прервано пользователем")
                return
        except Exception as e:
            safe_print(f"⚠ Ошибка при инициализации GUI: {e}")
            import traceback
            try:
                traceback.print_exc()
            except (ValueError, OSError):
                pass
            safe_print("  Продолжаем работу с выводом в терминал")
    
    # Если GUI недоступен, выполняем обычную логику
    await _run_async_logic()


if __name__ == '__main__':
    safe_print("=== SCRIPT STARTED ===")
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        safe_print("\n⚠ Прервано пользователем (KeyboardInterrupt)")
    except asyncio.CancelledError:
        safe_print("\n⚠ Операция отменена (CancelledError)")
    except Exception as e:
        safe_print(f"=== FATAL ERROR: {e} ===")
        import traceback
        try:
            traceback.print_exc()
        except (ValueError, OSError):
            pass
    finally:
        safe_print("=== SCRIPT ENDED ===")

