<?php

namespace App\Support;

use App\Models\Bot;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Impulse LVL 1min — порт docs_need_release/1min: импульс к trim-mean / MAX-ratio + обязательный LVL.
 */
final class ImpulseLvl1minConfig
{
    public const BOT_TYPE = 'impulse_lvl_1min';

    public const VERSION = 'impulse_lvl_1min_v1';

    public const TITLE = 'Impulse LVL 1min';

    /** Интервал scan для live M1 (секунды). */
    public const ALLOWED_SCAN_INTERVAL_SEC = [5, 15, 30, 60];

    public const DEFAULT_SCAN_INTERVAL_SEC = 5;

    /** Параллельные процессы внутри одного bots:scan (как несколько SSID у 1min). */
    public const ALLOWED_SCAN_WORKERS = [1, 2, 3, 4, 6, 8];

    public const DEFAULT_SCAN_WORKERS = 6;

    public const DEFAULT_TIMEFRAME_SEC = 60;

    public const DEFAULT_HISTORY_BARS = 100;

    public const TRIM_LOW_COUNT = 10;

    public const TRIM_HIGH_COUNT = 10;

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
            'count' => 400,
            'timeframe_sec' => self::DEFAULT_TIMEFRAME_SEC,
            'expiry_sec' => 60,
            'min_confidence' => 0.0,
            'min_payout' => 80.0,
            'stale_after_sec' => 30,
            'scan_interval_sec' => self::DEFAULT_SCAN_INTERVAL_SEC,
            'scan_workers' => 6,
            'signal_timing' => 'live',
            'lvl_filter' => true,
            'max_enabled' => true,
            'max_only_longest' => true,
            // Live-поведение как 1min (сегодня было захардкожено в strategy/engine/levels).
            'eval_prev_candle' => true,
            'overlay_forming_htf' => true,
            'send_all_bar_alerts' => true,
            // Live-фитиль как updateBotM1; выкл. по умолчанию — бэктест без partial не меняется.
            'live_candle_wick_signals' => false,
            'keep_stale_forming_m1' => true,
            'occupancy_blocks_alerts' => false,
            'occupancy_blocks_paper' => true,
            'occupancy_scope' => 'instrument',
            'paper_prefer_live' => true,
            'history_period' => self::DEFAULT_HISTORY_BARS,
            'trim_low_count' => self::TRIM_LOW_COUNT,
            'trim_high_count' => self::TRIM_HIGH_COUNT,
            'max_percent_default' => 120.0,
            'max_percent_series' => [1 => 110.0, 2 => 130.0],
            'level_settings' => self::defaultLevelSettings(),
            'm5_take' => 80,
            'm15_take' => 60,
            'm30_take' => 40,
            'profiles' => self::defaultProfiles(),
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
     * Четыре включённых профиля из 1min/config.py (structure_no_lvl не тащим).
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultProfiles(): array
    {
        return [
            ['id' => 'extra_wl', 'enabled' => true, 'label' => 'сильный', 'emoji' => '⚪', 'candle_count' => 3, 'multiplier' => 4.75],
            ['id' => 'extra', 'enabled' => true, 'label' => 'обычный', 'emoji' => '🟢', 'candle_count' => 1, 'multiplier' => 3.50],
            ['id' => 'extreme', 'enabled' => true, 'label' => 'частый', 'emoji' => '🟡', 'candle_count' => 3, 'multiplier' => 4.25],
            ['id' => 'structure', 'enabled' => true, 'label' => 'мягкий', 'emoji' => '🟣', 'candle_count' => 1, 'multiplier' => 3.00],
        ];
    }

    /**
     * Кружок из 1min/config.py (⚪ extra_wl, 🟢 extra, 🟡 extreme, 🟣 structure, 🟤 structure_no_lvl).
     */
    public static function defaultEmojiForProfileId(string $id): string
    {
        if ($id === 'MAX' || str_starts_with($id, 'MAX')) {
            return '';
        }

        return match ($id) {
            'extra_wl' => '⚪',
            'extra' => '🟢',
            'extreme' => '🟡',
            'structure' => '🟣',
            'structure_no_lvl' => '🟤',
            default => '⚪',
        };
    }

