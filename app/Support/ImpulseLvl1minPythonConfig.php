<?php

namespace App\Support;

use RuntimeException;

/**
 * Читает 1min config.py (каталог new или корень бота) через Python и мапит поля в config Impulse LVL.
 * SSID / BOT_TOKEN из private_config не трогаем.
 */
final class ImpulseLvl1minPythonConfig
{
    public const DEFAULT_PATH = 'docs_need_release/1min/new/config.py';

    /**
     * @return array{
     *     source:string,
     *     overlay:array<string, mixed>,
     *     pairs:list<string>,
     *     skipped:list<string>
     * }
     */
    public static function load(string $path): array
    {
        $resolved = self::resolvePath($path);
        $raw = self::execPythonDump($resolved);

        return [
            'source' => $resolved,
            'overlay' => self::overlayFromRaw($raw),
            'pairs' => self::normalizePairs($raw['PAIRS'] ?? []),
            'skipped' => [
                'SSID / SSIDS / BOT_TOKEN / CHAT_ID — секреты, в бота не пишем',
                'TELEGRAM_CHART_* — цвета PNG оригинала, не UI платформы',
                'PAIRS — белый список платформы не затираем (пусто = все active + min_payout)',
            ],
        ];
    }

    public static function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = self::DEFAULT_PATH;
        }
        if ($path[0] !== '/') {
            $path = base_path($path);
        }
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            throw new RuntimeException('Файл config.py не найден: '.$path);
        }
        $root = realpath(base_path()) ?: base_path();
        if (! str_starts_with($real, $root)) {
            throw new RuntimeException('config.py должен лежать внутри репозитория платформы.');
        }

        return $real;
    }

    /**
     * Поля стратегии, которые можно безопасно влить в bot.config (telegram/subscribers не затираем).
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function overlayFromRaw(array $raw): array
    {
        $scan = ImpulseLvl1minConfig::normalizeScanIntervalSec($raw['SCAN_INTERVAL_SECONDS'] ?? 5);
        $history = max(20, min(500, (int) ($raw['HISTORY_PERIOD'] ?? ImpulseLvl1minConfig::DEFAULT_HISTORY_BARS)));
        $filter = strtoupper(trim((string) ($raw['GLOBAL_FILTER'] ?? 'LVL')));

        return [
            'scan_interval_sec' => $scan,
            'min_payout' => max(0.0, min(100.0, (float) ($raw['MIN_PAYOUT'] ?? 80))),
            'history_period' => $history,
            'trim_low_count' => max(0, min(90, (int) ($raw['TRIM_LOW_COUNT'] ?? 10))),
            'trim_high_count' => max(0, min(90, (int) ($raw['TRIM_HIGH_COUNT'] ?? 10))),
            'lvl_filter' => $filter === 'LVL' || $filter === '',
            'max_enabled' => true,
            'max_only_longest' => (bool) ($raw['MAX_ONLY_LONGEST_SERIES'] ?? true),
            'eval_prev_candle' => true,
            'overlay_forming_htf' => (bool) ($raw['LEVEL_UPDATE_HIGHER_TF'] ?? true),
            // SEND_RULE_SEQUENCE=false в 1min режет дубль того же profile_id, не чужие профили.
            'send_all_bar_alerts' => true,
            'live_candle_wick_signals' => (bool) ($raw['LIVE_CANDLE_WICK_SIGNALS'] ?? false),
            'occupancy_blocks_alerts' => false,
            'occupancy_blocks_paper' => true,
            'occupancy_scope' => 'instrument',
            'paper_prefer_live' => true,
            'max_percent_default' => self::percentFromMaxConfig($raw['MAX_BODY_PERCENT_DEFAULT'] ?? 120.0),
            'max_percent_series' => self::maxSeriesFromRaw($raw['MAX_BODY_PERCENT_SERIES'] ?? []),
            'level_settings' => self::levelSettingsFromRaw($raw['LEVEL_SETTINGS'] ?? []),
            'profiles' => ImpulseLvl1minConfig::normalizeProfiles(self::profilesFromRaw($raw['STRATEGY_PROFILES'] ?? [])),
            'python_config_source' => $raw['_source'] ?? '',
            'version' => ImpulseLvl1minConfig::VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    public static function profilesFromRaw(array $raw): array
    {
        $out = [];
        foreach ($raw as $position => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $multipliers = is_array($row['candle_multipliers'] ?? null) ? $row['candle_multipliers'] : [];
            if ($multipliers === []) {
                continue;
            }
            $normalized = [];
            foreach ($multipliers as $n => $mult) {
                $count = (int) $n;
                if ($count >= 1 && $count <= 8) {
                    $normalized[$count] = (float) $mult;
                }
            }
            if ($normalized === []) {
                continue;
            }
            ksort($normalized, SORT_NUMERIC);
            $count = (int) array_key_last($normalized);
            $out[] = [
                'id' => $id !== '' ? $id : 'profile_'.((int) $position + 1),
                'enabled' => (bool) ($row['enabled'] ?? true),
                'label' => trim((string) ($row['label'] ?? $id)),
                'emoji' => trim((string) ($row['emoji'] ?? '')),
                'candle_count' => $count,
                'multiplier' => (float) $normalized[$count],
                'candle_multipliers' => $normalized,
                'filter_enabled' => (bool) ($row['filter_enabled'] ?? true),
                'live_priority' => isset($row['live_priority']) ? (int) $row['live_priority'] : (int) $position,
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return array<int, float>
     */
    private static function maxSeriesFromRaw(mixed $raw): array
    {
        $out = [];
        if (! is_array($raw)) {
            return $out;
        }
        foreach ($raw as $n => $cfg) {
            $len = (int) $n;
            if ($len < 1 || $len > 8) {
                continue;
            }
            $out[$len] = self::percentFromMaxConfig($cfg);
        }

        return $out;
    }

    private static function percentFromMaxConfig(mixed $cfg): float
    {
        if (is_array($cfg)) {
            return max(100.0, min(300.0, (float) ($cfg['percent'] ?? 120.0)));
        }

        return max(100.0, min(300.0, (float) $cfg));
    }

    /**
     * Ключи dashboard TF: 1 / 5 / 15 / 30.
     *
     * @param  mixed  $raw
     * @return array<string, array{pivot_left:int,pivot_right:int,zone_mode:string,wick_tip_fraction:float,distance_body_multiplier:float}>
     */
    private static function levelSettingsFromRaw(mixed $raw): array
    {
        $out = [];
        if (! is_array($raw)) {
            return ImpulseLvl1minConfig::defaultLevelSettings();
        }
        foreach ($raw as $tfSec => $row) {
            if (! is_array($row)) {
                continue;
            }
            $dashboard = ImpulseLvl1minConfig::dashboardTfFromSeconds((int) $tfSec);
            if ($dashboard === null) {
                continue;
            }
            $mode = (string) ($row['zone_mode'] ?? '');
            if ($mode === '') {
                $mode = $dashboard === 1 ? 'body_to_wick' : 'wick_tip';
            }
            $out[(string) $dashboard] = [
                'pivot_left' => max(1, min(10, (int) ($row['pivot_left'] ?? 2))),
                'pivot_right' => max(1, min(10, (int) ($row['pivot_right'] ?? 2))),
                'zone_mode' => $mode === 'wick_tip' ? 'wick_tip' : 'body_to_wick',
                'wick_tip_fraction' => max(0.0, min(1.0, (float) ($row['wick_tip_fraction'] ?? 0.0))),
                'distance_body_multiplier' => max(0.0, min(1.0, (float) ($row['distance_body_multiplier'] ?? 0.03))),
            ];
        }

        return $out !== [] ? $out : ImpulseLvl1minConfig::defaultLevelSettings();
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private static function normalizePairs(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $pair) {
            $name = trim((string) $pair);
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, mixed>
     */
    private static function execPythonDump(string $path): array
    {
        $script = <<<'PY'
import importlib.util, json, sys
path = sys.argv[1]
spec = importlib.util.spec_from_file_location("impulse_lvl_py_cfg", path)
mod = importlib.util.module_from_spec(spec)
sys.modules["impulse_lvl_py_cfg"] = mod
assert spec.loader is not None
spec.loader.exec_module(mod)
keys = [
    "SCAN_INTERVAL_SECONDS", "HISTORY_PERIOD", "MIN_PAYOUT",
    "TRIM_LOW_COUNT", "TRIM_HIGH_COUNT", "GLOBAL_FILTER",
    "STRATEGY_PROFILES", "MAX_BODY_PERCENT_DEFAULT", "MAX_BODY_PERCENT_SERIES",
    "MAX_ONLY_LONGEST_SERIES", "LEVEL_SETTINGS", "PAIRS",
    "LEVEL_UPDATE_HIGHER_TF", "LIVE_CANDLE_WICK_SIGNALS",
]
out = {"_source": path}
for key in keys:
    if hasattr(mod, key):
        out[key] = getattr(mod, key)
print(json.dumps(out, default=str))
PY;
        $cmd = 'python3 -c '.escapeshellarg($script).' '.escapeshellarg($path);
        $json = shell_exec($cmd);
        if (! is_string($json) || trim($json) === '') {
            throw new RuntimeException('Не удалось прочитать config.py через python3.');
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Python вернул не JSON из config.py.');
        }
        $decoded['_source'] = $path;

        return $decoded;
    }
}
