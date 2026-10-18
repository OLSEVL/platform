<?php

namespace App\Support;

use App\Models\Bot;
use App\Services\Telegram\StrongLevelTelegramLlmButton;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AuthorFlow — оркестратор сценариев D / E / sweep / raid_pb поверх существующих детекторов.
 *
 * Бот #45 (engulfing_liquidity) и SRL не меняются: здесь только собственные ключи af_*
 * и прокси-опции для EngulfingDetector / SweepRaidLiquidityDetector.
 */
final class AuthorFlowConfig
{
    public const BOT_TYPE = 'author_flow';

    public const VERSION = 'author_flow_v1';

    public const TITLE = 'Author Flow';

    public const ALLOWED_SCAN_INTERVAL_SEC = [5, 15, 30, 60];

    public const DEFAULT_SCAN_INTERVAL_SEC = 5;

    public const ALLOWED_SCAN_WORKERS = [1, 2, 3, 4, 6, 8];

    public const DEFAULT_SCAN_WORKERS = 6;

    public const DEFAULT_TELEGRAM_CHART_CANDLES = 50;

    public const MIN_TELEGRAM_CHART_CANDLES = 20;

    public const MAX_TELEGRAM_CHART_CANDLES = 80;

    public static function is(string $botType): bool
    {
        return $botType === self::BOT_TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'count' => 500,
            // S15 (dashboard TF id 4015) — как у автора на OTC.
            'timeframe' => \App\Services\CandleService::TF_S15,
            'expiry_sec' => 15,
            'min_confidence' => 0.55,
            'min_payout' => 80.0,
            'market_scope' => BotInstrumentMarketScope::OTC,
            'stale_after_sec' => 30,
            'scan_interval_sec' => self::DEFAULT_SCAN_INTERVAL_SEC,
            'scan_workers' => self::DEFAULT_SCAN_WORKERS,
            'continuous_scan' => true,
            // Сценарии вкл/выкл.
            'af_enable_breakout_e' => true,
            'af_enable_engulf_d' => true,
            'af_enable_raid_pb' => true,
            'af_enable_sweep' => true,
            'af_enable_skop_score' => false,
            // Контекст.
            'af_require_structure' => true,
            'af_allow_range_entries' => false,
            'af_structure_pivot' => 2,
            'af_require_open_liquidity' => false,
            'af_liquidity_lookback' => 40,
            // D → EngulfingDetector (не трогает bots.config #45).
            'af_eng_allow_classic' => true,
            'af_eng_allow_same_color' => true,
            'af_eng_require_level' => true,
            'af_eng_level_lookback' => 40,
            'af_eng_level_touch_pct' => 0.15,
            'af_eng_reaction_max_bars' => 3,
            'af_eng_skip_if_left' => true,
            'af_eng_skip_if_breakout_hold' => true,
            // E / SRL.
            'af_breakout_hold_closes' => 2,
            'af_srl_pivot_length' => 2,
            'af_gap_filter_enabled' => true,
            'af_gap_lookback' => 40,
            'af_gap_max_ratio' => 0.35,
            'af_gap_size_mult' => 0.5,
            // Telegram (как у #45).
            'telegram_enabled' => false,
            'telegram_notify_result' => true,
            'telegram_notify_on' => 'signal_trade',
            'telegram_send_chart' => true,
            'telegram_chart_candles' => self::DEFAULT_TELEGRAM_CHART_CANDLES,
            'telegram_show_nearest_level' => true,
            'telegram_level_lookback' => 0,
            'telegram_auto_subscribe' => true,
            'version' => self::VERSION,
        ];
    }

    /** @param  array<string, mixed>  $config */
    public static function telegramChartCandles(array $config = []): int
    {
        $raw = (int) ($config['telegram_chart_candles'] ?? self::DEFAULT_TELEGRAM_CHART_CANDLES);

        return max(self::MIN_TELEGRAM_CHART_CANDLES, min(self::MAX_TELEGRAM_CHART_CANDLES, $raw));
    }

    /** @param  array<string, mixed>  $config */
    public static function continuousScanEnabled(array $config = []): bool
    {
        return self::flag($config, 'continuous_scan', true);
    }

    /** @param  array<string, mixed>  $config */
    public static function scanWorkers(array $config = []): int
    {
        $raw = (int) ($config['scan_workers'] ?? self::DEFAULT_SCAN_WORKERS);

        return in_array($raw, self::ALLOWED_SCAN_WORKERS, true) ? $raw : self::DEFAULT_SCAN_WORKERS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function flag(array $config, string $key, bool $default = true): bool
    {
        if (! array_key_exists($key, $config) || $config[$key] === null || $config[$key] === '') {
            return $default;
        }
        $value = $config[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }
        $s = strtolower(trim((string) $value));
        if (in_array($s, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        if (in_array($s, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        return $default;
    }

    /**
     * Опции EngulfingDetector из af_eng_* (изолированы от eng_* бота #45).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function engulfDetectorOptions(array $config): array
    {
        return [
            'allow_classic' => self::flag($config, 'af_eng_allow_classic', true),
            'allow_same_color' => self::flag($config, 'af_eng_allow_same_color', true),
            'require_level' => self::flag($config, 'af_eng_require_level', true),
            'level_lookback' => max(10, min(200, (int) ($config['af_eng_level_lookback'] ?? 40))),
            'level_touch_pct' => max(0.01, min(2.0, (float) ($config['af_eng_level_touch_pct'] ?? 0.15))),
            'reaction_max_bars' => max(1, min(8, (int) ($config['af_eng_reaction_max_bars'] ?? 3))),
            'skip_if_left' => self::flag($config, 'af_eng_skip_if_left', true),
            'skip_if_breakout_hold' => self::flag($config, 'af_eng_skip_if_breakout_hold', true),
            'breakout_hold_closes' => max(1, min(4, (int) ($config['af_breakout_hold_closes'] ?? 2))),
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public static function buildFromRequest(Request $request, array $existing = []): array
    {
        $extra = $request->validate([
            'count' => ['required', 'integer', 'min:30', 'max:5000'],
            'expiry_sec' => ['required', 'integer', 'min:15', 'max:900'],
            'timeframe' => ['required', 'integer', Rule::in([
                \App\Services\CandleService::TF_S5,
                \App\Services\CandleService::TF_S15,
                \App\Services\CandleService::TF_S30,
                1,
            ])],
            'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
            'market_scope' => BotInstrumentMarketScope::validationRules(),
            'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            'scan_interval_sec' => ['required', 'integer', Rule::in(self::ALLOWED_SCAN_INTERVAL_SEC)],
            'scan_workers' => ['required', 'integer', Rule::in(self::ALLOWED_SCAN_WORKERS)],
            'af_structure_pivot' => ['required', 'integer', 'min:1', 'max:10'],
            'af_liquidity_lookback' => ['required', 'integer', 'min:10', 'max:200'],
            'af_eng_level_lookback' => ['required', 'integer', 'min:10', 'max:200'],
            'af_eng_level_touch_pct' => ['required', 'numeric', 'min:0.01', 'max:2'],
            'af_eng_reaction_max_bars' => ['required', 'integer', 'min:1', 'max:8'],
            'af_breakout_hold_closes' => ['required', 'integer', 'min:1', 'max:4'],
            'af_srl_pivot_length' => ['required', 'integer', 'min:1', 'max:10'],
            'af_gap_lookback' => ['required', 'integer', 'min:5', 'max:500'],
            'af_gap_max_ratio' => ['required', 'numeric', 'min:0.05', 'max:1'],
            'af_gap_size_mult' => ['required', 'numeric', 'min:0.1', 'max:10'],
            'telegram_notify_on' => ['required', 'string', Rule::in(['signal_trade', 'off'])],
            'telegram_send_chart' => ['required', 'string', Rule::in(['1', '0'])],
            'telegram_chart_candles' => [
                'required',
                'integer',
                'min:'.self::MIN_TELEGRAM_CHART_CANDLES,
                'max:'.self::MAX_TELEGRAM_CHART_CANDLES,
            ],
            'telegram_level_lookback' => ['nullable', 'integer', 'min:0', 'max:200'],
            'telegram_bot_token' => ['nullable', 'string', 'max:128'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
            'telegram_analyst_bot_id' => ['nullable', 'integer', 'min:1'],
            'telegram_analyst_llm_question' => ['nullable', 'string', 'max:500'],
        ] + TelegramBotConfigFields::validationRules());

        $existingToken = trim((string) ($existing['telegram_bot_token'] ?? ''));
        $tokenInput = trim((string) ($extra['telegram_bot_token'] ?? ''));

        $config = array_merge($existing, [
            'count' => (int) $extra['count'],
            'expiry_sec' => (int) $extra['expiry_sec'],
            'timeframe' => (int) $extra['timeframe'],
            'min_confidence' => (float) $extra['min_confidence'],
            'min_payout' => (float) $extra['min_payout'],
            'market_scope' => BotInstrumentMarketScope::normalize($extra['market_scope'] ?? BotInstrumentMarketScope::OTC),
            'stale_after_sec' => (int) $extra['stale_after_sec'],
            'scan_interval_sec' => (int) $extra['scan_interval_sec'],
            'scan_workers' => (int) $extra['scan_workers'],
            'continuous_scan' => $request->boolean('continuous_scan'),
            'af_enable_breakout_e' => $request->boolean('af_enable_breakout_e'),
            'af_enable_engulf_d' => $request->boolean('af_enable_engulf_d'),
            'af_enable_raid_pb' => $request->boolean('af_enable_raid_pb'),
            'af_enable_sweep' => $request->boolean('af_enable_sweep'),
            'af_enable_skop_score' => $request->boolean('af_enable_skop_score'),
            'af_require_structure' => $request->boolean('af_require_structure'),
            'af_allow_range_entries' => $request->boolean('af_allow_range_entries'),
            'af_structure_pivot' => (int) $extra['af_structure_pivot'],
            'af_require_open_liquidity' => $request->boolean('af_require_open_liquidity'),
            'af_liquidity_lookback' => (int) $extra['af_liquidity_lookback'],
            'af_eng_allow_classic' => $request->boolean('af_eng_allow_classic'),
            'af_eng_allow_same_color' => $request->boolean('af_eng_allow_same_color'),
            'af_eng_require_level' => $request->boolean('af_eng_require_level'),
            'af_eng_level_lookback' => (int) $extra['af_eng_level_lookback'],
            'af_eng_level_touch_pct' => (float) $extra['af_eng_level_touch_pct'],
            'af_eng_reaction_max_bars' => (int) $extra['af_eng_reaction_max_bars'],
            'af_eng_skip_if_left' => $request->boolean('af_eng_skip_if_left'),
            'af_eng_skip_if_breakout_hold' => $request->boolean('af_eng_skip_if_breakout_hold'),
            'af_breakout_hold_closes' => (int) $extra['af_breakout_hold_closes'],
            'af_srl_pivot_length' => (int) $extra['af_srl_pivot_length'],
            'af_gap_filter_enabled' => $request->boolean('af_gap_filter_enabled'),
            'af_gap_lookback' => (int) $extra['af_gap_lookback'],
            'af_gap_max_ratio' => (float) $extra['af_gap_max_ratio'],
            'af_gap_size_mult' => (float) $extra['af_gap_size_mult'],
            'telegram_enabled' => $request->boolean('telegram_enabled'),
            'telegram_notify_result' => $request->boolean('telegram_notify_result'),
            'telegram_notify_on' => (string) $extra['telegram_notify_on'],
            'telegram_send_chart' => ($extra['telegram_send_chart'] ?? '1') === '1',
            'telegram_chart_candles' => self::telegramChartCandles([
                'telegram_chart_candles' => $extra['telegram_chart_candles'] ?? self::DEFAULT_TELEGRAM_CHART_CANDLES,
            ]),
            'telegram_show_nearest_level' => $request->boolean('telegram_show_nearest_level'),
            'telegram_level_lookback' => max(0, min(200, (int) ($extra['telegram_level_lookback'] ?? 0))),
            'telegram_bot_token' => $tokenInput !== '' ? $tokenInput : $existingToken,
            'telegram_chat_id' => trim((string) ($extra['telegram_chat_id'] ?? '')),
            'telegram_analyst_llm_enabled' => $request->boolean('telegram_analyst_llm_enabled'),
            'telegram_analyst_bot_id' => ! empty($extra['telegram_analyst_bot_id'])
                ? (int) $extra['telegram_analyst_bot_id']
                : null,
            'telegram_analyst_llm_question' => StrongLevelTelegramLlmButton::llmQuestionFromConfig([
                'telegram_analyst_llm_question' => $extra['telegram_analyst_llm_question'] ?? null,
            ]),
            'version' => self::VERSION,
        ]);

        return TelegramBotConfigFields::mergeFromRequest($request, $config, $existing);
    }

    /**
     * @return array<string, mixed>
     */
    public static function mergeDefaults(Bot $bot): array
    {
        $existing = is_array($bot->config) ? $bot->config : [];

        return array_replace(self::defaultConfig(), $existing);
    }
}
