<?php

namespace App\Support;

use App\Models\Bot;
use App\Models\IndicatorSetting;
use App\Services\Indicators\AcIndicatorService;
use App\Services\Indicators\AdaptiveLookBackIndicatorService;
use App\Services\Indicators\AdxIndicatorService;
use App\Services\Indicators\AlligatorIndicatorService;
use App\Services\Indicators\AoIndicatorService;
use App\Services\Indicators\AroonIndicatorService;
use App\Services\Indicators\AtrChannelIndicatorService;
use App\Services\Indicators\AtrIndicatorService;
use App\Services\Indicators\BearsPowerIndicatorService;
use App\Services\Indicators\BollingerIndicatorService;
use App\Services\Indicators\BullsPowerIndicatorService;
use App\Services\Indicators\CciIndicatorService;
use App\Services\Indicators\CmoIndicatorService;
use App\Services\Indicators\CogIndicatorService;
use App\Services\Indicators\DemarkerIndicatorService;
use App\Services\Indicators\DonchianIndicatorService;
use App\Services\Indicators\DpoIndicatorService;
use App\Services\Indicators\DynamicTrendDetectorIndicatorService;
use App\Services\Indicators\EfficiencyRatioIndicatorService;
use App\Services\Indicators\EmaIndicatorService;
use App\Services\Indicators\EnvelopsIndicatorService;
use App\Services\Indicators\FractalIndicatorService;
use App\Services\Indicators\HmaIndicatorService;
use App\Services\Indicators\IchimokuIndicatorService;
use App\Services\Indicators\IvashovRangeIndicatorService;
use App\Services\Indicators\KalmanIndicatorService;
use App\Services\Indicators\KeltnerIndicatorService;
use App\Services\Indicators\LinRegChannelFastIndicatorService;
use App\Services\Indicators\LinRegChannelIndicatorService;
use App\Services\Indicators\LinRegIndicatorService;
use App\Services\Indicators\MacdIndicatorService;
use App\Services\Indicators\MassIndexIndicatorService;
use App\Services\Indicators\MomentumIndicatorService;
use App\Services\Indicators\NrtrIndicatorService;
use App\Services\Indicators\NrtrWatrIndicatorService;
use App\Services\Indicators\OffsetEmaIndicatorService;
use App\Services\Indicators\OffsetSmaIndicatorService;
use App\Services\Indicators\OffsetSsmaIndicatorService;
use App\Services\Indicators\OsmaIndicatorService;
use App\Services\Indicators\ParabolicBollingerIndicatorService;
use App\Services\Indicators\ParabolicPriceChannelIndicatorService;
use App\Services\Indicators\ParabolicSarIndicatorService;
use App\Services\Indicators\PivotFloorIndicatorService;
use App\Services\Indicators\PriceChannelAdaptiveIndicatorService;
use App\Services\Indicators\PriceChannelIndicatorService;
use App\Services\Indicators\PriceChannelOffsetIndicatorService;
use App\Services\Indicators\PriceOscillatorIndicatorService;
use App\Services\Indicators\QstickIndicatorService;
use App\Services\Indicators\RaviIndicatorService;
use App\Services\Indicators\RocIndicatorService;
use App\Services\Indicators\RsiIndicatorService;
use App\Services\Indicators\RviIndicatorService;
use App\Services\Indicators\SmaChannelIndicatorService;
use App\Services\Indicators\SmaIndicatorService;
use App\Services\Indicators\SmiIndicatorService;
use App\Services\Indicators\StdDevIndicatorService;
use App\Services\Indicators\StochRsiIndicatorService;
use App\Services\Indicators\StochasticIndicatorService;
use App\Services\Indicators\SuperTrendIndicatorService;
use App\Services\Indicators\TrixIndicatorService;
use App\Services\Indicators\UltimateIndicatorService;
use App\Services\Indicators\VhFilterIndicatorService;
use App\Services\Indicators\VolatilityAverageChannelIndicatorService;
use App\Services\Indicators\VolatilityAverageIndicatorService;
use App\Services\Indicators\VolatilityAverageTwiceIndicatorService;
use App\Services\Indicators\VolatilityCandlesIndicatorService;
use App\Services\Indicators\VolatilityStagesAWIndicatorService;
use App\Services\Indicators\VwmaIndicatorService;
use App\Services\Indicators\WilliamsRangeIndicatorService;
use App\Services\Indicators\ZigZagIndicatorService;

