from __future__ import annotations

try:
    import private_config as private
except ModuleNotFoundError as exc:
    if exc.name != "private_config":
        raise
    private = None

SSID = getattr(private, "SSID", "") if private else ""
SSIDS = getattr(private, "SSIDS", []) if private else ""
BOT_TOKEN = getattr(private, "BOT_TOKEN", "") if private else ""
CHAT_ID = getattr(private, "CHAT_ID", "") if private else ""
CHAT_IDS = getattr(private, "CHAT_IDS", []) if private else ""

DEBUG_CYCLE = False
# Отправлять ли в Telegram PNG со свечами и совпавшими зонами уровней.
# Для картинки используются уже накопленные M1-свечи, новый запрос не нужен.
TELEGRAM_CHARTS_ENABLED = True
TELEGRAM_CHART_CANDLES = 100

# Интервал сканирования рынка в секундах
SCAN_INTERVAL_SECONDS = 5

# Незакрытая M1 анализируется по максимальному движению от открытия до
# достигнутого high/low.  Закрытые M1, как и раньше, анализируются по телу.
LIVE_CANDLE_WICK_SIGNALS = True
# Для сигнала по незакрытой свече можно задать каждому цвету поле
# "live_priority" (меньшее число = выше приоритет). Если поле не задано,
# приоритетом считается позиция профиля в STRATEGY_PROFILES. Повторный сигнал
# за минуту допустим лишь при новом экстремуме и более высоком приоритете.

# Рассылать ли сигналы последовательных правил на одном баре
SEND_RULE_SEQUENCE_SIGNALS = False

REQUEST_BATCH_SIZE = 0                       # Размер пакета запросов (0 - без пакетирования/авто)
HISTORY_PERIOD = 100                         # Глубина запрашиваемой истории свечей (число баров)
TIMEFRAME_SECONDS = 60                       # Основной таймфрейм торговли в секундах (60 = M1)
HISTORY_TIMEOUT = 10                         # Таймаут ожидания загрузки истории в секундах
MIN_PAYOUT = 80                              # Минимальный процент доходности (выплаты) парах для фильтрации

# Настройки обрезки экстремальных свечей для вычисления среднего тела (TRIM)
TRIM_LOW_COUNT = 70
TRIM_HIGH_COUNT = 5

# Облегчённый бот: профили свечей и MAX фильтруются только обычными swing-уровнями LVL.
GLOBAL_FILTER = "LVL";
# 🔴 🟠 🟡 🟢 🔵 🟣 🟤 ⚪ ⚫
STRATEGY_PROFILES = [   

    {
        "id": "extra_wl",
        "enabled": True,
        "label": "-",
        "emoji": "⚪",
        "candle_multipliers": {1: 2.5, 2: 3.1, 3: 3.7, 4: 4.3},
        # "candle_multipliers": {1: 2.5, 2: 3.1, 3: 3.7, 4: 4.3, 5: 4.9, 6: 5.5, 7: 6.1, 8: 6.7},
        "filter_enabled": True,
        "filter_expr": GLOBAL_FILTER
    },
    {
        "id": "extra",
        "enabled": True,
        "label": "-",
        "emoji": "🟢",
        "candle_multipliers": {2: 2.9, 3: 3.4, 4: 3.9},
        # "candle_multipliers": {2: 2.9, 3: 3.4, 4: 3.9, 5: 4.4, 6: 4.8, 7: 5.2, 8: 5.6},
        "filter_enabled": True,
        "filter_expr": GLOBAL_FILTER
    },    
    {
        "id": "extreme",
        "enabled": True,
        "label": "-",
        "emoji": "🟡",        
        "candle_multipliers": {2: 2.7, 3: 3.1, 4: 3.5},
        # "candle_multipliers": {2: 2.7, 3: 3.1, 4: 3.5, 5: 3.9, 6: 4.3, 7: 4.7, 8: 5.1},
        "filter_enabled": True,
        "filter_expr": GLOBAL_FILTER
    },
    {
        "id": "structure",
        "enabled": True,
        "label": "-",
        "emoji": "🟣",        
        "candle_multipliers": {2: 2.5, 3: 2.8, 4: 3.1},
        # "candle_multipliers": {2: 2.5, 3: 2.8, 4: 3.1, 5: 3.3, 6: 3.6, 7: 3.9, 8: 4.2},
        "filter_enabled": True,
        "filter_expr": GLOBAL_FILTER
    },
    {
        "id": "structure_no_lvl",
        "enabled": True,
        "label": "-",
        "emoji": "🟤",        
        "candle_multipliers": {1: 2.7, 2: 3.3, 3: 3.9, 4: 4.5},
        "filter_enabled": False,
        "filter_expr": GLOBAL_FILTER
    }
]