    /**
     * Эмодзи профиля: только короткий текст без HTML (Telegram parse_mode=HTML).
     */
    public static function sanitizeEmoji(mixed $raw): string
    {
        $s = trim(strip_tags((string) $raw));
        $s = preg_replace('/[\x00-\x1F<>&]/u', '', $s) ?? '';
        if (function_exists('mb_strlen') && mb_strlen($s) > 4) {
            return mb_substr($s, 0, 4);
        }
        if (strlen($s) > 16) {
            return substr($s, 0, 16);
        }

        return $s;
    }

    /**
     * Строка как в оригинале: «⚪ extra_wl 3-» / «MAX» / «MAX2».
     *
     * @param  array<string, mixed>  $signal
     */
    public static function outputLabel(array $signal): string
    {
        $kind = (string) ($signal['kind'] ?? '');
        $id = trim((string) ($signal['strategy_id'] ?? $signal['id'] ?? ''));
        if ($kind === 'max' || str_starts_with($id, 'MAX')) {
            $max = $id !== '' ? $id : 'MAX';

            return ! empty($signal['is_prev']) ? $max.' prev' : $max;
        }

        $emoji = self::sanitizeEmoji($signal['emoji'] ?? $signal['profile_emoji'] ?? '');
        if ($emoji === '') {
            $emoji = self::defaultEmojiForProfileId($id);
        }
        $n = (int) ($signal['candle_count'] ?? 0);
        $nSuffix = $n > 0 ? ' '.$n.'-' : '';
        $prev = ! empty($signal['is_prev']) ? ' prev' : '';

        return trim($emoji.' '.$id.$nSuffix).$prev;
    }

    public static function historyPeriod(array $config = []): int
    {
        return max(20, min(500, (int) ($config['history_period'] ?? self::DEFAULT_HISTORY_BARS)));
    }

    /** Сколько PHP-воркеров на круг пар: 1 = как раньше по очереди, 6 ≈ оригинальные SSID. */
    public static function scanWorkers(array $config = []): int
    {
        $raw = (int) ($config['scan_workers'] ?? self::DEFAULT_SCAN_WORKERS);

        return in_array($raw, self::ALLOWED_SCAN_WORKERS, true) ? $raw : self::DEFAULT_SCAN_WORKERS;
    }

    /**
     * Булев флаг из bots.config: нет ключа → дефолт (старые JSON без поля ведут себя как сейчас).
     *
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

    /** instrument = ждать закрытия paper только по этой паре; bot = по любой паре этого бота. */
    public static function occupancyScope(array $config = []): string
    {
        $raw = (string) ($config['occupancy_scope'] ?? 'instrument');

        return $raw === 'bot' ? 'bot' : 'instrument';
    }

    public static function trimLowCount(array $config = []): int
    {
        return max(0, min(90, (int) ($config['trim_low_count'] ?? self::TRIM_LOW_COUNT)));
    }

    public static function trimHighCount(array $config = []): int
    {
        return max(0, min(90, (int) ($config['trim_high_count'] ?? self::TRIM_HIGH_COUNT)));
    }

    /**
     * Порог MAX для длины серии N: из config.py MAX_BODY_PERCENT_SERIES, иначе 1→110%, 2→130%, default 120%.
     *
     * @param  array<string, mixed>  $config
     */
    public static function maxPercentForLength(int $n, array $config = []): float
    {
        $series = is_array($config['max_percent_series'] ?? null) ? $config['max_percent_series'] : [];
        if (array_key_exists($n, $series)) {
            return max(100.0, min(300.0, (float) $series[$n]));
        }
        if (array_key_exists((string) $n, $series)) {
            return max(100.0, min(300.0, (float) $series[(string) $n]));
        }

        return max(100.0, min(300.0, (float) ($config['max_percent_default'] ?? match ($n) {
            1 => 110.0,
            2 => 130.0,
            default => 120.0,
        })));
    }