/**
 * Заголовки классических индикаторов на графике в стиле Pocket Option:
 * «RSI 14», «MACD 12 26 9» — имя + ключевые числовые параметры.
 *
 * Добавляет в payload поля `params` и `title` (без тяжёлого изменения каждого compute()).
 */
final class ClassicIndicatorChartTitle
{
    /**
     * Ключ JSON → имя + ключи params для подписи + как достать merged/default.
     *
     * @var array<string, array{name:string, keys:list<string>, merged:string, defaults:class-string}>
     */
    private const MAP = [
        'bollinger' => ['name' => 'Bollinger', 'keys' => ['length', 'deviation'], 'merged' => 'mergedBollingerParams', 'defaults' => BollingerIndicatorService::class],
        'rsi' => ['name' => 'RSI', 'keys' => ['length'], 'merged' => 'mergedRsiParams', 'defaults' => RsiIndicatorService::class],
        'sma' => ['name' => 'SMA', 'keys' => ['length'], 'merged' => 'mergedSmaParams', 'defaults' => SmaIndicatorService::class],
        'ema' => ['name' => 'EMA', 'keys' => ['length'], 'merged' => 'mergedEmaParams', 'defaults' => EmaIndicatorService::class],
        'vwma' => ['name' => 'VWMA', 'keys' => ['length'], 'merged' => 'mergedVwmaParams', 'defaults' => VwmaIndicatorService::class],
        'atr' => ['name' => 'ATR', 'keys' => ['length'], 'merged' => 'mergedAtrParams', 'defaults' => AtrIndicatorService::class],
        'macd' => ['name' => 'MACD', 'keys' => ['length_fast', 'length_slow', 'length_signal'], 'merged' => 'mergedMacdParams', 'defaults' => MacdIndicatorService::class],
        'stochastic' => ['name' => 'Stochastic', 'keys' => ['period1', 'period2', 'period3'], 'merged' => 'mergedStochasticParams', 'defaults' => StochasticIndicatorService::class],
        'cci' => ['name' => 'CCI', 'keys' => ['length'], 'merged' => 'mergedCciParams', 'defaults' => CciIndicatorService::class],
        'keltner' => ['name' => 'Keltner', 'keys' => ['ema_period', 'atr_period', 'atr_coef'], 'merged' => 'mergedKeltnerParams', 'defaults' => KeltnerIndicatorService::class],
        'envelops' => ['name' => 'Envelops', 'keys' => ['length', 'deviation'], 'merged' => 'mergedEnvelopsParams', 'defaults' => EnvelopsIndicatorService::class],
        'parabolic_sar' => ['name' => 'Parabolic SAR', 'keys' => ['step', 'max_step'], 'merged' => 'mergedParabolicSarParams', 'defaults' => ParabolicSarIndicatorService::class],
        'supertrend' => ['name' => 'SuperTrend', 'keys' => ['length', 'deviation'], 'merged' => 'mergedSuperTrendParams', 'defaults' => SuperTrendIndicatorService::class],
        'adx' => ['name' => 'ADX', 'keys' => ['length'], 'merged' => 'mergedAdxParams', 'defaults' => AdxIndicatorService::class],
        'williams' => ['name' => 'Williams %R', 'keys' => ['period'], 'merged' => 'mergedWilliamsParams', 'defaults' => WilliamsRangeIndicatorService::class],
        'ao' => ['name' => 'AO', 'keys' => ['length_fast', 'length_slow'], 'merged' => 'mergedAoParams', 'defaults' => AoIndicatorService::class],
        'alligator' => ['name' => 'Alligator', 'keys' => ['length_jaw', 'length_teeth', 'length_lips'], 'merged' => 'mergedAlligatorParams', 'defaults' => AlligatorIndicatorService::class],
        'ichimoku' => ['name' => 'Ichimoku', 'keys' => ['tenkan', 'kijun', 'senkou_b'], 'merged' => 'mergedIchimokuParams', 'defaults' => IchimokuIndicatorService::class],
        'price_channel' => ['name' => 'Price Channel', 'keys' => ['length_up', 'length_down'], 'merged' => 'mergedPriceChannelParams', 'defaults' => PriceChannelIndicatorService::class],
        'nrtr' => ['name' => 'NRTR', 'keys' => ['length', 'deviation'], 'merged' => 'mergedNrtrParams', 'defaults' => NrtrIndicatorService::class],
        'hma' => ['name' => 'HMA', 'keys' => ['length'], 'merged' => 'mergedHmaParams', 'defaults' => HmaIndicatorService::class],
        'aroon' => ['name' => 'Aroon', 'keys' => ['period'], 'merged' => 'mergedAroonParams', 'defaults' => AroonIndicatorService::class],
        'momentum' => ['name' => 'Momentum', 'keys' => ['length'], 'merged' => 'mergedMomentumParams', 'defaults' => MomentumIndicatorService::class],
        'roc' => ['name' => 'ROC', 'keys' => ['period'], 'merged' => 'mergedRocParams', 'defaults' => RocIndicatorService::class],
        'osma' => ['name' => 'OsMA', 'keys' => ['length_fast', 'length_slow', 'length_signal'], 'merged' => 'mergedOsmaParams', 'defaults' => OsmaIndicatorService::class],
        'trix' => ['name' => 'TRIX', 'keys' => ['length'], 'merged' => 'mergedTrixParams', 'defaults' => TrixIndicatorService::class],
        'cmo' => ['name' => 'CMO', 'keys' => ['period'], 'merged' => 'mergedCmoParams', 'defaults' => CmoIndicatorService::class],
        'demarker' => ['name' => 'DeMarker', 'keys' => ['period'], 'merged' => 'mergedDemarkerParams', 'defaults' => DemarkerIndicatorService::class],
        'rvi' => ['name' => 'RVI', 'keys' => ['period'], 'merged' => 'mergedRviParams', 'defaults' => RviIndicatorService::class],
        'ultimate' => ['name' => 'Ultimate Oscillator', 'keys' => ['period1', 'period2', 'period3'], 'merged' => 'mergedUltimateParams', 'defaults' => UltimateIndicatorService::class],
        'smi' => ['name' => 'SMI', 'keys' => ['period1', 'period2', 'period3', 'period4'], 'merged' => 'mergedSmiParams', 'defaults' => SmiIndicatorService::class],
        'stddev' => ['name' => 'StdDev', 'keys' => ['period'], 'merged' => 'mergedStdDevParams', 'defaults' => StdDevIndicatorService::class],
        'sma_channel' => ['name' => 'SMA Channel', 'keys' => ['length', 'deviation'], 'merged' => 'mergedSmaChannelParams', 'defaults' => SmaChannelIndicatorService::class],
        'ivashov' => ['name' => 'Ivashov Range', 'keys' => ['length_ma', 'length_avg'], 'merged' => 'mergedIvashovParams', 'defaults' => IvashovRangeIndicatorService::class],
        'linreg' => ['name' => 'Linear Regression', 'keys' => ['period'], 'merged' => 'mergedLinRegParams', 'defaults' => LinRegIndicatorService::class],
        'linreg_channel' => ['name' => 'Linear Reg. Channel', 'keys' => ['period', 'up_deviation', 'down_deviation'], 'merged' => 'mergedLinRegChannelParams', 'defaults' => LinRegChannelIndicatorService::class],
        'kalman' => ['name' => 'Kalman Filter', 'keys' => ['sharpness', 'k'], 'merged' => 'mergedKalmanParams', 'defaults' => KalmanIndicatorService::class],
        'fractal' => ['name' => 'Fractal', 'keys' => [], 'merged' => 'mergedFractalParams', 'defaults' => FractalIndicatorService::class],
        'donchian' => ['name' => 'Donchian', 'keys' => ['length'], 'merged' => 'mergedDonchianParams', 'defaults' => DonchianIndicatorService::class],
        'stoch_rsi' => ['name' => 'Stoch RSI', 'keys' => ['rsi_length', 'stochastic_length', 'k', 'd'], 'merged' => 'mergedStochRsiParams', 'defaults' => StochRsiIndicatorService::class],
        'ac' => ['name' => 'AC', 'keys' => ['length_long', 'length_short'], 'merged' => 'mergedAcParams', 'defaults' => AcIndicatorService::class],
        'bulls_power' => ['name' => 'Bulls Power', 'keys' => ['period'], 'merged' => 'mergedBullsPowerParams', 'defaults' => BullsPowerIndicatorService::class],
        'bears_power' => ['name' => 'Bears Power', 'keys' => ['period'], 'merged' => 'mergedBearsPowerParams', 'defaults' => BearsPowerIndicatorService::class],
        'dpo' => ['name' => 'DPO', 'keys' => ['length_sma'], 'merged' => 'mergedDpoParams', 'defaults' => DpoIndicatorService::class],
        'qstick' => ['name' => 'QStick', 'keys' => ['length'], 'merged' => 'mergedQstickParams', 'defaults' => QstickIndicatorService::class],
        'vh_filter' => ['name' => 'VHFilter', 'keys' => ['period'], 'merged' => 'mergedVhFilterParams', 'defaults' => VhFilterIndicatorService::class],
        'efficiency_ratio' => ['name' => 'Efficiency Ratio', 'keys' => ['length'], 'merged' => 'mergedEfficiencyRatioParams', 'defaults' => EfficiencyRatioIndicatorService::class],
        'ravi' => ['name' => 'RAVI', 'keys' => ['length_slow', 'length_fast'], 'merged' => 'mergedRaviParams', 'defaults' => RaviIndicatorService::class],
        'cog' => ['name' => 'COG', 'keys' => ['length'], 'merged' => 'mergedCogParams', 'defaults' => CogIndicatorService::class],
        'pivot_floor' => ['name' => 'Pivot Floor', 'keys' => ['period'], 'merged' => 'mergedPivotFloorParams', 'defaults' => PivotFloorIndicatorService::class],
        'mass_index' => ['name' => 'Mass Index', 'keys' => ['length_ema', 'period_sum'], 'merged' => 'mergedMassIndexParams', 'defaults' => MassIndexIndicatorService::class],
        'nrtr_watr' => ['name' => 'NRTR WATR', 'keys' => ['length', 'atr_multiplicator'], 'merged' => 'mergedNrtrWatrParams', 'defaults' => NrtrWatrIndicatorService::class],
        'price_channel_adaptive' => ['name' => 'PC Adaptive', 'keys' => ['adx_period', 'ratio'], 'merged' => 'mergedPriceChannelAdaptiveParams', 'defaults' => PriceChannelAdaptiveIndicatorService::class],
        'price_channel_offset' => ['name' => 'PC Offset', 'keys' => ['length', 'offset_percent'], 'merged' => 'mergedPriceChannelOffsetParams', 'defaults' => PriceChannelOffsetIndicatorService::class],
        'parabolic_bollinger' => ['name' => 'Parabolic BB', 'keys' => ['length', 'deviation'], 'merged' => 'mergedParabolicBollingerParams', 'defaults' => ParabolicBollingerIndicatorService::class],
        'parabolic_price_channel' => ['name' => 'Parabolic PC', 'keys' => ['length_up', 'length_down'], 'merged' => 'mergedParabolicPriceChannelParams', 'defaults' => ParabolicPriceChannelIndicatorService::class],
        'atr_channel' => ['name' => 'ATR Channel', 'keys' => ['length', 'multiplier'], 'merged' => 'mergedAtrChannelParams', 'defaults' => AtrChannelIndicatorService::class],
        'offset_sma' => ['name' => 'Offset SMA', 'keys' => ['length', 'offset'], 'merged' => 'mergedOffsetSmaParams', 'defaults' => OffsetSmaIndicatorService::class],
        'offset_ema' => ['name' => 'Offset EMA', 'keys' => ['length', 'offset'], 'merged' => 'mergedOffsetEmaParams', 'defaults' => OffsetEmaIndicatorService::class],
        'offset_ssma' => ['name' => 'Offset SSMA', 'keys' => ['length', 'offset'], 'merged' => 'mergedOffsetSsmaParams', 'defaults' => OffsetSsmaIndicatorService::class],
        'volatility_average' => ['name' => 'Vol Average', 'keys' => ['sma_len'], 'merged' => 'mergedVolatilityAverageParams', 'defaults' => VolatilityAverageIndicatorService::class],
        'volatility_average_channel' => ['name' => 'Vol Avg Channel', 'keys' => ['sma_len_slow', 'sma_len_fast', 'channel_deviation'], 'merged' => 'mergedVolatilityAverageChannelParams', 'defaults' => VolatilityAverageChannelIndicatorService::class],
        'price_oscillator' => ['name' => 'Price Oscillator', 'keys' => ['length_short', 'length_long'], 'merged' => 'mergedPriceOscillatorParams', 'defaults' => PriceOscillatorIndicatorService::class],
        'dynamic_trend_detector' => ['name' => 'DTD', 'keys' => ['length', 'correction_coeff'], 'merged' => 'mergedDynamicTrendDetectorParams', 'defaults' => DynamicTrendDetectorIndicatorService::class],
        'adaptive_look_back' => ['name' => 'Adaptive Look Back', 'keys' => ['length'], 'merged' => 'mergedAdaptiveLookBackParams', 'defaults' => AdaptiveLookBackIndicatorService::class],
        'linreg_channel_fast' => ['name' => 'LR Channel Fast', 'keys' => ['period', 'up_deviation', 'down_deviation'], 'merged' => 'mergedLinRegChannelFastParams', 'defaults' => LinRegChannelFastIndicatorService::class],
        'volatility_candles' => ['name' => 'Volatility Candles', 'keys' => ['length'], 'merged' => 'mergedVolatilityCandlesParams', 'defaults' => VolatilityCandlesIndicatorService::class],
        'zigzag' => ['name' => 'ZigZag', 'keys' => ['length'], 'merged' => 'mergedZigZagParams', 'defaults' => ZigZagIndicatorService::class],
        'volatility_average_twice' => ['name' => 'Vol Avg Twice', 'keys' => ['sma_len_slow', 'sma_len_fast'], 'merged' => 'mergedVolatilityAverageTwiceParams', 'defaults' => VolatilityAverageTwiceIndicatorService::class],
        'volatility_stages_aw' => ['name' => 'Vol Stages AW', 'keys' => ['sma_len_slow', 'sma_len_fast', 'channel_deviation'], 'merged' => 'mergedVolatilityStagesAWParams', 'defaults' => VolatilityStagesAWIndicatorService::class],
    ];

