<?php

namespace App\Models;

use App\Services\Indicators\AdxIndicatorService;
use App\Services\Indicators\AlligatorIndicatorService;
use App\Services\Indicators\AoIndicatorService;
use App\Services\Indicators\AroonIndicatorService;
use App\Services\Indicators\HmaIndicatorService;
use App\Services\Indicators\IchimokuIndicatorService;
use App\Services\Indicators\NrtrIndicatorService;
use App\Services\Indicators\PriceChannelIndicatorService;
use App\Services\Indicators\WilliamsRangeIndicatorService;
use App\Services\Indicators\AtrIndicatorService;
use App\Services\Indicators\BollingerIndicatorService;
use App\Services\Indicators\EmaIndicatorService;
use App\Services\Indicators\EnvelopsIndicatorService;
use App\Services\Indicators\KeltnerIndicatorService;
use App\Services\Indicators\LiquiditySwingsIndicatorService;
use App\Services\Indicators\MacdIndicatorService;
use App\Services\Indicators\OrderBlocksIndicatorService;
use App\Services\Indicators\ParabolicSarIndicatorService;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\RsiIndicatorService;
use App\Services\Indicators\SmaIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;
use App\Services\Indicators\CciIndicatorService;
use App\Services\Indicators\StochasticIndicatorService;
use App\Services\Indicators\SuperTrendIndicatorService;
use App\Services\Indicators\VwmaIndicatorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorSetting extends Model
{
    /** Индикатор Price Action (DGT) на графике бота; JSON — те же ключи, что в PriceActionDgtIndicatorService::defaultParams(). */
    public const KEY_PRICE_ACTION_DGT = 'price_action_dgt';

    /** Параметры оверлея Liquidity Swings (LuxAlgo), те же ключи, что LiquiditySwingsIndicatorService::defaultParams(). */
    public const KEY_LIQUIDITY_SWINGS = 'liquidity_swings';

    /** SMC Flow (3.txt): UT Bot и последующие модули — ключи SmcFlowIndicatorService::defaultParams(). */
    public const KEY_SMC_FLOW = 'smc_flow';

    /** Ордерблоки LuxAlgo (orderblock.txt) — ключи OrderBlocksIndicatorService::defaultParams(). */
    public const KEY_ORDER_BLOCKS = 'order_blocks';

    /** Классика OsEngine v1. */
    public const KEY_BOLLINGER = 'bollinger';

    public const KEY_RSI = 'rsi';

    public const KEY_SMA = 'sma';

    public const KEY_EMA = 'ema';

    public const KEY_ATR = 'atr';

    public const KEY_MACD = 'macd';

    public const KEY_STOCHASTIC = 'stochastic';

    public const KEY_CCI = 'cci';

    /** VWMA (Volume Weighted MA) — линия на цене, ключи VwmaIndicatorService::defaultParams(). */
    public const KEY_VWMA = 'vwma';

    /** Keltner Channel — оверлей на цене. */
    public const KEY_KELTNER = 'keltner';

    /** Envelops — процентный канал вокруг SMA. */
    public const KEY_ENVELOPS = 'envelops';

    /** Parabolic SAR — точки на цене. */
    public const KEY_PARABOLIC_SAR = 'parabolic_sar';

    /** SuperTrend — trailing-stop линия на цене. */
    public const KEY_SUPERTREND = 'supertrend';

    /** ADX / +DI / −DI — отдельная pane. */
    public const KEY_ADX = 'adx';

    /** Williams %R — осциллятор -100..0. */
    public const KEY_WILLIAMS = 'williams';

    /** Awesome Oscillator — гистограмма. */
    public const KEY_AO = 'ao';

    /** Alligator — три SSMA линии на цене. */
    public const KEY_ALLIGATOR = 'alligator';

    /** Ichimoku — облако и линии на цене. */
    public const KEY_ICHIMOKU = 'ichimoku';

    /** PriceChannel — Donchian-like канал. */
    public const KEY_PRICE_CHANNEL = 'price_channel';

    /** NRTR — center line trailing. */
    public const KEY_NRTR = 'nrtr';

    /** Hull Moving Average — линия на цене. */
    public const KEY_HMA = 'hma';

    /** Aroon Up/Down — отдельная pane. */
    public const KEY_AROON = 'aroon';

    protected $fillable = ['bot_id', 'indicator_key', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Параметры Price Action DGT: дефолты сервиса ← legacy config бота ← строка indicator_settings (приоритет).
     */
    public static function mergedPriceActionDgtParams(Bot $bot): array
    {
        $defaults = PriceActionDgtIndicatorService::defaultParams();
        $botConfig = is_array($bot->config) ? $bot->config : [];
        $legacy = isset($botConfig[self::KEY_PRICE_ACTION_DGT]) && is_array($botConfig[self::KEY_PRICE_ACTION_DGT])
            ? $botConfig[self::KEY_PRICE_ACTION_DGT]
            : [];
        $row = static::query()
            ->where('bot_id', $bot->id)
            ->where('indicator_key', self::KEY_PRICE_ACTION_DGT)
            ->first();
        $db = is_array($row?->settings) ? $row->settings : [];

        return array_replace($defaults, $legacy, $db);
    }

    /**
     * Параметры Liquidity Swings: дефолты сервиса ← legacy config бота ← indicator_settings (приоритет).
     */
    public static function mergedLiquiditySwingsParams(Bot $bot): array
    {
        $defaults = LiquiditySwingsIndicatorService::defaultParams();
        $botConfig = is_array($bot->config) ? $bot->config : [];
        $legacy = isset($botConfig[self::KEY_LIQUIDITY_SWINGS]) && is_array($botConfig[self::KEY_LIQUIDITY_SWINGS])
            ? $botConfig[self::KEY_LIQUIDITY_SWINGS]
            : [];
        $row = static::query()
            ->where('bot_id', $bot->id)
            ->where('indicator_key', self::KEY_LIQUIDITY_SWINGS)
            ->first();
        $db = is_array($row?->settings) ? $row->settings : [];

        return array_replace($defaults, $legacy, $db);
    }

    /**
     * Параметры SMC Flow: дефолты сервиса ← legacy config бота ← indicator_settings (приоритет).
     */
    public static function mergedSmcFlowParams(Bot $bot): array
    {
        $defaults = SmcFlowIndicatorService::defaultParams();
        $botConfig = is_array($bot->config) ? $bot->config : [];
        $legacy = isset($botConfig[self::KEY_SMC_FLOW]) && is_array($botConfig[self::KEY_SMC_FLOW])
            ? $botConfig[self::KEY_SMC_FLOW]
            : [];
        $row = static::query()
            ->where('bot_id', $bot->id)
            ->where('indicator_key', self::KEY_SMC_FLOW)
            ->first();
        $db = is_array($row?->settings) ? $row->settings : [];

        return array_replace($defaults, $legacy, $db);
    }

    /**
     * Параметры ордерблоков: дефолты сервиса ← legacy config бота ← indicator_settings (приоритет).
     */
    public static function mergedOrderBlocksParams(Bot $bot): array
    {
        $defaults = OrderBlocksIndicatorService::defaultParams();
        $botConfig = is_array($bot->config) ? $bot->config : [];
        $legacy = isset($botConfig[self::KEY_ORDER_BLOCKS]) && is_array($botConfig[self::KEY_ORDER_BLOCKS])
            ? $botConfig[self::KEY_ORDER_BLOCKS]
            : [];
        $row = static::query()
            ->where('bot_id', $bot->id)
            ->where('indicator_key', self::KEY_ORDER_BLOCKS)
            ->first();
        $db = is_array($row?->settings) ? $row->settings : [];

        return array_replace($defaults, $legacy, $db);
    }

    /**
     * Универсальный merge: дефолты сервиса ← legacy config ← indicator_settings.
     *
     * @param  callable(): array<string,mixed>  $defaultsFn
     * @return array<string, mixed>
     */
    public static function mergedByKey(Bot $bot, string $key, callable $defaultsFn): array
    {
        $defaults = $defaultsFn();
        $botConfig = is_array($bot->config) ? $bot->config : [];
        $legacy = isset($botConfig[$key]) && is_array($botConfig[$key]) ? $botConfig[$key] : [];
        $row = static::query()
            ->where('bot_id', $bot->id)
            ->where('indicator_key', $key)
            ->first();
        $db = is_array($row?->settings) ? $row->settings : [];

        return array_replace($defaults, $legacy, $db);
    }

    /** @return array<string, mixed> */
    public static function mergedBollingerParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_BOLLINGER, [BollingerIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedRsiParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_RSI, [RsiIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedSmaParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_SMA, [SmaIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedEmaParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_EMA, [EmaIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedAtrParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_ATR, [AtrIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedMacdParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_MACD, [MacdIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedStochasticParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_STOCHASTIC, [StochasticIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedCciParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_CCI, [CciIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedVwmaParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_VWMA, [VwmaIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedKeltnerParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_KELTNER, [KeltnerIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedEnvelopsParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_ENVELOPS, [EnvelopsIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedParabolicSarParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_PARABOLIC_SAR, [ParabolicSarIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedSuperTrendParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_SUPERTREND, [SuperTrendIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedAdxParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_ADX, [AdxIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedWilliamsParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_WILLIAMS, [WilliamsRangeIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedAoParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_AO, [AoIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedAlligatorParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_ALLIGATOR, [AlligatorIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedIchimokuParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_ICHIMOKU, [IchimokuIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedPriceChannelParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_PRICE_CHANNEL, [PriceChannelIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedNrtrParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_NRTR, [NrtrIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedHmaParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_HMA, [HmaIndicatorService::class, 'defaultParams']);
    }

    /** @return array<string, mixed> */
    public static function mergedAroonParams(Bot $bot): array
    {
        return self::mergedByKey($bot, self::KEY_AROON, [AroonIndicatorService::class, 'defaultParams']);
    }
}