# Настройки для MAX серий по умолчанию, если длина N не прописана явно в MAX_BODY_PERCENT_SERIES
MAX_BODY_PERCENT_DEFAULT = {
    "percent": 120.0,
    "filter_enabled": True,
    "filter_expr": GLOBAL_FILTER
}

# Индивидуальные настройки для конкретных длин серий (переопределяют MAX_BODY_PERCENT_DEFAULT)
MAX_BODY_PERCENT_SERIES = {
    1: {
        "percent": 110.0,
        "filter_enabled": True,
        "filter_expr": GLOBAL_FILTER       
    },
    2: {
        "percent": 130.0,
        "filter_enabled": True,
        "filter_expr": GLOBAL_FILTER       
    },
}

# Выводить только один сигнал MAX с наибольшей длиной N
MAX_ONLY_LONGEST_SERIES = True

# -------------------------------------------------------------
# ИНДИВИДУАЛЬНЫЕ НАСТРОЙКИ ФРАКТАЛОВ И ДОПУСКОВ ДЛЯ КАЖДОГО ТАЙМФРЕЙМА
# -------------------------------------------------------------
# body_to_wick: вся зона от тела до экстремума фитиля.
# wick_tip: только часть фитиля у экстремума; защищает M5/M15 от слишком широких зон.
LEVEL_SETTINGS = {
    60:  {"pivot_left": 3, "pivot_right": 3, "use_body": False, "wick_only": False, "distance_body_multiplier": 0.05},  # M1: зона тело ↔ фитиль
    300: {"pivot_left": 2, "pivot_right": 2, "wick_only": False, "zone_mode": "wick_tip", "wick_tip_fraction": 0.15, "distance_body_multiplier": 0.05},  # M5: последние 33% фитиля
    900: {"pivot_left": 1, "pivot_right": 1, "wick_only": False, "zone_mode": "wick_tip", "wick_tip_fraction": 0.10, "distance_body_multiplier": 0.05},  # M15: последние 20% фитиля
    1800: {"pivot_left": 1, "pivot_right": 1, "wick_only": False, "zone_mode": "wick_tip", "wick_tip_fraction": 0.10, "distance_body_multiplier": 0.05},  # M15: последние 20% фитиля
}

# Допуск = коэффициент × медианное тело закрытых свечей именно этого ТФ.
# То есть 0.10 — 10% типичного M1 для M1, M5 для M5, M15 для M15.
LEVEL_DISTANCE_BODY_MULTIPLIER = 0.05
LEVEL_ALLOWANCE_BODY_PERIOD = 50

# Ограничение истории поиска уровней в минутах (0 - за всю историю)
LEVEL_HISTORY_LIMIT_MINUTES = 0

# Смещение выводимого времени временных меток в часах
TIME_OFFSET_HOURS = -2.0

# Список таймфреймов для поиска уровней
LEVEL_TIMEFRAMES_SECONDS = [60, 300, 900, 1800]  # M1 — локально, M5 — рабочий, M15 — старший контекст

# Какой таймфрейм приоритетнее при удалении дубликатов: "lower" или "higher"
# "higher" обеспечивает приоритет старшим таймфреймам (M5-M15), у которых проходимость и сила уровней выше
LEVEL_TIMEFRAME_PRIORITY = "higher"

# Автоматическое обновление/накопление свечей старших ТФ
LEVEL_UPDATE_HIGHER_TF = True  # M5/M15 достраиваются из актуальных M1; отдельных запросов к брокеру нет