    /**
     * @return array<string, array{pivot_left:int,pivot_right:int,zone_mode:string,wick_tip_fraction:float,distance_body_multiplier:float}>
     */
    public static function defaultLevelSettings(): array
    {
        return [
            '1' => [
                'pivot_left' => 3,
                'pivot_right' => 3,
                'zone_mode' => 'body_to_wick',
                'wick_tip_fraction' => 0.0,
                'distance_body_multiplier' => 0.03,
            ],
            '5' => [
                'pivot_left' => 2,
                'pivot_right' => 2,
                'zone_mode' => 'wick_tip',
                'wick_tip_fraction' => 0.10,
                'distance_body_multiplier' => 0.03,
            ],
            '15' => [
                'pivot_left' => 1,
                'pivot_right' => 1,
                'zone_mode' => 'wick_tip',
                'wick_tip_fraction' => 0.07,
                'distance_body_multiplier' => 0.03,
            ],
            '30' => [
                'pivot_left' => 1,
                'pivot_right' => 1,
                'zone_mode' => 'wick_tip',
                'wick_tip_fraction' => 0.07,
                'distance_body_multiplier' => 0.03,
            ],
        ];
    }

    public static function dashboardTfFromSeconds(int $tfSeconds): ?int
    {
        return match ($tfSeconds) {
            60 => 1,
            300 => 5,
            900 => 15,
            1800 => 30,
            default => null,
        };
    }

    /**
     * Настройки свингов по dashboard-ТФ (1=M1, 5=M5, 15=M15, 30=M30).
     *
     * @param  array<string, mixed>  $config
     * @return array{pivot_left:int,pivot_right:int,zone_mode:string,wick_tip_fraction:float,distance_body_multiplier:float}
     */
    public static function levelSettingsForTf(int $dashboardTf, array $config = []): array
    {
        $defaults = self::defaultLevelSettings();
        $key = (string) $dashboardTf;
        $fromConfig = is_array($config['level_settings'] ?? null) ? $config['level_settings'] : [];
        $row = is_array($fromConfig[$key] ?? null)
            ? $fromConfig[$key]
            : (is_array($fromConfig[$dashboardTf] ?? null) ? $fromConfig[$dashboardTf] : ($defaults[$key] ?? $defaults['5']));

        $mode = (string) ($row['zone_mode'] ?? 'body_to_wick');

        return [
            'pivot_left' => max(1, min(10, (int) ($row['pivot_left'] ?? 2))),
            'pivot_right' => max(1, min(10, (int) ($row['pivot_right'] ?? 2))),
            'zone_mode' => $mode === 'wick_tip' ? 'wick_tip' : 'body_to_wick',
            'wick_tip_fraction' => max(0.0, min(1.0, (float) ($row['wick_tip_fraction'] ?? 0.0))),
            'distance_body_multiplier' => max(0.0, min(1.0, (float) ($row['distance_body_multiplier'] ?? 0.03))),
        ];
    }

    /**
     * Сколько баров HTF грузить (ключи — dashboard TF).
     *
     * @return array<int, int>
     */
    public static function mtfTakeMap(array $config): array
    {
        return [
            5 => max(20, min(300, (int) ($config['m5_take'] ?? 80))),
            15 => max(20, min(300, (int) ($config['m15_take'] ?? 60))),
            30 => max(10, min(200, (int) ($config['m30_take'] ?? 40))),
        ];
    }

