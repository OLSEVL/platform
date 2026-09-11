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

# Интервал сканирования рынка в секундах
SCAN_INTERVAL_SECONDS = 5

REQUEST_BATCH_SIZE = 0                       # Размер пакета запросов (0 - без пакетирования/авто)
HISTORY_PERIOD = 100                         # Базовая глубина истории; бот увеличит её сам, если стратегии нужно больше баров
HISTORY_TIMEOUT = 5                          # Таймаут ожидания загрузки истории в секундах
MIN_PAYOUT = 90                              # Минимальный процент доходности (выплаты) парах для фильтрации

# Фильтр IP адресов по странам
RESTRICTED_COUNTRIES = "UA"

# Смещение выводимого времени временных меток в часах
TIME_OFFSET_HOURS = 0.0

# -------------------------------------------------------------
# УНИВЕРСАЛЬНЫЕ СВЕЧНЫЕ СТРАТЕГИИ
# -------------------------------------------------------------
# Каждая включённая стратегия проверяется параллельно. Доступные значения:
# color: "red" или "green"; если поле не указано — любой цвет;
# streak_mode: "exact" (ровно N подряд) либо "at_least" (N и более);
# signal_timing: "live" — сразу при выполнении условия в текущей свече;
#                "closed" — только после её закрытия.
#
# Сигнал строится только по закрытым свечам. measured_candles — количество
# последних свечей серии, тела которых сравниваются с максимумом таких же
# одноцветных блоков за lookback_bars предыдущих свечей.
STRATEGIES = [
    {
        "id": "1:110%",
        "enabled": True,
        "timeframe_seconds": 60,
        "lookback_bars": 100,
        "color": "",
        "streak_length": 1,
        "streak_mode": "at_least",
        "measured_candles": 1,
        "threshold_pct": 110,
        "signal_timing": "live",
    },
    {
        "id": "2:120%",
        "enabled": True,
        "timeframe_seconds": 60,
        "lookback_bars": 100,
        "color": "",
        "streak_length": 2,
        "streak_mode": "at_least",
        "measured_candles": 2,
        "threshold_pct": 120,
        "signal_timing": "live",
    },
   
    {
        "id": "3:120%",
        "enabled": True,
        "timeframe_seconds": 60,
        "lookback_bars": 100,
        "color": "",
        "streak_length": 3,
        "streak_mode": "at_least",
        "measured_candles": 3,
        "threshold_pct": 120,
        "signal_timing": "live",
    },
    {
        "id": "4:120%",
        "enabled": True,
        "timeframe_seconds": 60,
        "lookback_bars": 100,
        "color": "",
        "streak_length": 4,
        "streak_mode": "at_least",
        "measured_candles": 4,
        "threshold_pct": 120,
        "signal_timing": "live",
    },
]

PAIRS_MAP = {
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