    /**
     * Добавляет в каждый присутствующий classic-payload поля params + title («RSI 14»).
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public static function enrich(array $json, ?Bot $bot): array
    {
        foreach (self::MAP as $key => $meta) {
            if (! isset($json[$key]) || ! is_array($json[$key])) {
                continue;
            }
            $params = self::resolveParams($bot, $meta['merged'], $meta['defaults']);
            // Подпись «RSI 14» должна совпадать с торговым config, а не только с indicator_settings.
            if ($bot !== null) {
                $params = BotTradingChartLayers::overlayParams($bot, $key, $params);
            }
            $json[$key]['params'] = $params;
            $json[$key]['title'] = self::format($meta['name'], $params, $meta['keys']);
        }

        return $json;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $params
     */
    public static function format(string $name, array $params, array $keys): string
    {
        $parts = [$name];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $params)) {
                continue;
            }
            $raw = $params[$key];
            if ($raw === null || $raw === '' || is_bool($raw) || is_array($raw)) {
                continue;
            }
            if (is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw))) {
                $parts[] = (string) (int) $raw;

                continue;
            }
            if (is_float($raw) || (is_numeric($raw) && str_contains((string) $raw, '.'))) {
                $num = (float) $raw;
                // 20.0 → "20", 2.5 → "2.5" (как на PO для deviation).
                $parts[] = abs($num - round($num)) < 1e-9
                    ? (string) (int) round($num)
                    : rtrim(rtrim(number_format($num, 4, '.', ''), '0'), '.');

                continue;
            }
            $parts[] = (string) $raw;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  class-string  $defaultsClass
     * @return array<string, mixed>
     */
    private static function resolveParams(?Bot $bot, string $mergedMethod, string $defaultsClass): array
    {
        if ($bot !== null && method_exists(IndicatorSetting::class, $mergedMethod)) {
            /** @var array<string, mixed> $params */
            $params = IndicatorSetting::{$mergedMethod}($bot);

            return $params;
        }

        /** @var array<string, mixed> $defaults */
        $defaults = $defaultsClass::defaultParams();

        return $defaults;
    }
}
