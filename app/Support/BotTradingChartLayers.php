<?php

namespace App\Support;

use App\Models\Bot;
use App\Services\CandleService;

/**
 * Какие слои графика на /bots/{id} соответствуют торговой логике бота
 * и как подставить в оверлей те же числа, что использует стратегия на вход.
 *
 * Иначе пользователь включает DeMarker/Bollinger вручную, а период/зоны
 * берутся из indicator_settings (дефолт 14 / 0.3–0.7), тогда как бот
 * торгует по config (например period=6 и зоны 10%/90%).
 */
final class BotTradingChartLayers
{
    /**
     * Боты, у которых торговые индикаторы на графике — экземпляры (не legacy-чекбоксы).
     */
    public static function usesChartIndicatorInstances(Bot $bot): bool
    {
        return in_array((string) $bot->type, [
            'strong_trend_demarker',
            DemarkerDualFastConfig::BOT_TYPE,
            'rsi_contrtrend',
            'rsi_trade',
            'cci_trade',
            'williams_range_trade',
            'sma_stochastic',
            'momentum_macd',
        ], true);
    }

    /**
     *
     * @return list<string>
     */
    public static function layerIds(Bot $bot): array
    {
        $type = (string) $bot->type;
        $cfg = self::config($bot);

        return match ($type) {
            'sweep_raid_liquidity' => array_values(array_filter([
                'layer-sweep-raid-liquidity',
                (bool) ($cfg['srl_demarker_enabled'] ?? false) ? 'layer-demarker' : null,
            ])),
            'engulfing_liquidity' => ['layer-engulfing-liquidity'],
            // DeMarker / RSI / MACD+… — через chart_indicator_instances (syncTradingInstances).
            'strong_trend_demarker' => [],
            DemarkerDualFastConfig::BOT_TYPE => [],
            'bollinger_contrtrend' => ['layer-bollinger'],
            'rsi_contrtrend' => [],
            'rsi_trade' => [],
            'cci_trade' => [],
            'williams_range_trade' => [],
            'rvi_trade' => ['layer-rvi'],
            'smc_flow' => ['layer-smc-flow'],
            'strong_level', 'candle_reversal_confirm' => ['layer-pa-dgt'],
            'envelop_trend' => ['layer-envelops'],
            'price_channel_trade' => ['layer-price-channel'],
            'parabolic_sar_trade' => ['layer-parabolic-sar'],
            'break_linreg_channel' => ['layer-linreg-channel-fast', 'layer-sma'],
            'pivot_points' => ['layer-pivot-floor'],
            'sma_stochastic' => [],
            'momentum_macd' => [],
            'parabolic_bollinger' => ['layer-parabolic-bollinger'],
            'parabolic_price_channel' => ['layer-parabolic-price-channel'],
            'pinbar_trade' => ['layer-sma'],
            'openrouter_llm_analyst' => self::analystLayerIds($cfg),
            ChartAnalystBotTypes::SRL => ['layer-llm-levels', 'layer-sweep-raid-liquidity'],
            ChartAnalystBotTypes::ENGULF => ['layer-llm-levels', 'layer-engulfing-liquidity'],
            'strong_trend_telegram' => self::telegramPullbackLayerIds($cfg),
            // Свечные паттерны без оверлей-индикатора: three_candles, three_soldier, Wave B impulse и т.п.
            default => [],
        };
    }

    /**
     * Таймфрейм, на котором стратегия считает вход (как series_timeframe в движке).
     * Кнопка «Сделать как у бота» ставит на графике именно его.
     */
    public static function chartTimeframeMinutes(Bot $bot): int
    {
        $cfg = self::config($bot);
        if (! in_array((string) $bot->type, ['strong_trend_demarker', DemarkerDualFastConfig::BOT_TYPE], true)) {
            return 1;
        }
        $tf = (int) ($cfg['timeframe'] ?? CandleService::TF_S30);

        return in_array($tf, [CandleService::TF_S30, 1], true) ? $tf : CandleService::TF_S30;
    }

