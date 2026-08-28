<?php

namespace App\Support;

use App\Models\Bot;
use App\Services\CandleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * MaxBot — порт docs_need_release/MaxBot: серия одноцветных свечей + ratio тел к историческому максимуму.
 */
final class MaxBotConfig
{
    public const BOT_TYPE = 'max_bot';

    public const VERSION = 'max_bot_v1';

    /** Допустимые ТФ бота (секунды). */
    public const ALLOWED_TIMEFRAME_SEC = [5, 15, 30, 60, 300];

    /** Интервал scan для MaxBot (секунды). */
    public const ALLOWED_SCAN_INTERVAL_SEC = [5, 15, 30, 60];

    public const DEFAULT_TIMEFRAME_SEC = 60;

    public const DEFAULT_SCAN_INTERVAL_SEC = 5;

    public const DEFAULT_HISTORY_BARS = 100;

    /**
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'count' => self::DEFAULT_HISTORY_BARS + 10,
            'timeframe_sec' => self::DEFAULT_TIMEFRAME_SEC,
            'expiry_sec' => 60,
            'min_confidence' => 0.0,
            'min_payout' => 90.0,
            'stale_after_sec' => 30,
            'scan_interval_sec' => self::DEFAULT_SCAN_INTERVAL_SEC,
            'strategies' => self::defaultStrategies(),
            'telegram_enabled' => false,
            'telegram_notify_result' => true,
            'telegram_notify_on' => 'signal_trade',
            'telegram_alert_format' => 'brief',
            'telegram_send_chart' => true,
            'telegram_send_chart_m5' => true,
            'telegram_auto_subscribe' => true,
            'version' => self::VERSION,
        ];
    }

    /**
     * 4 пресета из оригинального MaxBot (все live, at_least).
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultStrategies(): array
    {
        return [
            self::strategyPreset('1:110%', 1, 1, 110.0),
            self::strategyPreset('2:120%', 2, 2, 120.0),
            self::strategyPreset('3:120%', 3, 3, 120.0),
            self::strategyPreset('4:120%', 4, 4, 120.0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function strategyPreset(string $id, int $streak, int $measured, float $threshold): array
    {
        return [
            'id' => $id,
            'enabled' => true,
            'streak_length' => $streak,
            'streak_mode' => 'at_least',
            'measured_candles' => $measured,
            'lookback_bars' => self::DEFAULT_HISTORY_BARS,
            'threshold_pct' => $threshold,
            'color' => '',
            'signal_timing' => 'live',
            'direction' => 'trend',
        ];
    }

    public static function is(string $botType): bool
    {
        return $botType === self::BOT_TYPE;
    }

    /** Секунды ТФ → dashboard TF id (CandleService). */
    public static function dashboardTimeframeId(int $timeframeSec): int
    {
        return match ($timeframeSec) {
            5 => CandleService::TF_S5,
            15 => CandleService::TF_S15,
            30 => CandleService::TF_S30,
            60 => 1,
            300 => 5,
            default => 1,
        };
    }

    public static function normalizeScanIntervalSec(mixed $raw): int
    {
        $value = (int) $raw;

        return in_array($value, self::ALLOWED_SCAN_INTERVAL_SEC, true)
            ? $value
            : self::DEFAULT_SCAN_INTERVAL_SEC;
    }

    /**
     * @param  list<array<string, mixed>>  $strategies
     */
    public static function requiredHistoryBars(array $strategies, int $baseCount = self::DEFAULT_HISTORY_BARS): int
    {
        $required = $baseCount;
        foreach ($strategies as $strategy) {
            if (! ($strategy['enabled'] ?? true)) {
                continue;
            }
            $lookback = max(2, (int) ($strategy['lookback_bars'] ?? $baseCount));
            $streak = max(1, (int) ($strategy['streak_length'] ?? 1));
            $measured = max(1, (int) ($strategy['measured_candles'] ?? 1));
            $required = max($required, $lookback + max($streak, $measured) + 1);
        }

        return $required;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function enabledStrategies(array $config): array
    {
        $raw = is_array($config['strategies'] ?? null) ? $config['strategies'] : [];

        return array_values(array_filter(
            self::normalizeStrategies($raw),
            static fn (array $s): bool => (bool) ($s['enabled'] ?? true)
        ));
    }

    /**
     * @param  list<mixed>  $rawStrategies
     * @return list<array<string, mixed>>
     */
    public static function normalizeStrategies(array $rawStrategies): array
    {
        $normalized = [];
        $usedIds = [];
        foreach ($rawStrategies as $position => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            if (! (bool) ($raw['enabled'] ?? true)) {
                // Сохраняем выключенные стратегии в конфиге для UI.
                $parsed = self::parseStrategyRow($raw, $position + 1);
                $parsed['enabled'] = false;
                $normalized[] = $parsed;
                continue;
            }
            $parsed = self::parseStrategyRow($raw, $position + 1);
            $strategyId = $parsed['id'];
            if ($strategyId === '' || isset($usedIds[$strategyId])) {
                throw ValidationException::withMessages([
                    'strategies' => "Повторяющийся или пустой id стратегии: {$strategyId}",
                ]);
            }
            $usedIds[$strategyId] = true;
            $normalized[] = $parsed;
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'strategies' => 'Добавьте хотя бы одну стратегию.',
            ]);
        }

