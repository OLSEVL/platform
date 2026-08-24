<?php

namespace App\Support;

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
 * Каталог классических индикаторов, поддерживающих несколько экземпляров на графике.
 *
 * help_key — ключ из попапа (sma, parabolic-sar); api_key — ключ API/slayers (parabolic_sar).
 * placement: overlay — линии на свечах; oscillator — отдельная pane снизу.
 */
final class ChartIndicatorInstanceRegistry
{
    /**
     * @var array<string, array{
     *   api_key: string,
     *   service: class-string,
     *   form: class-string,
     *   placement: 'overlay'|'oscillator',
     *   render: string,
     *   title_keys: list<string>,
     *   vary_key: ?string,
     *   color_key: string
     * }>
     */
    private const ENTRIES = [
        'bollinger' => ['api_key' => 'bollinger', 'service' => BollingerIndicatorService::class, 'form' => BollingerSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3', 'title_keys' => ['length', 'deviation'], 'vary_key' => 'length', 'color_key' => 'color_mid'],
        'rsi' => ['api_key' => 'rsi', 'service' => RsiIndicatorService::class, 'form' => RsiSettingsForm::class, 'placement' => 'oscillator', 'render' => 'rsi', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'rsi'],
        'sma' => ['api_key' => 'sma', 'service' => SmaIndicatorService::class, 'form' => SmaSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'ma'],
        'ema' => ['api_key' => 'ema', 'service' => EmaIndicatorService::class, 'form' => EmaSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'ema'],
        'vwma' => ['api_key' => 'vwma', 'service' => VwmaIndicatorService::class, 'form' => VwmaSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'vwma'],
        'atr' => ['api_key' => 'atr', 'service' => AtrIndicatorService::class, 'form' => AtrSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'atr'],
        'macd' => ['api_key' => 'macd', 'service' => MacdIndicatorService::class, 'form' => MacdSettingsForm::class, 'placement' => 'oscillator', 'render' => 'macd', 'title_keys' => ['length_fast', 'length_slow', 'length_signal'], 'vary_key' => 'length_fast', 'color_key' => 'color'],
        'stochastic' => ['api_key' => 'stochastic', 'service' => StochasticIndicatorService::class, 'form' => StochasticSettingsForm::class, 'placement' => 'oscillator', 'render' => 'stochastic', 'title_keys' => ['period1', 'period2', 'period3'], 'vary_key' => 'period1', 'color_key' => 'color'],
        'cci' => ['api_key' => 'cci', 'service' => CciIndicatorService::class, 'form' => CciSettingsForm::class, 'placement' => 'oscillator', 'render' => 'cci', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'cci'],
        'keltner' => ['api_key' => 'keltner', 'service' => KeltnerIndicatorService::class, 'form' => KeltnerSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3', 'title_keys' => ['ema_period', 'atr_period', 'atr_coef'], 'vary_key' => 'ema_period', 'color_key' => 'color_mid'],
        'envelops' => ['api_key' => 'envelops', 'service' => EnvelopsIndicatorService::class, 'form' => EnvelopsSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3', 'title_keys' => ['length', 'deviation'], 'vary_key' => 'length', 'color_key' => 'color_mid'],
        'parabolic-sar' => ['api_key' => 'parabolic_sar', 'service' => ParabolicSarIndicatorService::class, 'form' => ParabolicSarSettingsForm::class, 'placement' => 'overlay', 'render' => 'points', 'title_keys' => ['step', 'max_step'], 'vary_key' => null, 'color_key' => 'color', 'series_key' => 'sar'],
        'supertrend' => ['api_key' => 'supertrend', 'service' => SuperTrendIndicatorService::class, 'form' => SuperTrendSettingsForm::class, 'placement' => 'overlay', 'render' => 'supertrend', 'title_keys' => ['length', 'deviation'], 'vary_key' => 'length', 'color_key' => 'color_center'],
        'adx' => ['api_key' => 'adx', 'service' => AdxIndicatorService::class, 'form' => AdxSettingsForm::class, 'placement' => 'oscillator', 'render' => 'adx', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color_adx'],
        'williams' => ['api_key' => 'williams', 'service' => WilliamsRangeIndicatorService::class, 'form' => WilliamsRangeSettingsForm::class, 'placement' => 'oscillator', 'render' => 'williams', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color', 'series_key' => 'wr'],
        'ao' => ['api_key' => 'ao', 'service' => AoIndicatorService::class, 'form' => AoSettingsForm::class, 'placement' => 'oscillator', 'render' => 'ao', 'title_keys' => ['length_fast', 'length_slow'], 'vary_key' => 'length_fast', 'color_key' => 'color'],
        'alligator' => ['api_key' => 'alligator', 'service' => AlligatorIndicatorService::class, 'form' => AlligatorSettingsForm::class, 'placement' => 'overlay', 'render' => 'alligator', 'title_keys' => ['length_jaw', 'length_teeth', 'length_lips'], 'vary_key' => 'length_jaw', 'color_key' => 'color_jaw'],
        'ichimoku' => ['api_key' => 'ichimoku', 'service' => IchimokuIndicatorService::class, 'form' => IchimokuSettingsForm::class, 'placement' => 'overlay', 'render' => 'ichimoku', 'title_keys' => ['tenkan', 'kijun', 'senkou_b'], 'vary_key' => 'tenkan', 'color_key' => 'color_tenkan'],
        'price-channel' => ['api_key' => 'price_channel', 'service' => PriceChannelIndicatorService::class, 'form' => PriceChannelSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands2', 'title_keys' => ['length_up', 'length_down'], 'vary_key' => 'length_up', 'color_key' => 'color_up'],
        'nrtr' => ['api_key' => 'nrtr', 'service' => NrtrIndicatorService::class, 'form' => NrtrSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3_center', 'title_keys' => ['length', 'deviation'], 'vary_key' => 'length', 'color_key' => 'color_center'],
        'hma' => ['api_key' => 'hma', 'service' => HmaIndicatorService::class, 'form' => HmaSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'hma'],
        'aroon' => ['api_key' => 'aroon', 'service' => AroonIndicatorService::class, 'form' => AroonSettingsForm::class, 'placement' => 'oscillator', 'render' => 'aroon', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color_up'],
        'momentum' => ['api_key' => 'momentum', 'service' => MomentumIndicatorService::class, 'form' => MomentumSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'momentum'],
        'roc' => ['api_key' => 'roc', 'service' => RocIndicatorService::class, 'form' => RocSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color', 'series_key' => 'roc'],
        'osma' => ['api_key' => 'osma', 'service' => OsmaIndicatorService::class, 'form' => OsmaSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length_fast', 'length_slow', 'length_signal'], 'vary_key' => 'length_fast', 'color_key' => 'color', 'series_key' => 'osma'],
        'trix' => ['api_key' => 'trix', 'service' => TrixIndicatorService::class, 'form' => TrixSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'trix'],
        'cmo' => ['api_key' => 'cmo', 'service' => CmoIndicatorService::class, 'form' => CmoSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color', 'series_key' => 'cmo'],
        // DemarkerIndicatorService::compute() кладёт линию в series['dem'], не demarker.
        'demarker' => ['api_key' => 'demarker', 'service' => DemarkerIndicatorService::class, 'form' => DemarkerSettingsForm::class, 'placement' => 'oscillator', 'render' => 'demarker', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color', 'series_key' => 'dem'],
        'rvi' => ['api_key' => 'rvi', 'service' => RviIndicatorService::class, 'form' => RviSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color', 'series_key' => 'rvi'],
        'ultimate' => ['api_key' => 'ultimate', 'service' => UltimateIndicatorService::class, 'form' => UltimateSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['period1', 'period2', 'period3'], 'vary_key' => 'period1', 'color_key' => 'color', 'series_key' => 'uo'],
        'smi' => ['api_key' => 'smi', 'service' => SmiIndicatorService::class, 'form' => SmiSettingsForm::class, 'placement' => 'oscillator', 'render' => 'smi', 'title_keys' => ['period1', 'period2', 'period3', 'period4'], 'vary_key' => 'period1', 'color_key' => 'color'],
        'stddev' => ['api_key' => 'stddev', 'service' => StdDevIndicatorService::class, 'form' => StdDevSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color', 'series_key' => 'stddev'],
        'sma-channel' => ['api_key' => 'sma_channel', 'service' => SmaChannelIndicatorService::class, 'form' => SmaChannelSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3', 'title_keys' => ['length', 'deviation'], 'vary_key' => 'length', 'color_key' => 'color_mid'],
        'ivashov' => ['api_key' => 'ivashov', 'service' => IvashovRangeIndicatorService::class, 'form' => IvashovRangeSettingsForm::class, 'placement' => 'oscillator', 'render' => 'ivashov', 'title_keys' => ['length_ma', 'length_avg'], 'vary_key' => 'length_ma', 'color_key' => 'color'],
        'linreg' => ['api_key' => 'linreg', 'service' => LinRegIndicatorService::class, 'form' => LinRegSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color', 'series_key' => 'linreg'],
        'linreg-channel' => ['api_key' => 'linreg_channel', 'service' => LinRegChannelIndicatorService::class, 'form' => LinRegChannelSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3', 'title_keys' => ['period', 'up_deviation', 'down_deviation'], 'vary_key' => 'period', 'color_key' => 'color_mid'],
        'kalman' => ['api_key' => 'kalman', 'service' => KalmanIndicatorService::class, 'form' => KalmanSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['sharpness', 'k'], 'vary_key' => null, 'color_key' => 'color', 'series_key' => 'kalman'],
        'fractal' => ['api_key' => 'fractal', 'service' => FractalIndicatorService::class, 'form' => FractalSettingsForm::class, 'placement' => 'overlay', 'render' => 'fractal', 'title_keys' => [], 'vary_key' => null, 'color_key' => 'color'],
        'donchian' => ['api_key' => 'donchian', 'service' => DonchianIndicatorService::class, 'form' => DonchianSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands2', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color_up'],
        'stoch-rsi' => ['api_key' => 'stoch_rsi', 'service' => StochRsiIndicatorService::class, 'form' => StochRsiSettingsForm::class, 'placement' => 'oscillator', 'render' => 'stochastic', 'title_keys' => ['rsi_length', 'stochastic_length', 'k', 'd'], 'vary_key' => 'rsi_length', 'color_key' => 'color'],
        'ac' => ['api_key' => 'ac', 'service' => AcIndicatorService::class, 'form' => AcSettingsForm::class, 'placement' => 'oscillator', 'render' => 'histogram', 'title_keys' => ['length_long', 'length_short'], 'vary_key' => 'length_long', 'color_key' => 'color_histogram', 'series_key' => 'ac'],
        'bulls-power' => ['api_key' => 'bulls_power', 'service' => BullsPowerIndicatorService::class, 'form' => BullsPowerSettingsForm::class, 'placement' => 'oscillator', 'render' => 'histogram', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color_histogram', 'series_key' => 'bulls_power'],
        'bears-power' => ['api_key' => 'bears_power', 'service' => BearsPowerIndicatorService::class, 'form' => BearsPowerSettingsForm::class, 'placement' => 'oscillator', 'render' => 'histogram', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color_histogram', 'series_key' => 'bears_power'],
        'dpo' => ['api_key' => 'dpo', 'service' => DpoIndicatorService::class, 'form' => DpoSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length_sma'], 'vary_key' => 'length_sma', 'color_key' => 'color', 'series_key' => 'dpo'],
        'qstick' => ['api_key' => 'qstick', 'service' => QstickIndicatorService::class, 'form' => QstickSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'qstick'],
        'vh-filter' => ['api_key' => 'vh_filter', 'service' => VhFilterIndicatorService::class, 'form' => VhFilterSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color', 'series_key' => 'vh_filter'],
        'efficiency-ratio' => ['api_key' => 'efficiency_ratio', 'service' => EfficiencyRatioIndicatorService::class, 'form' => EfficiencyRatioSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'er'],
        'ravi' => ['api_key' => 'ravi', 'service' => RaviIndicatorService::class, 'form' => RaviSettingsForm::class, 'placement' => 'oscillator', 'render' => 'ravi', 'title_keys' => ['length_slow', 'length_fast'], 'vary_key' => 'length_slow', 'color_key' => 'color_ravi'],
        'cog' => ['api_key' => 'cog', 'service' => CogIndicatorService::class, 'form' => CogSettingsForm::class, 'placement' => 'oscillator', 'render' => 'cog', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color_cog'],
        'pivot-floor' => ['api_key' => 'pivot_floor', 'service' => PivotFloorIndicatorService::class, 'form' => PivotFloorSettingsForm::class, 'placement' => 'overlay', 'render' => 'pivot_floor', 'title_keys' => ['period'], 'vary_key' => 'period', 'color_key' => 'color'],
        'mass-index' => ['api_key' => 'mass_index', 'service' => MassIndexIndicatorService::class, 'form' => MassIndexSettingsForm::class, 'placement' => 'oscillator', 'render' => 'mass_index', 'title_keys' => ['length_ema', 'period_sum'], 'vary_key' => 'length_ema', 'color_key' => 'color_mi'],
        'nrtr-watr' => ['api_key' => 'nrtr_watr', 'service' => NrtrWatrIndicatorService::class, 'form' => NrtrWatrSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3_center', 'title_keys' => ['length', 'atr_multiplicator'], 'vary_key' => 'length', 'color_key' => 'color_center'],
        'price-channel-adaptive' => ['api_key' => 'price_channel_adaptive', 'service' => PriceChannelAdaptiveIndicatorService::class, 'form' => PriceChannelAdaptiveSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands2', 'title_keys' => ['adx_period', 'ratio'], 'vary_key' => 'adx_period', 'color_key' => 'color_up'],
        'price-channel-offset' => ['api_key' => 'price_channel_offset', 'service' => PriceChannelOffsetIndicatorService::class, 'form' => PriceChannelOffsetSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands2', 'title_keys' => ['length', 'offset_percent'], 'vary_key' => 'length', 'color_key' => 'color_up'],
        'parabolic-bollinger' => ['api_key' => 'parabolic_bollinger', 'service' => ParabolicBollingerIndicatorService::class, 'form' => ParabolicBollingerSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3', 'title_keys' => ['length', 'deviation'], 'vary_key' => 'length', 'color_key' => 'color_mid'],
        'parabolic-price-channel' => ['api_key' => 'parabolic_price_channel', 'service' => ParabolicPriceChannelIndicatorService::class, 'form' => ParabolicPriceChannelSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands2', 'title_keys' => ['length_up', 'length_down'], 'vary_key' => 'length_up', 'color_key' => 'color_up'],
        'atr-channel' => ['api_key' => 'atr_channel', 'service' => AtrChannelIndicatorService::class, 'form' => AtrChannelSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3', 'title_keys' => ['length', 'multiplier'], 'vary_key' => 'length', 'color_key' => 'color_mid'],
        'offset-sma' => ['api_key' => 'offset_sma', 'service' => OffsetSmaIndicatorService::class, 'form' => OffsetSmaSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length', 'offset'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'sma'],
        'offset-ema' => ['api_key' => 'offset_ema', 'service' => OffsetEmaIndicatorService::class, 'form' => OffsetEmaSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length', 'offset'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'ema'],
        'offset-ssma' => ['api_key' => 'offset_ssma', 'service' => OffsetSsmaIndicatorService::class, 'form' => OffsetSsmaSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length', 'offset'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'ssma'],
        'volatility-average' => ['api_key' => 'volatility_average', 'service' => VolatilityAverageIndicatorService::class, 'form' => VolatilityAverageSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['sma_len'], 'vary_key' => 'sma_len', 'color_key' => 'color', 'series_key' => 'volatility'],
        'volatility-average-channel' => ['api_key' => 'volatility_average_channel', 'service' => VolatilityAverageChannelIndicatorService::class, 'form' => VolatilityAverageChannelSettingsForm::class, 'placement' => 'oscillator', 'render' => 'volatility_average_channel', 'title_keys' => ['sma_len_slow', 'sma_len_fast', 'channel_deviation'], 'vary_key' => 'sma_len_slow', 'color_key' => 'color'],
        'price-oscillator' => ['api_key' => 'price_oscillator', 'service' => PriceOscillatorIndicatorService::class, 'form' => PriceOscillatorSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length_short', 'length_long'], 'vary_key' => 'length_short', 'color_key' => 'color', 'series_key' => 'po'],
        'dynamic-trend-detector' => ['api_key' => 'dynamic_trend_detector', 'service' => DynamicTrendDetectorIndicatorService::class, 'form' => DynamicTrendDetectorSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length', 'correction_coeff'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'dtd'],
        'adaptive-look-back' => ['api_key' => 'adaptive_look_back', 'service' => AdaptiveLookBackIndicatorService::class, 'form' => AdaptiveLookBackSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'alb'],
        'linreg-channel-fast' => ['api_key' => 'linreg_channel_fast', 'service' => LinRegChannelFastIndicatorService::class, 'form' => LinRegChannelFastSettingsForm::class, 'placement' => 'overlay', 'render' => 'bands3', 'title_keys' => ['period', 'up_deviation', 'down_deviation'], 'vary_key' => 'period', 'color_key' => 'color_mid'],
        'volatility-candles' => ['api_key' => 'volatility_candles', 'service' => VolatilityCandlesIndicatorService::class, 'form' => VolatilityCandlesSettingsForm::class, 'placement' => 'oscillator', 'render' => 'osc_line', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'vc'],
        'zigzag' => ['api_key' => 'zigzag', 'service' => ZigZagIndicatorService::class, 'form' => ZigZagSettingsForm::class, 'placement' => 'overlay', 'render' => 'single', 'title_keys' => ['length'], 'vary_key' => 'length', 'color_key' => 'color', 'series_key' => 'line'],
        'volatility-average-twice' => ['api_key' => 'volatility_average_twice', 'service' => VolatilityAverageTwiceIndicatorService::class, 'form' => VolatilityAverageTwiceSettingsForm::class, 'placement' => 'oscillator', 'render' => 'volatility_average_twice', 'title_keys' => ['sma_len_slow', 'sma_len_fast'], 'vary_key' => 'sma_len_slow', 'color_key' => 'color'],
        'volatility-stages-aw' => ['api_key' => 'volatility_stages_aw', 'service' => VolatilityStagesAWIndicatorService::class, 'form' => VolatilityStagesAWSettingsForm::class, 'placement' => 'oscillator', 'render' => 'volatility_stages_aw', 'title_keys' => ['sma_len_slow', 'sma_len_fast', 'channel_deviation'], 'vary_key' => 'sma_len_slow', 'color_key' => 'color'],
    ];

    /**
     * @return list<string>
     */
    public static function supportedHelpKeys(): array
    {
        return array_keys(self::ENTRIES);
    }

    public static function isSupported(string $helpKey): bool
    {
        return isset(self::ENTRIES[$helpKey]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(string $helpKey): array
    {
        abort_unless(isset(self::ENTRIES[$helpKey]), 404, 'Unsupported chart indicator instance type.');

        return self::ENTRIES[$helpKey];
    }

    public static function apiKey(string $helpKey): string
    {
        return (string) self::entry($helpKey)['api_key'];
    }

    /**
     * @return class-string
     */
    public static function serviceClass(string $helpKey): string
    {
        return self::entry($helpKey)['service'];
    }

    /**
     * @return class-string
     */
    public static function formClass(string $helpKey): string
    {
        return self::entry($helpKey)['form'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultParams(string $helpKey): array
    {
        $service = self::serviceClass($helpKey);

        return $service::defaultParams();
    }

    /**
     * Спеки рендера для JS (placement + render kind + series_key).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function renderSpecsForJs(): array
    {
        $out = [];
        foreach (self::ENTRIES as $helpKey => $meta) {
            $out[$helpKey] = [
                'placement' => $meta['placement'],
                'render' => $meta['render'],
                'series_key' => $meta['series_key'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function validationRuleIn(): array
    {
        return self::supportedHelpKeys();
    }
}