    public static function dashboardTimeframeId(int $timeframeSec): int
    {
        return match ($timeframeSec) {
            60 => 1,
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
     * @return list<array<string, mixed>>
     */
    public static function enabledProfiles(array $config): array
    {
        $raw = is_array($config['profiles'] ?? null) ? $config['profiles'] : self::defaultProfiles();

        return array_values(array_filter(
            self::normalizeProfiles($raw),
            static fn (array $p): bool => (bool) ($p['enabled'] ?? true)
        ));
    }

    /**
     * Меньше число — выше приоритет на живом фитиле (live_priority или индекс в STRATEGY_PROFILES).
     *
     * @param  array<string, mixed>  $config
     */
    public static function profileLivePriority(array $config, string $profileId): int
    {
        $raw = is_array($config['profiles'] ?? null) ? $config['profiles'] : self::defaultProfiles();
        $profiles = self::normalizeProfiles($raw);
        foreach ($profiles as $index => $profile) {
            if ((string) $profile['id'] !== $profileId) {
                continue;
            }
            if (array_key_exists('live_priority', $profile) && $profile['live_priority'] !== null && $profile['live_priority'] !== '') {
                return (int) $profile['live_priority'];
            }

            return (int) $index;
        }

        return count($profiles);
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array<string, mixed>>
     */
    public static function normalizeProfiles(array $raw): array
    {
        $out = [];
        $used = [];
        foreach ($raw as $position => $row) {
            if (! is_array($row)) {
                continue;
            }
            $parsed = self::parseProfileRow($row, $position + 1);
            if ($parsed['id'] === '' || isset($used[$parsed['id']])) {
                throw ValidationException::withMessages([
                    'profiles' => 'Повторяющийся или пустой id профиля: '.$parsed['id'],
                ]);
            }
            $used[$parsed['id']] = true;
            $out[] = $parsed;
        }

        if ($out === []) {
            $out = self::defaultProfiles();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function parseProfileRow(array $raw, int $position): array
    {
        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            $id = 'profile_'.$position;
        }
        $multipliers = self::profileMultipliers($raw);
        $count = (int) array_key_last($multipliers);
        $mult = (float) $multipliers[$count];

        $emoji = self::sanitizeEmoji($raw['emoji'] ?? '');
        if ($emoji === '') {
            $emoji = self::defaultEmojiForProfileId($id);
        }

        return [
            'id' => $id,
            'enabled' => (bool) ($raw['enabled'] ?? true),
            'label' => trim((string) ($raw['label'] ?? $id)),
            'emoji' => $emoji,
            'candle_count' => $count,
            'multiplier' => $mult,
            'candle_multipliers' => $multipliers,
            // Как filter_enabled в 1min: false = профиль без LVL (structure_no_lvl).
            'filter_enabled' => (bool) ($raw['filter_enabled'] ?? true),
            'live_priority' => isset($raw['live_priority']) && $raw['live_priority'] !== '' && $raw['live_priority'] !== null
                ? (int) $raw['live_priority']
                : $position - 1,
        ];
    }

    /**
     * Правила N→множитель внутри профиля (как candle_multipliers в Python).
     *
     * @param  array<string, mixed>  $profile
     * @return array<int, float>
     */
    public static function profileMultipliers(array $profile): array
    {
        $raw = $profile['candle_multipliers'] ?? null;
        $out = [];
        if (is_array($raw) && $raw !== []) {
            foreach ($raw as $n => $mult) {
                $count = (int) $n;
                if ($count < 1 || $count > 8) {
                    continue;
                }
                $out[$count] = max(0.5, min(20.0, (float) $mult));
            }
        }
        if ($out === []) {
            $count = max(1, min(8, (int) ($profile['candle_count'] ?? 1)));
            $out[$count] = max(0.5, min(20.0, (float) ($profile['multiplier'] ?? 3.0)));
        }
        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildFromRequest(Request $request, Bot $bot): array
    {
        $extra = $request->validate([
            'count' => ['required', 'integer', 'min:110', 'max:5000'],
            'expiry_sec' => ['required', 'integer', 'min:5', 'max:900'],
            'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'min_payout' => ['required', 'numeric', 'min:0', 'max:100'],
            'stale_after_sec' => ['required', 'integer', 'min:5', 'max:600'],
            'scan_interval_sec' => ['required', 'integer', Rule::in(self::ALLOWED_SCAN_INTERVAL_SEC)],
            'scan_workers' => ['required', 'integer', Rule::in(self::ALLOWED_SCAN_WORKERS)],
            'occupancy_scope' => ['required', 'string', Rule::in(['instrument', 'bot'])],
            'trim_low_count' => ['required', 'integer', 'min:0', 'max:90'],
            'trim_high_count' => ['required', 'integer', 'min:0', 'max:90'],
            'm5_take' => ['required', 'integer', 'min:20', 'max:300'],
            'm15_take' => ['required', 'integer', 'min:20', 'max:300'],
            'm30_take' => ['required', 'integer', 'min:10', 'max:200'],
            'profiles' => ['required', 'array', 'min:1', 'max:8'],
            'profiles.*.id' => ['required', 'string', 'max:32'],
            'profiles.*.label' => ['nullable', 'string', 'max:32'],
            'profiles.*.emoji' => ['nullable', 'string', 'max:8'],
            'profiles.*.candle_count' => ['required', 'integer', 'min:1', 'max:8'],
            'profiles.*.multiplier' => ['required', 'numeric', 'min:0.5', 'max:20'],
            'profiles.*.multipliers_json' => ['nullable', 'string', 'max:500'],
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

        $profileRows = [];
        foreach ($extra['profiles'] as $index => $row) {
            $decoded = json_decode((string) ($row['multipliers_json'] ?? ''), true);
            $profileRows[] = [
                'id' => $row['id'],
                'enabled' => $request->boolean('profiles.'.$index.'.enabled'),
                'label' => (string) ($row['label'] ?? ''),
                'emoji' => (string) ($row['emoji'] ?? ''),
                'candle_count' => (int) $row['candle_count'],
                'multiplier' => (float) $row['multiplier'],
                'candle_multipliers' => is_array($decoded) ? $decoded : [
                    (int) $row['candle_count'] => (float) $row['multiplier'],
                ],
                'filter_enabled' => $request->boolean('profiles.'.$index.'.filter_enabled'),
            ];
        }

        $profiles = self::normalizeProfiles($profileRows);
        $hasEnabled = false;
        foreach ($profiles as $profile) {
            if ($profile['enabled']) {
                $hasEnabled = true;
                break;
            }
        }
        $maxEnabled = $request->boolean('max_enabled');
        if (! $hasEnabled && ! $maxEnabled) {
            throw ValidationException::withMessages([
                'profiles' => 'Включите хотя бы один профиль или блок MAX.',
            ]);
        }

        return array_merge($bot->config ?? [], [
            'count' => (int) $extra['count'],
            'timeframe_sec' => self::DEFAULT_TIMEFRAME_SEC,
            'expiry_sec' => (int) $extra['expiry_sec'],
            'min_confidence' => (float) $extra['min_confidence'],
            'min_payout' => (float) $extra['min_payout'],
            'stale_after_sec' => (int) $extra['stale_after_sec'],
            'scan_interval_sec' => self::normalizeScanIntervalSec($extra['scan_interval_sec']),
            'scan_workers' => self::scanWorkers(['scan_workers' => $extra['scan_workers'] ?? self::DEFAULT_SCAN_WORKERS]),
            'signal_timing' => $request->boolean('signal_timing_closed') ? 'closed' : 'live',
            'lvl_filter' => $request->boolean('lvl_filter'),
            'max_enabled' => $maxEnabled,
            'max_only_longest' => $request->boolean('max_only_longest'),
            'eval_prev_candle' => $request->boolean('eval_prev_candle'),
            'overlay_forming_htf' => $request->boolean('overlay_forming_htf'),
            'send_all_bar_alerts' => $request->boolean('send_all_bar_alerts'),
            'live_candle_wick_signals' => $request->boolean('live_candle_wick_signals'),
            'keep_stale_forming_m1' => $request->boolean('keep_stale_forming_m1'),
            'occupancy_blocks_alerts' => $request->boolean('occupancy_blocks_alerts'),
            'occupancy_blocks_paper' => $request->boolean('occupancy_blocks_paper'),
            'occupancy_scope' => self::occupancyScope(['occupancy_scope' => $extra['occupancy_scope'] ?? 'instrument']),
            'paper_prefer_live' => $request->boolean('paper_prefer_live'),
            'trim_low_count' => self::trimLowCount(['trim_low_count' => $extra['trim_low_count'] ?? self::TRIM_LOW_COUNT]),
            'trim_high_count' => self::trimHighCount(['trim_high_count' => $extra['trim_high_count'] ?? self::TRIM_HIGH_COUNT]),
            'm5_take' => (int) $extra['m5_take'],
            'm15_take' => (int) $extra['m15_take'],
            'm30_take' => (int) $extra['m30_take'],
            'profiles' => $profiles,
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