    /**
     * Накладывает торговый config бота на merged-параметры индикатора.
     * Если тип бота этот слой не использует — params без изменений.
     *
     * $layerKey — ключ из query layers[] / ClassicIndicatorChartTitle (rsi, srl, demarker…).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function overlayParams(Bot $bot, string $layerKey, array $params): array
    {
        $type = (string) $bot->type;
        $cfg = self::config($bot);

        return match ($layerKey) {
            'srl' => self::overlaySrl($type, $cfg, $params),
            'eng' => self::overlayEng($type, $cfg, $params),
            'demarker' => self::overlayDemarker($type, $cfg, $params),
            'bollinger' => self::overlayBollinger($type, $cfg, $params),
            'rsi' => self::overlayRsi($type, $cfg, $params),
            'sma' => self::overlaySma($type, $cfg, $params),
            'ema' => self::overlayEma($type, $cfg, $params),
            'cci' => self::overlayCci($type, $cfg, $params),
            'williams' => self::overlayWilliams($type, $cfg, $params),
            'rvi' => self::overlayRvi($type, $cfg, $params),
            'envelops' => self::overlayEnvelops($type, $cfg, $params),
            'price_channel' => self::overlayPriceChannel($type, $cfg, $params),
            'parabolic_sar' => self::overlayParabolicSar($type, $cfg, $params),
            'linreg_channel_fast' => self::overlayLinRegFast($type, $cfg, $params),
            'macd' => self::overlayMacd($type, $cfg, $params),
            'momentum' => self::overlayMomentum($type, $cfg, $params),
            'stochastic' => self::overlayStochastic($type, $cfg, $params),
            'pivot_floor' => self::overlayPivotFloor($type, $cfg, $params),
            'parabolic_bollinger' => self::overlayParabolicBollinger($type, $cfg, $params),
            'parabolic_price_channel' => self::overlayParabolicPriceChannel($type, $cfg, $params),
            default => $params,
        };
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return list<string>
     */
    private static function analystLayerIds(array $cfg): array
    {
        // Уровни LLM на графике аналитика уже включены в разметке; SMC — только если включён в настройках.
        $ids = ['layer-llm-levels'];
        if (OpenRouterLlmConfig::analystSmcFlowEnabled($cfg)) {
            $ids[] = 'layer-smc-flow';
        }

        return $ids;
    }

