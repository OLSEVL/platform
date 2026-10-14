<?php

namespace App\Support;

use App\Models\Bot;
use App\Services\Telegram\StrongLevelTelegramLlmButton;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Engulfing Liquidity (#45) — 3-шаговое поглощение + live-инфра как у Impulse LVL (#47).
 *
 * Новое со стримов 28–29.09.2026 (Ihj / Vun):
 * - фильтр уровня (FH/FL рядом с шагом 1);
 * - окно реакции 1…N баров;
 * - skip «уход» без реакции;
 * - skip D при закрепе пробоя (ветка E).
 */
final class EngulfingLiquidityConfig
{
    public const BOT_TYPE = 'engulfing_liquidity';

    public const VERSION = 'engulfing_liquidity_v3';

    public const TITLE = 'Engulfing Liquidity';

    public const ALLOWED_SCAN_INTERVAL_SEC = [5, 15, 30, 60];

    public const DEFAULT_SCAN_INTERVAL_SEC = 5;

    public const ALLOWED_SCAN_WORKERS = [1, 2, 3, 4, 6, 8];

    public const DEFAULT_SCAN_WORKERS = 6;

    /** Сколько M1-свечей на PNG в Telegram (как TELEGRAM_CHART_CANDLES у #47 / updateBotM1). */
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
            'timeframe' => 1,
            'expiry_sec' => 60,
            'min_confidence' => 0.0,
            'min_payout' => 80.0,
            // all | otc | non_otc — суффикс _otc у кода пары.
            'market_scope' => BotInstrumentMarketScope::ALL,
            'stale_after_sec' => 60,
            'scan_interval_sec' => self::DEFAULT_SCAN_INTERVAL_SEC,
            'scan_workers' => self::DEFAULT_SCAN_WORKERS,
            // Live daemon как у #47: poll + workers вместо batch bots:scan.
            'continuous_scan' => true,
            'eng_allow_classic' => true,
            'eng_allow_same_color' => true,
            // Со стримов: engulf у фрактального уровня.
            'eng_require_level' => true,
            'eng_level_lookback' => 40,
            'eng_level_touch_pct' => 0.15,
            // Реакция не только на соседней свече (1…N).
            'eng_reaction_max_bars' => 3,
            // «Уход / сразу вышли» — пропуск.
            'eng_skip_if_left' => true,
            // После engulf close закрепился за уровнем → не D (смотри E).
            'eng_skip_if_breakout_hold' => true,
            'eng_breakout_hold_closes' => 2,
            'eng_gap_filter_enabled' => false,
            'eng_gap_lookback' => 40,
            'eng_gap_max_ratio' => 0.35,
            'eng_gap_size_mult' => 0.5,
            'eng_skip_martingale_if_session_green' => false,
            'telegram_enabled' => false,
            'telegram_notify_result' => true,
            'telegram_notify_on' => 'signal_trade',
            'telegram_send_chart' => true,
            // PNG как у Impulse LVL (#47): N последних свечей + сетка + рамка хвоста.
            'telegram_chart_candles' => self::DEFAULT_TELEGRAM_CHART_CANDLES,
            // Линия ближайшего FH/FL на PNG — видеть, сработает ли отбой.
            'telegram_show_nearest_level' => true,
            // Lookback фракталов для линии на графике (0 = брать eng_level_lookback).
            'telegram_level_lookback' => 0,
            'telegram_auto_subscribe' => true,
            'version' => self::VERSION,
        ];
    }

    /**
     * Число свечей на Telegram PNG (20…80), дефолт как у #47.
     *
     * @param  array<string, mixed>  $config
     */
    public static function telegramChartCandles(array $config = []): int
    {
        $raw = (int) ($config['telegram_chart_candles'] ?? self::DEFAULT_TELEGRAM_CHART_CANDLES);

        return max(self::MIN_TELEGRAM_CHART_CANDLES, min(self::MAX_TELEGRAM_CHART_CANDLES, $raw));
    }

    /** Рисовать ближайший FH/FL на Telegram PNG. */
    public static function telegramShowNearestLevel(array $config = []): bool
    {
        return self::flag($config, 'telegram_show_nearest_level', true);
    }

    /**
     * Lookback фракталов для линии уровня на PNG.
     * 0 / пусто → eng_level_lookback или $fallback.
     *
     * @param  array<string, mixed>  $config
     */
    public static function telegramLevelLookback(array $config = [], int $fallback = 40): int
    {
        $raw = (int) ($config['telegram_level_lookback'] ?? 0);
        if ($raw <= 0) {
            $raw = (int) ($config['eng_level_lookback'] ?? $fallback);
        }

        return max(10, min(200, $raw > 0 ? $raw : $fallback));
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
     * Опции для EngulfingDetector из bots.config.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function detectorOptions(array $config): array
    {
        // Ключ отсутствует (старые тесты/config) → мягкие дефолты; defaultConfig() для UI включает фильтры.
        $has = static fn (string $k): bool => array_key_exists($k, $config) && $config[$k] !== null && $config[$k] !== '';

        return [
            'allow_classic' => self::flag($config, 'eng_allow_classic', true),
            'allow_same_color' => self::flag($config, 'eng_allow_same_color', true),
            'require_level' => $has('eng_require_level') ? self::flag($config, 'eng_require_level', true) : false,
            'level_lookback' => max(10, min(200, (int) ($config['eng_level_lookback'] ?? 40))),
            'level_touch_pct' => max(0.01, min(2.0, (float) ($config['eng_level_touch_pct'] ?? 0.15))),
            'reaction_max_bars' => max(1, min(8, (int) ($config['eng_reaction_max_bars'] ?? ($has('eng_require_level') ? 3 : 1)))),
            'skip_if_left' => $has('eng_skip_if_left') ? self::flag($config, 'eng_skip_if_left', true) : false,
            'skip_if_breakout_hold' => $has('eng_skip_if_breakout_hold')
                ? self::flag($config, 'eng_skip_if_breakout_hold', true)
                : false,
            'breakout_hold_closes' => max(1, min(4, (int) ($config['eng_breakout_hold_closes'] ?? 2))),
        ];
    }

    /**
     * Собрать config из Request (settings form).
     *
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
            'eng_gap_lookback' => ['required', 'integer', 'min:5', 'max:500'],
            'eng_gap_max_ratio' => ['required', 'numeric', 'min:0.05', 'max:1'],
            'eng_gap_size_mult' => ['required', 'numeric', 'min:0.1', 'max:10'],
            'eng_level_lookback' => ['required', 'integer', 'min:10', 'max:200'],
            'eng_level_touch_pct' => ['required', 'numeric', 'min:0.01', 'max:2'],
            'eng_reaction_max_bars' => ['required', 'integer', 'min:1', 'max:8'],
            'eng_breakout_hold_closes' => ['required', 'integer', 'min:1', 'max:4'],
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

        if (! $request->boolean('eng_allow_classic') && ! $request->boolean('eng_allow_same_color')) {
            throw ValidationException::withMessages([
                'eng_allow_classic' => 'Включите хотя бы один тип поглощения: классическое или same-color.',
            ]);
        }

        $existingToken = trim((string) ($existing['telegram_bot_token'] ?? ''));
        $tokenInput = trim((string) ($extra['telegram_bot_token'] ?? ''));

        $config = array_merge($existing, [
            'count' => (int) $extra['count'],
            'expiry_sec' => (int) $extra['expiry_sec'],
            'timeframe' => (int) $extra['timeframe'],
            'min_confidence' => (float) $extra['min_confidence'],
            'min_payout' => (float) $extra['min_payout'],
            'market_scope' => BotInstrumentMarketScope::normalize($extra['market_scope'] ?? BotInstrumentMarketScope::ALL),
            'stale_after_sec' => (int) $extra['stale_after_sec'],
            'scan_interval_sec' => (int) $extra['scan_interval_sec'],
            'scan_workers' => (int) $extra['scan_workers'],
            'continuous_scan' => $request->boolean('continuous_scan'),
            'eng_allow_classic' => $request->boolean('eng_allow_classic'),
            'eng_allow_same_color' => $request->boolean('eng_allow_same_color'),
            'eng_require_level' => $request->boolean('eng_require_level'),
            'eng_level_lookback' => (int) $extra['eng_level_lookback'],
            'eng_level_touch_pct' => (float) $extra['eng_level_touch_pct'],
            'eng_reaction_max_bars' => (int) $extra['eng_reaction_max_bars'],
            'eng_skip_if_left' => $request->boolean('eng_skip_if_left'),
            'eng_skip_if_breakout_hold' => $request->boolean('eng_skip_if_breakout_hold'),
            'eng_breakout_hold_closes' => (int) $extra['eng_breakout_hold_closes'],
            'eng_gap_filter_enabled' => $request->boolean('eng_gap_filter_enabled'),
            'eng_gap_lookback' => (int) $extra['eng_gap_lookback'],
            'eng_gap_max_ratio' => (float) $extra['eng_gap_max_ratio'],
            'eng_gap_size_mult' => (float) $extra['eng_gap_size_mult'],
            'eng_skip_martingale_if_session_green' => $request->boolean('eng_skip_martingale_if_session_green'),
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
     * Подтянуть дефолты v3 в существующий bots.config без потери полей.
     * Миграция v1/v2 → v3: live-инфра как #47 + фильтры стримов, если ещё на старой версии.
     */
    public static function mergeDefaults(Bot $bot): array
    {
        $existing = is_array($bot->config) ? $bot->config : [];
        $merged = array_replace(self::defaultConfig(), $existing);

        $ver = (string) ($existing['version'] ?? '');
        if ($ver === '' || $ver === 'engulfing_liquidity_v1' || $ver === 'engulfing_liquidity_v2') {
            $merged['version'] = self::VERSION;
            $merged['continuous_scan'] = true;
            if (! array_key_exists('scan_workers', $existing) || $existing['scan_workers'] === null || $existing['scan_workers'] === '') {
                $merged['scan_workers'] = self::DEFAULT_SCAN_WORKERS;
            }
            // Legacy UI ставил 60 с (M1); для daemon как у #47 нужен poll 5 с.
            $interval = (int) ($existing['scan_interval_sec'] ?? 0);
            if ($interval <= 0 || $interval === 60) {
                $merged['scan_interval_sec'] = self::DEFAULT_SCAN_INTERVAL_SEC;
            }
            foreach (['eng_require_level', 'eng_skip_if_left', 'eng_skip_if_breakout_hold'] as $flag) {
                if (! array_key_exists($flag, $existing)) {
                    $merged[$flag] = true;
                }
            }
            if (! array_key_exists('eng_reaction_max_bars', $existing)) {
                $merged['eng_reaction_max_bars'] = 3;
            }
        }

        return $merged;
    }
}