# Исключать ли последнюю свечу при фильтрации пробитых уровней
LEVEL_EXCLUDE_LATEST_CANDLE_FROM_FILTER = False  # Учитывать текущую незакрытую свечу при обновлении/снятии LVL

# Исключать ли уровень при его пробитии
LEVEL_EXCLUDE_ON_BREAK = True

# Режим касания уровня ("high_low", "candle", "close")
LEVEL_MATCH_MODE = "high_low"

# False: коротко показывать только ТФ уровней (M1 M5 M15), без времени.
DETAILED_MARKERS = False

# Выводить ли детализированную информацию по правилу MAX
MAX_RULE_DETAILS_ENABLED = False

PAIRS_MAP = {
    # Примеры использования:
    "BTCUSD_otc": "BTC     OTC",
    "BITB_otc": "BTC ETF OTC",
}

# Список отслеживаемых торговых пар
PAIRS = [
    # FOREX OTC
    "EURUSD_otc", "GBPUSD_otc", "USDJPY_otc", "AUDUSD_otc",
    "NZDUSD_otc", "EURGBP_otc", "EURJPY_otc", "EURCHF_otc",
    "EURNZD_otc", "EURTRY_otc", "EURHUF_otc",
    "GBPJPY_otc", "GBPAUD_otc", "AUDJPY_otc", "AUDCAD_otc",
    "AUDCHF_otc", "AUDNZD_otc", "CADJPY_otc", "CADCHF_otc",
    "CHFJPY_otc", "NZDJPY_otc", "USDINR_otc", "USDMXN_otc",
    "USDBRL_otc", "USDCNH_otc", "USDSGD_otc", "USDTHB_otc",
    "USDPKR_otc", "USDVND_otc", "USDEGP_otc", "USDDZD_otc",
    "USDIDR_otc", "USDCOP_otc", "USDCLP_otc", "USDMYR_otc",
    "USDPHP_otc", "USDARS_otc", "USDCAD_otc",
    "USDCHF_otc", "UAHUSD_otc", "ZARUSD_otc",
    "KESUSD_otc", "MADUSD_otc", "NGNUSD_otc",
    "TNDUSD_otc", "YERUSD_otc", "AEDCNY_otc", "BHDCNY_otc",
    "JODCNY_otc", "OMRCNY_otc", "QARCNY_otc", "SARCNY_otc",
    "CHFNOK_otc", "USDBDT_otc", "LBPUSD_otc",

    # FOREX
    "GBPUSD", "EURGBP", "EURJPY", "EURCHF",
    "EURAUD",  "EURCAD", "GBPJPY",
    "GBPAUD", "GBPCHF", "GBPCAD", "AUDJPY",
    "AUDCHF", "AUDCAD", "CADJPY",
    "CADCHF", "CHFJPY", "USDCHF", "USDCAD",
    "USDJPY",  "EURUSD","AUDUSD",

    # CRYPTO
    "BTCUSD_otc", "ETHUSD_otc", "LTCUSD_otc", "DOGE_otc",
    "MATIC_otc", "BNB-USD_otc", "ADA-USD_otc", "SOL-USD_otc",
    "TRX-USD_otc", "LINK_otc", "DOTUSD_otc", "TON-USD_otc",
    "AVAX_otc", "BITB_otc",

    # STOCKS
    "AMZN_otc", "NFLX_otc", "BABA_otc", "COIN_otc",
    "AMD_otc", "PLTR_otc", "MARA_otc", "VISA_otc",
    "FDX_otc", "CITI_otc", "GME_otc", "#AAPL_otc",
    "#AXP_otc", "#BA_otc", "#CSCO_otc", "#FB_otc",
    "#INTC_otc", "#JNJ_otc", "#MCD_otc", "#MSFT_otc",
    "#PFE_otc", "#TSLA_otc", "#XOM_otc", "VIX_otc",
    
    # COMMODITIES
    "XAUUSD_otc", "XAGUSD_otc", "USCrude_otc", "UKBrent_otc",
]