    /**
     * Strong Trend Telegram: MA на графике нужна только если вход ждёт откат к ней.
     *
     * @param  array<string, mixed>  $cfg
     * @return list<string>
     */
    private static function telegramPullbackLayerIds(array $cfg): array
    {
        if (! (bool) ($cfg['require_pullback_to_ma'] ?? false)) {
            return [];
        }
        $ma = strtolower((string) ($cfg['pullback_ma_type'] ?? 'ema'));

        return $ma === 'sma' ? ['layer-sma'] : ['layer-ema'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(Bot $bot): array
    {
        return is_array($bot->config) ? $bot->config : [];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    /**
     * Engulfing Liquidity: оверлей ENGULF 1/2/3 совпадает с флагами входа бота.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayEng(string $type, array $cfg, array $params): array
    {
        if (! in_array($type, ['engulfing_liquidity', ChartAnalystBotTypes::ENGULF], true)) {
            return $params;
        }
        $params['allow_classic'] = (bool) ($cfg['eng_allow_classic'] ?? true);
        $params['allow_same_color'] = (bool) ($cfg['eng_allow_same_color'] ?? true);
        $params['show_step1'] = (bool) ($cfg['eng_show_step1'] ?? true);
        $params['show_step2'] = (bool) ($cfg['eng_show_step2'] ?? true);
        $params['show_step3'] = (bool) ($cfg['eng_show_step3'] ?? true);

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlaySrl(string $type, array $cfg, array $params): array
    {
        if (! in_array($type, ['sweep_raid_liquidity', ChartAnalystBotTypes::SRL], true)) {
            return $params;
        }
        $params['pivot_length'] = max(1, min(100, (int) ($cfg['srl_pivot_length'] ?? $params['pivot_length'] ?? 2)));
        $params['show_fractals'] = true;
        $params['show_sweeps'] = (bool) ($cfg['srl_enter_sweep'] ?? true);
        $enterPb = (bool) ($cfg['srl_enter_pullback'] ?? true);
        $params['show_raids'] = $enterPb;
        $params['show_pullback_entries'] = $enterPb;

        return $params;
    }

    /**
     * Форма DeMarker хранит зоны в процентах (10/90), индикатор считает 0..1.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayDemarker(string $type, array $cfg, array $params): array
    {
        if ($type === 'sweep_raid_liquidity') {
            if (! (bool) ($cfg['srl_demarker_enabled'] ?? false)) {
                return $params;
            }
            $params['period'] = max(1, min(500, (int) ($cfg['srl_demarker_period'] ?? $params['period'] ?? 6)));
            $params['level_oversold'] = max(0.0, min(1.0, (float) ($cfg['srl_demarker_oversold'] ?? 10) / 100));
            $params['level_overbought'] = max(0.0, min(1.0, (float) ($cfg['srl_demarker_overbought'] ?? 90) / 100));
            $params['show_levels'] = true;

            return $params;
        }

        if (! in_array($type, ['strong_trend_demarker', DemarkerDualFastConfig::BOT_TYPE], true)) {
            return $params;
        }
        $params['period'] = max(1, min(500, (int) ($cfg['demarker_period'] ?? $params['period'] ?? 6)));
        $params['level_oversold'] = max(0.0, min(1.0, (float) ($cfg['demarker_oversold'] ?? 10) / 100));
        $params['level_overbought'] = max(0.0, min(1.0, (float) ($cfg['demarker_overbought'] ?? 90) / 100));
        $params['show_levels'] = true;

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayBollinger(string $type, array $cfg, array $params): array
    {
        if ($type !== 'bollinger_contrtrend') {
            return $params;
        }
        $params['length'] = max(2, min(500, (int) ($cfg['bb_length'] ?? $params['length'] ?? 20)));
        $params['deviation'] = max(0.1, min(10.0, (float) ($cfg['bb_deviation'] ?? $params['deviation'] ?? 2.0)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayRsi(string $type, array $cfg, array $params): array
    {
        if (! in_array($type, ['rsi_contrtrend', 'rsi_trade'], true)) {
            return $params;
        }
        $params['length'] = max(2, min(500, (int) ($cfg['rsi_length'] ?? $params['length'] ?? 14)));
        $params['level_overbought'] = (float) ($cfg['rsi_up'] ?? $params['level_overbought'] ?? 70.0);
        $params['level_oversold'] = (float) ($cfg['rsi_down'] ?? $params['level_oversold'] ?? 30.0);
        $params['show_levels'] = true;

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlaySma(string $type, array $cfg, array $params): array
    {
        $usesSma = in_array($type, [
            'rsi_contrtrend',
            'sma_stochastic',
            'pinbar_trade',
            'break_linreg_channel',
        ], true);
        $telegramSma = $type === 'strong_trend_telegram'
            && (bool) ($cfg['require_pullback_to_ma'] ?? false)
            && strtolower((string) ($cfg['pullback_ma_type'] ?? 'ema')) === 'sma';
        if (! $usesSma && ! $telegramSma) {
            return $params;
        }
        if ($telegramSma) {
            $params['length'] = max(2, min(100, (int) ($cfg['pullback_ma_length'] ?? $params['length'] ?? 9)));

            return $params;
        }
        $params['length'] = max(2, min(500, (int) ($cfg['sma_length'] ?? $params['length'] ?? 14)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayEma(string $type, array $cfg, array $params): array
    {
        $telegramEma = $type === 'strong_trend_telegram'
            && (bool) ($cfg['require_pullback_to_ma'] ?? false)
            && strtolower((string) ($cfg['pullback_ma_type'] ?? 'ema')) !== 'sma';
        if (! $telegramEma) {
            return $params;
        }
        $params['length'] = max(2, min(100, (int) ($cfg['pullback_ma_length'] ?? $params['length'] ?? 9)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayCci(string $type, array $cfg, array $params): array
    {
        if ($type !== 'cci_trade') {
            return $params;
        }
        $params['length'] = max(2, min(500, (int) ($cfg['cci_length'] ?? $params['length'] ?? 20)));
        $params['level_overbought'] = (float) ($cfg['cci_up'] ?? $params['level_overbought'] ?? 150.0);
        $params['level_oversold'] = (float) ($cfg['cci_down'] ?? $params['level_oversold'] ?? -150.0);
        $params['show_levels'] = true;

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayWilliams(string $type, array $cfg, array $params): array
    {
        if ($type !== 'williams_range_trade') {
            return $params;
        }
        $params['period'] = max(2, min(500, (int) ($cfg['wr_length'] ?? $params['period'] ?? 14)));
        $params['level_overbought'] = (float) ($cfg['wr_up'] ?? $params['level_overbought'] ?? -20.0);
        $params['level_oversold'] = (float) ($cfg['wr_down'] ?? $params['level_oversold'] ?? -80.0);
        $params['show_levels'] = true;

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayRvi(string $type, array $cfg, array $params): array
    {
        if ($type !== 'rvi_trade') {
            return $params;
        }
        $params['period'] = max(2, min(500, (int) ($cfg['rvi_length'] ?? $params['period'] ?? 10)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayEnvelops(string $type, array $cfg, array $params): array
    {
        if ($type !== 'envelop_trend') {
            return $params;
        }
        $params['length'] = max(2, min(500, (int) ($cfg['length'] ?? $params['length'] ?? 21)));
        $params['deviation'] = max(0.01, min(20.0, (float) ($cfg['deviation'] ?? $params['deviation'] ?? 2.0)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayPriceChannel(string $type, array $cfg, array $params): array
    {
        if ($type !== 'price_channel_trade') {
            return $params;
        }
        $params['length_up'] = max(2, min(500, (int) ($cfg['length_up'] ?? $params['length_up'] ?? 21)));
        $params['length_down'] = max(2, min(500, (int) ($cfg['length_down'] ?? $params['length_down'] ?? 21)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayParabolicSar(string $type, array $cfg, array $params): array
    {
        if ($type !== 'parabolic_sar_trade') {
            return $params;
        }
        $params['step'] = max(0.001, min(1.0, (float) ($cfg['step'] ?? $params['step'] ?? 0.02)));
        $params['max_step'] = max((float) $params['step'], min(1.0, (float) ($cfg['max_step'] ?? $params['max_step'] ?? 0.2)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayLinRegFast(string $type, array $cfg, array $params): array
    {
        if ($type !== 'break_linreg_channel') {
            return $params;
        }
        $params['period'] = max(5, min(500, (int) ($cfg['period'] ?? $params['period'] ?? 40)));
        $params['up_deviation'] = max(0.1, min(10.0, (float) ($cfg['up_deviation'] ?? $params['up_deviation'] ?? 1.0)));
        $params['down_deviation'] = max(0.1, min(10.0, (float) ($cfg['down_deviation'] ?? $params['down_deviation'] ?? 1.0)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayMacd(string $type, array $cfg, array $params): array
    {
        if ($type !== 'momentum_macd') {
            return $params;
        }
        $params['length_fast'] = max(2, min(200, (int) ($cfg['macd_fast'] ?? $params['length_fast'] ?? 12)));
        $params['length_slow'] = max((int) $params['length_fast'] + 1, min(300, (int) ($cfg['macd_slow'] ?? $params['length_slow'] ?? 26)));
        $params['length_signal'] = max(2, min(100, (int) ($cfg['macd_signal'] ?? $params['length_signal'] ?? 9)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayMomentum(string $type, array $cfg, array $params): array
    {
        if ($type !== 'momentum_macd') {
            return $params;
        }
        $params['length'] = max(2, min(200, (int) ($cfg['momentum_length'] ?? $params['length'] ?? 5)));

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayStochastic(string $type, array $cfg, array $params): array
    {
        if ($type !== 'sma_stochastic') {
            return $params;
        }
        $params['period1'] = max(1, min(200, (int) ($cfg['stoch_period1'] ?? $params['period1'] ?? 5)));
        $params['period2'] = max(1, min(100, (int) ($cfg['stoch_period2'] ?? $params['period2'] ?? 3)));
        $params['period3'] = max(1, min(100, (int) ($cfg['stoch_period3'] ?? $params['period3'] ?? 3)));
        $params['level_overbought'] = (float) ($cfg['up_line'] ?? $params['level_overbought'] ?? 75.0);
        $params['level_oversold'] = (float) ($cfg['down_line'] ?? $params['level_oversold'] ?? 25.0);
        $params['show_levels'] = true;

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayPivotFloor(string $type, array $cfg, array $params): array
    {
        if ($type !== 'pivot_points') {
            return $params;
        }
        $period = (string) ($cfg['pivot_period'] ?? $params['period'] ?? 'Daily');
        $params['period'] = in_array($period, ['Daily', 'Weekly'], true) ? $period : 'Daily';

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayParabolicBollinger(string $type, array $cfg, array $params): array
    {
        if ($type !== 'parabolic_bollinger') {
            return $params;
        }
        $params['length'] = max(5, min(500, (int) ($cfg['length'] ?? $params['length'] ?? 21)));
        $params['deviation'] = max(0.1, min(10.0, (float) ($cfg['deviation'] ?? $params['deviation'] ?? 2.0)));
        $params['averaging_period'] = max(2, min(200, (int) ($cfg['averaging_period'] ?? $params['averaging_period'] ?? 15)));
        $params['volatility_multi'] = max(0.01, min(5.0, (float) ($cfg['volatility_multi'] ?? $params['volatility_multi'] ?? 0.15)));
        $vPeriod = (string) ($cfg['volatility_period'] ?? $params['volatility_period'] ?? 'Day');
        $params['volatility_period'] = in_array($vPeriod, ['Day', 'Week', 'Month'], true) ? $vPeriod : 'Day';

        return $params;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function overlayParabolicPriceChannel(string $type, array $cfg, array $params): array
    {
        if ($type !== 'parabolic_price_channel') {
            return $params;
        }
        $params['length_up'] = max(5, min(500, (int) ($cfg['length_up'] ?? $params['length_up'] ?? 21)));
        $params['length_down'] = max(5, min(500, (int) ($cfg['length_down'] ?? $params['length_down'] ?? 21)));
        $params['averaging_period'] = max(2, min(200, (int) ($cfg['averaging_period'] ?? $params['averaging_period'] ?? 15)));
        $params['volatility_multi'] = max(0.01, min(5.0, (float) ($cfg['volatility_multi'] ?? $params['volatility_multi'] ?? 0.15)));
        $vPeriod = (string) ($cfg['volatility_period'] ?? $params['volatility_period'] ?? 'Day');
        $params['volatility_period'] = in_array($vPeriod, ['Day', 'Week', 'Month'], true) ? $vPeriod : 'Day';

        return $params;
    }
}
