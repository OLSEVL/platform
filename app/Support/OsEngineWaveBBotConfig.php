<?php

namespace App\Support;

use App\Models\Bot;
use App\Services\Bots\Strategies\CustomCandlesImpulseStrategy;
use App\Services\Bots\Strategies\ThreeSoldierVolAdaptiveStrategy;
use App\Services\Bots\Strategies\VolAdaptiveCandlesStrategy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Wave B OsEngine→CALL/PUT: импульсные паттерны под экспирацию ≤10m.
 * VolatilityAdaptiveCandles / ThreeSoldierVolatilityAdaptive / CustomCandlesImpulse.
 */
final class OsEngineWaveBBotConfig
{
    /** @var list<string> */
    public const TYPES = [
        'vol_adaptive_candles',
        'three_soldier_vol_adaptive',
        'custom_candles_impulse',
    ];

    public static function isWaveB(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @return array{title: string, blurb: string, version: string}
     */
    public static function meta(string $type): array
    {
        return match ($type) {
            'vol_adaptive_candles' => [
                'title' => 'Vol Adaptive Candles',
                'blurb' => 'Сильная сигнальная свеча (тело ≥ порог от дневной волы) → CALL/PUT.',
                'version' => 'vol_adaptive_candles_v1',
            ],
            'three_soldier_vol_adaptive' => [
                'title' => 'Three Soldier Vol Adaptive',
                'blurb' => 'Три солдата с порогами высоты от средней дневной волы.',
                'version' => 'three_soldier_vol_adaptive_v1',
            ],
            'custom_candles_impulse' => [
                'title' => 'Custom Candles Impulse',
                'blurb' => 'N свечей подряд в одну сторону за ограниченный TimeSpan.',
                'version' => 'custom_candles_impulse_v1',
            ],
            default => ['title' => $type, 'blurb' => '', 'version' => $type.'_v1'],
        };
    }

    /** @return array<string, mixed> */
    public static function defaultConfig(string $type): array
    {
        // Vola-adaptive нужны дни M1-истории (~1440 баров/день).
        $needsLongHistory = in_array($type, ['vol_adaptive_candles', 'three_soldier_vol_adaptive'], true);

        $shared = [
            'count' => $needsLongHistory ? 2000 : 200,
            'expiry_sec' => 180,
            'min_confidence' => 0.0,
            'min_payout' => 75.0,
            'stale_after_sec' => 60,
            'scan_interval_sec' => \App\Support\BotScanCoordinator::DEFAULT_SCAN_INTERVAL_SEC,
            'telegram_enabled' => false,
            'telegram_auto_subscribe' => true,
            'telegram_notify_result' => true,
            'telegram_notify_on' => 'signal_trade',
            'telegram_alert_format' => 'brief',
            'telegram_send_chart' => true,
            'telegram_send_chart_m5' => true,
            'version' => self::meta($type)['version'],
        ];

        $specific = match ($type) {
            'vol_adaptive_candles' => [
                'height_signal_candle' => VolAdaptiveCandlesStrategy::DEFAULT_HEIGHT_SIGNAL_CANDLE,
                'days_volatility_adaptive' => VolAdaptiveCandlesStrategy::DEFAULT_DAYS_VOLA,
                'height_signal_candle_vola_percent' => VolAdaptiveCandlesStrategy::DEFAULT_HEIGHT_SIGNAL_VOLA_PERCENT,
            ],
            'three_soldier_vol_adaptive' => [
                'height_soldiers' => ThreeSoldierVolAdaptiveStrategy::DEFAULT_HEIGHT_SOLDIERS,
                'min_height_one_soldier' => ThreeSoldierVolAdaptiveStrategy::DEFAULT_MIN_HEIGHT_ONE_SOLDIER,
                'days_volatility_adaptive' => ThreeSoldierVolAdaptiveStrategy::DEFAULT_DAYS_VOLA,
                'height_soldiers_vola_percent' => ThreeSoldierVolAdaptiveStrategy::DEFAULT_HEIGHT_SOLDIERS_VOLA_PERCENT,
                'min_height_one_soldier_vola_percent' => ThreeSoldierVolAdaptiveStrategy::DEFAULT_MIN_ONE_VOLA_PERCENT,
            ],
            'custom_candles_impulse' => [
                'candles_count_to_entry' => CustomCandlesImpulseStrategy::DEFAULT_CANDLES_COUNT_TO_ENTRY,
                'seconds_time_on_candles' => CustomCandlesImpulseStrategy::DEFAULT_SECONDS_TIME_ON_CANDLES,
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
            'vol_adaptive_candles' => [
                'height_signal_candle' => ['required', 'numeric', 'min:0', 'max:20'],
                'days_volatility_adaptive' => ['required', 'integer', 'min:0', 'max:30'],
                'height_signal_candle_vola_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            ],
            'three_soldier_vol_adaptive' => [
                'height_soldiers' => ['required', 'numeric', 'min:0', 'max:20'],
                'min_height_one_soldier' => ['required', 'numeric', 'min:0', 'max:20'],
                'days_volatility_adaptive' => ['required', 'integer', 'min:0', 'max:30'],
                'height_soldiers_vola_percent' => ['required', 'numeric', 'min:0', 'max:100'],
                'min_height_one_soldier_vola_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            ],
            'custom_candles_impulse' => [
                'candles_count_to_entry' => ['required', 'integer', 'min:1', 'max:20'],
                'seconds_time_on_candles' => ['required', 'integer', 'min:30', 'max:3600'],
            ],
            default => [],
        };

        $countMin = in_array($type, ['vol_adaptive_candles', 'three_soldier_vol_adaptive'], true) ? 200 : 40;
        $extra = $request->validate(array_merge([
            'count' => ['required', 'integer', 'min:'.$countMin, 'max:5000'],
            'expiry_sec' => ['required', 'integer', 'min:60', 'max:900'],
            'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
            'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            'telegram_enabled' => ['sometimes', 'boolean'],
            'telegram_notify_result' => ['sometimes', 'boolean'],
            'telegram_bot_token' => ['nullable', 'string', 'max:128'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
            'telegram_auto_subscribe' => ['sometimes', 'boolean'],
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
            'telegram_notify_result' => $request->boolean('telegram_notify_result'),
            'telegram_bot_token' => $tokenInput !== '' ? $tokenInput : $existingToken,
            'telegram_chat_id' => trim((string) ($extra['telegram_chat_id'] ?? '')),
            'telegram_auto_subscribe' => $request->boolean('telegram_auto_subscribe'),
            'telegram_notify_on' => (string) $extra['telegram_notify_on'],
            'telegram_alert_format' => (string) $extra['telegram_alert_format'],
            'telegram_send_chart' => $extra['telegram_send_chart'] === '1',
            'telegram_send_chart_m5' => $extra['telegram_send_chart_m5'] === '1',
            'telegram_analyst_llm_enabled' => $request->boolean('telegram_analyst_llm_enabled'),
            'telegram_analyst_bot_id' => isset($extra['telegram_analyst_bot_id']) ? (int) $extra['telegram_analyst_bot_id'] : null,
            'telegram_analyst_llm_question' => trim((string) ($extra['telegram_analyst_llm_question'] ?? '')),
            'version' => self::meta($type)['version'],
        ]);

        $intKeys = ['days_volatility_adaptive', 'candles_count_to_entry', 'seconds_time_on_candles'];
        $floatKeys = [
            'height_signal_candle',
            'height_signal_candle_vola_percent',
            'height_soldiers',
            'min_height_one_soldier',
            'height_soldiers_vola_percent',
            'min_height_one_soldier_vola_percent',
        ];

        foreach (array_keys($specificRules) as $key) {
            if (! array_key_exists($key, $extra)) {
                continue;
            }
            $val = $extra[$key];
            if (in_array($key, $intKeys, true)) {
                $config[$key] = (int) $val;
            } elseif (in_array($key, $floatKeys, true)) {
                $config[$key] = (float) $val;
            } else {
                $config[$key] = $val;
            }
        }

        return $config;
    }
}