        $hasEnabled = false;
        foreach ($normalized as $strategy) {
            if ($strategy['enabled']) {
                $hasEnabled = true;
                break;
            }
        }
        if (! $hasEnabled) {
            throw ValidationException::withMessages([
                'strategies' => 'Включите хотя бы одну стратегию.',
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function parseStrategyRow(array $raw, int $position): array
    {
        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            $id = 'strategy_'.$position;
        }

        $color = strtolower(trim((string) ($raw['color'] ?? '')));
        if (in_array($color, ['', 'any'], true)) {
            $color = '';
        }

        $streakMode = strtolower(trim((string) ($raw['streak_mode'] ?? 'exact')));
        if (! in_array($streakMode, ['exact', 'at_least'], true)) {
            $streakMode = 'exact';
        }

        $signalTiming = strtolower(trim((string) ($raw['signal_timing'] ?? 'live')));
        if (! in_array($signalTiming, ['live', 'closed'], true)) {
            $signalTiming = 'live';
        }

        $direction = strtolower(trim((string) ($raw['direction'] ?? 'trend')));
        if (! in_array($direction, ['trend', 'counter'], true)) {
            $direction = 'trend';
        }

        $streakLength = max(1, (int) ($raw['streak_length'] ?? 1));
        $measured = max(1, min($streakLength, (int) ($raw['measured_candles'] ?? 1)));
        $lookback = max(2, (int) ($raw['lookback_bars'] ?? self::DEFAULT_HISTORY_BARS));
        $threshold = max(0.1, (float) ($raw['threshold_pct'] ?? 100.0));

        if ($color !== '' && ! in_array($color, ['red', 'green'], true)) {
            throw ValidationException::withMessages([
                'strategies' => "{$id}: color должен быть red, green или пустым.",
            ]);
        }

        return [
            'id' => $id,
            'enabled' => (bool) ($raw['enabled'] ?? true),
            'streak_length' => $streakLength,
            'streak_mode' => $streakMode,
            'measured_candles' => $measured,
            'lookback_bars' => $lookback,
            'threshold_pct' => $threshold,
            'color' => $color,
            'signal_timing' => $signalTiming,
            'direction' => $direction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildFromRequest(Request $request, Bot $bot): array
    {
        $extra = $request->validate([
            'count' => ['required', 'integer', 'min:40', 'max:5000'],
            'timeframe_sec' => ['required', 'integer', Rule::in(self::ALLOWED_TIMEFRAME_SEC)],
            'expiry_sec' => ['required', 'integer', 'min:5', 'max:900'],
            'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
            'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            'scan_interval_sec' => ['required', 'integer', Rule::in(self::ALLOWED_SCAN_INTERVAL_SEC)],
            'strategies' => ['required', 'array', 'min:1', 'max:20'],
            'strategies.*.id' => ['nullable', 'string', 'max:32'],
            'strategies.*.enabled' => ['sometimes', 'boolean'],
            'strategies.*.streak_length' => ['required', 'integer', 'min:1', 'max:20'],
            'strategies.*.streak_mode' => ['required', 'string', Rule::in(['exact', 'at_least'])],
            'strategies.*.measured_candles' => ['required', 'integer', 'min:1', 'max:20'],
            'strategies.*.lookback_bars' => ['required', 'integer', 'min:2', 'max:500'],
            'strategies.*.threshold_pct' => ['required', 'numeric', 'min:1', 'max:500'],
            'strategies.*.color' => ['nullable', 'string', 'max:16'],
            'strategies.*.signal_timing' => ['required', 'string', Rule::in(['live', 'closed'])],
            'strategies.*.direction' => ['required', 'string', Rule::in(['trend', 'counter'])],
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
        ]);

        $existingToken = trim((string) (($bot->config ?? [])['telegram_bot_token'] ?? ''));
        $tokenInput = trim((string) ($extra['telegram_bot_token'] ?? ''));

        $strategyRows = [];
        foreach ($extra['strategies'] as $index => $row) {
            $strategyRows[] = [
                'id' => $row['id'] ?? null,
                'enabled' => $request->boolean('strategies.'.$index.'.enabled'),
                'streak_length' => (int) $row['streak_length'],
                'streak_mode' => (string) $row['streak_mode'],
                'measured_candles' => (int) $row['measured_candles'],
                'lookback_bars' => (int) $row['lookback_bars'],
                'threshold_pct' => (float) $row['threshold_pct'],
                'color' => (string) ($row['color'] ?? ''),
                'signal_timing' => (string) $row['signal_timing'],
                'direction' => (string) $row['direction'],
            ];
        }

        $strategies = self::normalizeStrategies($strategyRows);
        $countMin = self::requiredHistoryBars($strategies);
        if ((int) $extra['count'] < $countMin) {
            throw ValidationException::withMessages([
                'count' => "Минимум {$countMin} свечей для выбранных стратегий (lookback + streak).",
            ]);
        }

        return array_merge($bot->config ?? [], [
            'count' => (int) $extra['count'],
            'timeframe_sec' => (int) $extra['timeframe_sec'],
            'expiry_sec' => (int) $extra['expiry_sec'],
            'min_confidence' => (float) $extra['min_confidence'],
            'min_payout' => (float) $extra['min_payout'],
            'stale_after_sec' => (int) $extra['stale_after_sec'],
            'scan_interval_sec' => self::normalizeScanIntervalSec($extra['scan_interval_sec']),
            'strategies' => $strategies,
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
            'version' => self::VERSION,
        ]);
    }
}
