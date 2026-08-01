<?php

namespace App\Support;

use App\Models\Bot;
use App\Services\Bots\Strategies\BreakLinRegChannelStrategy;
use App\Services\Bots\Strategies\EnvelopTrendStrategy;
use App\Services\Bots\Strategies\MomentumMacdStrategy;
use App\Services\Bots\Strategies\ParabolicBollingerStrategy;
use App\Services\Bots\Strategies\ParabolicPriceChannelStrategy;
use App\Services\Bots\Strategies\ParabolicSarTradeStrategy;
use App\Services\Bots\Strategies\PivotPointsStrategy;
use App\Services\Bots\Strategies\PriceChannelTradeStrategy;
use App\Services\Bots\Strategies\SmaStochasticStrategy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Wave A OsEngine→CALL/PUT боты: валидация настроек и дефолты config.
 */
final class OsEngineWaveABotConfig
{
    /** @var list<string> */
    public const TYPES = [
        'envelop_trend',
        'price_channel_trade',
        'parabolic_sar_trade',
        'break_linreg_channel',
        'pivot_points',
        'sma_stochastic',
        'momentum_macd',
        'parabolic_bollinger',
        'parabolic_price_channel',
    ];

    public static function isWaveA(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @return array{title: string, blurb: string, version: string}
     */
    public static function meta(string $type): array
    {
        return match ($type) {
            'envelop_trend' => [
                'title' => 'Envelop Trend',
                'blurb' => 'Пробой верхней/нижней Envelops (high≥up → CALL, low≤down → PUT).',
                'version' => 'envelop_trend_v1',
            ],
            'price_channel_trade' => [
                'title' => 'Price Channel Trade',
                'blurb' => 'Пробой PriceChannel по каналу предыдущего бара (Count−2).',
                'version' => 'price_channel_trade_v1',
            ],
            'parabolic_sar_trade' => [
                'title' => 'Parabolic SAR Trade',
                'blurb' => 'Цена относительно SAR; по умолчанию только кросс стороны.',
                'version' => 'parabolic_sar_trade_v1',
            ],
            'break_linreg_channel' => [
                'title' => 'Break LR Channel',
                'blurb' => 'Пробой LinReg Channel Fast; SMA-фильтры опциональны.',
                'version' => 'break_linreg_channel_v1',
            ],
            'pivot_points' => [
                'title' => 'Pivot Points',
                'blurb' => 'Кросс R1 вверх → CALL, кросс S1 вниз → PUT (PivotFloor Daily/Weekly).',
                'version' => 'pivot_points_v1',
            ],
            'sma_stochastic' => [
                'title' => 'SMA Stochastic',
                'blurb' => 'SMA ± step_pct + кросс %K через уровни Stochastic.',
                'version' => 'sma_stochastic_v1',
            ],
            'momentum_macd' => [
                'title' => 'Momentum MACD',
                'blurb' => 'MACD vs Signal + Momentum выше/ниже 100.',
                'version' => 'momentum_macd_v1',
            ],
            'parabolic_bollinger' => [
                'title' => 'Parabolic Bollinger',
                'blurb' => 'Пробой BB при SAR внутри канала (ParabolicBollinger).',
                'version' => 'parabolic_bollinger_v1',
            ],
            'parabolic_price_channel' => [
                'title' => 'Parabolic Price Channel',
                'blurb' => 'Пробой PC при SAR внутри канала (ParabolicPriceChannel).',
                'version' => 'parabolic_price_channel_v1',
            ],
            default => ['title' => $type, 'blurb' => '', 'version' => $type.'_v1'],
        };
    }

    /** @return array<string, mixed> дефолтный config для bootstrap */
    public static function defaultConfig(string $type): array
    {
        $shared = [
            'count' => $type === 'pivot_points' ? 2000 : 200,
            'expiry_sec' => 180,
            'min_confidence' => 0.56,
            'min_payout' => 75.0,
            'stale_after_sec' => 60,
            'telegram_enabled' => false,
            'telegram_notify_on' => 'signal_trade',
            'telegram_alert_format' => 'brief',
            'telegram_send_chart' => true,
            'telegram_send_chart_m5' => true,
            'version' => self::meta($type)['version'],
        ];

        $specific = match ($type) {
            'envelop_trend' => [
                'length' => EnvelopTrendStrategy::DEFAULT_LENGTH,
                'deviation' => EnvelopTrendStrategy::DEFAULT_DEVIATION,
            ],
            'price_channel_trade' => [
                'length_up' => PriceChannelTradeStrategy::DEFAULT_LENGTH_UP,
                'length_down' => PriceChannelTradeStrategy::DEFAULT_LENGTH_DOWN,
            ],
            'parabolic_sar_trade' => [
                'step' => ParabolicSarTradeStrategy::DEFAULT_STEP,
                'max_step' => ParabolicSarTradeStrategy::DEFAULT_MAX_STEP,
                'require_cross' => true,
            ],
            'break_linreg_channel' => [
                'period' => BreakLinRegChannelStrategy::DEFAULT_PERIOD,
                'up_deviation' => BreakLinRegChannelStrategy::DEFAULT_UP_DEVIATION,
                'down_deviation' => BreakLinRegChannelStrategy::DEFAULT_DOWN_DEVIATION,
                'sma_length' => BreakLinRegChannelStrategy::DEFAULT_SMA_LENGTH,
                'sma_filter_position' => false,
                'sma_filter_slope' => false,
            ],
            'pivot_points' => [
                'pivot_period' => PivotPointsStrategy::DEFAULT_PERIOD,
            ],
            'sma_stochastic' => [
                'sma_length' => SmaStochasticStrategy::DEFAULT_SMA_LENGTH,
                'stoch_period1' => SmaStochasticStrategy::DEFAULT_STOCH_PERIOD1,
                'stoch_period2' => SmaStochasticStrategy::DEFAULT_STOCH_PERIOD2,
                'stoch_period3' => SmaStochasticStrategy::DEFAULT_STOCH_PERIOD3,
                'up_line' => SmaStochasticStrategy::DEFAULT_UP_LINE,
                'down_line' => SmaStochasticStrategy::DEFAULT_DOWN_LINE,
                'step_pct' => SmaStochasticStrategy::DEFAULT_STEP_PCT,
            ],
            'momentum_macd' => [
                'momentum_length' => MomentumMacdStrategy::DEFAULT_MOMENTUM_LENGTH,
                'macd_fast' => MomentumMacdStrategy::DEFAULT_MACD_FAST,
                'macd_slow' => MomentumMacdStrategy::DEFAULT_MACD_SLOW,
                'macd_signal' => MomentumMacdStrategy::DEFAULT_MACD_SIGNAL,
            ],
            'parabolic_bollinger' => [
                'length' => ParabolicBollingerStrategy::DEFAULT_LENGTH,
                'deviation' => ParabolicBollingerStrategy::DEFAULT_DEVIATION,
                'averaging_period' => ParabolicBollingerStrategy::DEFAULT_AVERAGING_PERIOD,
                'volatility_multi' => ParabolicBollingerStrategy::DEFAULT_VOLATILITY_MULTI,
                'volatility_period' => 'Day',
            ],
            'parabolic_price_channel' => [
                'length_up' => ParabolicPriceChannelStrategy::DEFAULT_LENGTH_UP,
                'length_down' => ParabolicPriceChannelStrategy::DEFAULT_LENGTH_DOWN,
                'averaging_period' => ParabolicPriceChannelStrategy::DEFAULT_AVERAGING_PERIOD,
                'volatility_multi' => ParabolicPriceChannelStrategy::DEFAULT_VOLATILITY_MULTI,
                'volatility_period' => 'Day',
            ],
            default => [],
        };

        return array_merge($shared, $specific);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request, Bot $bot): array
    {
        $type = $bot->type;
        $specificRules = match ($type) {
            'envelop_trend' => [
                'length' => ['required', 'integer', 'min:2', 'max:500'],
                'deviation' => ['required', 'numeric', 'min:0.01', 'max:20'],
            ],
            'price_channel_trade' => [
                'length_up' => ['required', 'integer', 'min:2', 'max:500'],
                'length_down' => ['required', 'integer', 'min:2', 'max:500'],
            ],
            'parabolic_sar_trade' => [
                'step' => ['required', 'numeric', 'min:0.001', 'max:1'],
                'max_step' => ['required', 'numeric', 'min:0.001', 'max:1'],
                'require_cross' => ['sometimes', 'boolean'],
            ],
            'break_linreg_channel' => [
                'period' => ['required', 'integer', 'min:5', 'max:500'],
                'up_deviation' => ['required', 'numeric', 'min:0.1', 'max:10'],
                'down_deviation' => ['required', 'numeric', 'min:0.1', 'max:10'],
                'sma_length' => ['required', 'integer', 'min:2', 'max:500'],
                'sma_filter_position' => ['sometimes', 'boolean'],
                'sma_filter_slope' => ['sometimes', 'boolean'],
            ],
            'pivot_points' => [
                'pivot_period' => ['required', 'string', Rule::in(['Daily', 'Weekly'])],
            ],
            'sma_stochastic' => [
                'sma_length' => ['required', 'integer', 'min:2', 'max:500'],
                'stoch_period1' => ['required', 'integer', 'min:1', 'max:200'],
                'stoch_period2' => ['required', 'integer', 'min:1', 'max:100'],
                'stoch_period3' => ['required', 'integer', 'min:1', 'max:100'],
                'up_line' => ['required', 'numeric', 'min:50', 'max:100'],
                'down_line' => ['required', 'numeric', 'min:0', 'max:50'],
                'step_pct' => ['required', 'numeric', 'min:0', 'max:0.05'],
            ],
            'momentum_macd' => [
                'momentum_length' => ['required', 'integer', 'min:2', 'max:200'],
                'macd_fast' => ['required', 'integer', 'min:2', 'max:200'],
                'macd_slow' => ['required', 'integer', 'min:3', 'max:300'],
                'macd_signal' => ['required', 'integer', 'min:2', 'max:100'],
            ],
            'parabolic_bollinger' => [
                'length' => ['required', 'integer', 'min:5', 'max:500'],
                'deviation' => ['required', 'numeric', 'min:0.1', 'max:10'],
                'averaging_period' => ['required', 'integer', 'min:2', 'max:200'],
                'volatility_multi' => ['required', 'numeric', 'min:0.01', 'max:5'],
                'volatility_period' => ['required', 'string', Rule::in(['Day', 'Week', 'Month'])],
            ],
            'parabolic_price_channel' => [
                'length_up' => ['required', 'integer', 'min:5', 'max:500'],
                'length_down' => ['required', 'integer', 'min:5', 'max:500'],
                'averaging_period' => ['required', 'integer', 'min:2', 'max:200'],
                'volatility_multi' => ['required', 'numeric', 'min:0.01', 'max:5'],
                'volatility_period' => ['required', 'string', Rule::in(['Day', 'Week', 'Month'])],
            ],
            default => [],
        };

        $countMin = $type === 'pivot_points' ? 200 : 40;
        $extra = $request->validate(array_merge([
            'count' => ['required', 'integer', 'min:'.$countMin, 'max:5000'],
            'expiry_sec' => ['required', 'integer', 'min:60', 'max:900'],
            'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
            'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            'telegram_enabled' => ['sometimes', 'boolean'],
            'telegram_bot_token' => ['nullable', 'string', 'max:128'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
            'telegram_notify_on' => ['required', 'string', Rule::in(['signal_trade', 'off'])],
            'telegram_alert_format' => ['required', 'string', Rule::in(['brief', 'detailed', 'card'])],
            'telegram_send_chart' => ['required', 'string', Rule::in(['1', '0'])],
            'telegram_send_chart_m5' => ['required', 'string', Rule::in(['1', '0'])],
            'telegram_analyst_llm_enabled' => ['sometimes', 'boolean'],
            'telegram_analyst_bot_id' => ['nullable', 'integer', 'min:1'],
            'telegram_analyst_llm_question' => ['nullable', 'string', 'max:500'],
        ], $specificRules));

        $existingToken = trim((string) (($bot->config ?? [])['telegram_bot_token'] ?? ''));
        $tokenInput = trim((string) ($extra['telegram_bot_token'] ?? ''));

        $config = array_merge($bot->config ?? [], [
            'count' => (int) $extra['count'],
            'expiry_sec' => (int) $extra['expiry_sec'],
            'min_confidence' => (float) $extra['min_confidence'],
            'min_payout' => (float) $extra['min_payout'],
            'stale_after_sec' => (int) $extra['stale_after_sec'],
            'telegram_enabled' => $request->boolean('telegram_enabled'),
            'telegram_bot_token' => $tokenInput !== '' ? $tokenInput : $existingToken,
            'telegram_chat_id' => trim((string) ($extra['telegram_chat_id'] ?? '')),
            'telegram_notify_on' => (string) $extra['telegram_notify_on'],
            'telegram_alert_format' => (string) $extra['telegram_alert_format'],
            'telegram_send_chart' => $extra['telegram_send_chart'] === '1',
            'telegram_send_chart_m5' => $extra['telegram_send_chart_m5'] === '1',
            'telegram_analyst_llm_enabled' => $request->boolean('telegram_analyst_llm_enabled'),
            'telegram_analyst_bot_id' => isset($extra['telegram_analyst_bot_id']) ? (int) $extra['telegram_analyst_bot_id'] : null,
            'telegram_analyst_llm_question' => trim((string) ($extra['telegram_analyst_llm_question'] ?? '')),
            'version' => self::meta($type)['version'],
        ]);

        // Стратегия-специфичные поля.
        foreach (array_keys($specificRules) as $key) {
            if (in_array($key, ['require_cross', 'sma_filter_position', 'sma_filter_slope'], true)) {
                $config[$key] = $request->boolean($key);

                continue;
            }
            if (! array_key_exists($key, $extra)) {
                continue;
            }
            $val = $extra[$key];
            $config[$key] = is_numeric($val) && ! is_string($val)
                ? $val
                : (is_string($val) && is_numeric($val) && ! in_array($key, ['pivot_period', 'volatility_period'], true)
                    ? (str_contains((string) $val, '.') ? (float) $val : (int) $val)
                    : $val);
            // Числа из HTML приходят строками — приводим явно для int/float полей.
            if (in_array($key, ['length', 'length_up', 'length_down', 'period', 'sma_length', 'stoch_period1', 'stoch_period2', 'stoch_period3', 'momentum_length', 'macd_fast', 'macd_slow', 'macd_signal', 'averaging_period'], true)) {
                $config[$key] = (int) $val;
            } elseif (in_array($key, ['deviation', 'step', 'max_step', 'up_deviation', 'down_deviation', 'up_line', 'down_line', 'step_pct', 'volatility_multi'], true)) {
                $config[$key] = (float) $val;
            } elseif (in_array($key, ['pivot_period', 'volatility_period'], true)) {
                $config[$key] = (string) $val;
            }
        }

        return $config;
    }
}
