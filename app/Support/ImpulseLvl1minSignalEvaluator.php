<?php

namespace App\Support;

/**
 * Ядро 1min: trim-mean профили + MAX-ratio по самой длинной серии; LVL — отдельный фильтр.
 */
final class ImpulseLvl1minSignalEvaluator
{
    private const EPSILON = 1e-12;

    /**
     * Все прошедшие MAX + профили на этой свече (как 1min detect_signals + фильтр LVL).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $closedHistory  без свечи сигнала
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @param  array<string, mixed>  $config
     * @param  array<string, list<array<string, mixed>>>  $mtf
     * @param  float|null  $evalBodyOverride  фитильная мера live M1; null = тело close−open
     * @param  int|null  $evalDirectionOverride  сторона фитиля; null = знак тела
     * @return list<array<string, mixed>>
     */
    public static function findAllSignals(
        array $closedHistory,
        array $evalCandle,
        array $config,
        array $mtf,
        ?float $evalBodyOverride = null,
        ?int $evalDirectionOverride = null,
    ): array {
        if (count($closedHistory) < ImpulseLvl1minConfig::historyPeriod($config)) {
            return [];
        }

        $direction = $evalDirectionOverride ?? self::candleDirection($evalCandle);
        if ($direction === 0) {
            return [];
        }

        $histBodies = [];
        $histDirs = [];
        foreach ($closedHistory as $c) {
            $histBodies[] = self::candleBody($c);
            $histDirs[] = self::candleDirection($c);
        }

        $histPeriod = ImpulseLvl1minConfig::historyPeriod($config);
        $mean = self::trimmedMeanFromBodies(
            $histBodies,
            $histPeriod,
            ImpulseLvl1minConfig::trimLowCount($config),
            ImpulseLvl1minConfig::trimHighCount($config)
        );
        if ($mean <= self::EPSILON) {
            return [];
        }

        $evalBody = $evalBodyOverride ?? self::candleBody($evalCandle);
        // Как в 1min: recent_* maxlen=8 — профили и длина MAX-серии не длиннее 8 баров.
        $recentDirs = array_slice($histDirs, -7);
        $recentBodies = array_slice($histBodies, -7);
        $recentDirs[] = $direction;
        $recentBodies[] = $evalBody;

        $maxHits = [];
        if ((bool) ($config['max_enabled'] ?? true)) {
            $maxHits = self::findMaxHits($histBodies, $histDirs, $evalBody, $recentDirs, $config);
        }

        $profileHits = self::findProfileHits($recentBodies, $recentDirs, $mean, $config);
        $globalLvl = (bool) ($config['lvl_filter'] ?? true);
        $lvlCache = null;
        $resolveLvl = static function () use (&$lvlCache, $closedHistory, $evalCandle, $mtf, $direction, $config): array {
            $lvlCache ??= ImpulseLvl1minLevels::match($closedHistory, $evalCandle, $mtf, $direction, $config);

            return $lvlCache;
        };

        $candidates = [];
        foreach ($maxHits as $maxSignal) {
            $candidates[] = $maxSignal + ['needs_lvl' => true];
        }
        foreach ($profileHits as $hit) {
            $candidates[] = $hit;
        }

        // Как 1min: MAX и каждый profile_id — отдельные сигналы (SEND_RULE_SEQUENCE=false режет только дубль того же id).
        $accepted = [];
        foreach ($candidates as $candidate) {
            $needsLvl = $globalLvl && (bool) ($candidate['needs_lvl'] ?? true);
            if ($needsLvl) {
                $lvl = $resolveLvl();
                if (! $lvl['ok']) {
                    continue;
                }
            } else {
                $lvl = ['ok' => true, 'labels' => [], 'tf_seconds' => [], 'zones' => []];
            }
            $accepted[] = self::hydrateHit($candidate, $evalCandle, $direction, $mean, $lvl);
        }

        return $accepted;
    }

    /**
     * Первый хит (MAX, иначе первый профиль) — paper / бэктест.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $closedHistory
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @param  array<string, mixed>  $config
     * @param  array<string, list<array<string, mixed>>>  $mtf
     * @return array<string, mixed>|null
     */
    public static function findSignal(array $closedHistory, array $evalCandle, array $config, array $mtf): ?array
    {
        $all = self::findAllSignals($closedHistory, $evalCandle, $config, $mtf);

        return $all[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $picked
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @param  array{ok:bool,labels:list<string>,tf_seconds:list<int>}  $lvl
     * @return array<string, mixed>
     */
    private static function hydrateHit(array $picked, array $evalCandle, int $direction, float $mean, array $lvl): array
    {
        $color = $direction > 0 ? 'green' : 'red';
        $emoji = ImpulseLvl1minConfig::sanitizeEmoji($picked['emoji'] ?? '');
        if ($emoji === '' && (string) ($picked['kind'] ?? '') !== 'max') {
            $emoji = ImpulseLvl1minConfig::defaultEmojiForProfileId((string) $picked['id']);
        }

        return [
            'strategy_id' => (string) $picked['id'],
            'kind' => (string) $picked['kind'],
            'value' => (float) $picked['value'],
            'label' => (string) ($picked['label'] ?? $picked['id']),
            'emoji' => $emoji,
            'candle_count' => (int) ($picked['candle_count'] ?? 0),
            'signal_timestamp' => (int) ($evalCandle['time'] ?? 0),
            'color' => $color,
            'close' => (float) ($evalCandle['close'] ?? 0),
            'average_body' => $mean,
            'lvl_labels' => $lvl['labels'],
            'lvl_zones' => is_array($lvl['zones'] ?? null) ? $lvl['zones'] : [],
            'is_prev' => false,
        ];
    }

    /**
     * Trim 10+10 из последних 100 закрытых тел (как compute_trimmed_mean_body_values).
     *
     * @param  list<array{open?:float,close?:float}>  $closedHistory
     */
    public static function trimmedMeanBody(array $closedHistory, int $lookback = 100, int $trimLow = 10, int $trimHigh = 10): float
    {
        $bodies = [];
        foreach (array_slice($closedHistory, -$lookback) as $c) {
            $bodies[] = self::candleBody($c);
        }

        return self::trimmedMeanFromBodies($bodies, $lookback, $trimLow, $trimHigh);
    }

    /**
     * @param  list<float>  $bodies
     */
    public static function trimmedMeanFromBodies(array $bodies, int $lookback = 100, int $trimLow = 10, int $trimHigh = 10): float
    {
        $bodies = array_slice($bodies, -$lookback);
        $n = count($bodies);
        if ($n === 0) {
            return 0.0;
        }
        if ($n < 5) {
            return array_sum($bodies) / $n;
        }
        sort($bodies);
        $trimLow = max(0, $trimLow);
        $trimHigh = max(0, $trimHigh);
        $maxTotal = $n - 1;
        $totalTrim = min($trimLow + $trimHigh, $maxTotal);
        $trimLow = min($trimLow, $totalTrim);
        $trimHigh = min($trimHigh, $totalTrim - $trimLow);
        $slice = array_slice($bodies, $trimLow, $n - $trimLow - $trimHigh);
        if ($slice === []) {
            return 0.0;
        }

        return array_sum($slice) / count($slice);
    }

    /**
     * MAX-хиты: по умолчанию только самая длинная серия (MAX_ONLY_LONGEST_SERIES).
     *
     * @param  list<float>  $histBodies
     * @param  list<int>  $histDirs
     * @param  list<int>  $recentDirs
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private static function findMaxHits(array $histBodies, array $histDirs, float $evalBody, array $recentDirs, array $config): array
    {
        $currentDir = $recentDirs[array_key_last($recentDirs)] ?? 0;
        if ($currentDir === 0) {
            return [];
        }
        $sameColor = 0;
        for ($i = count($recentDirs) - 1; $i >= 0; $i--) {
            if ($recentDirs[$i] !== $currentDir) {
                break;
            }
            $sameColor++;
        }
        if ($sameColor < 1) {
            return [];
        }

        $onlyLongest = ImpulseLvl1minConfig::flag($config, 'max_only_longest', true);
        $hits = [];
        for ($n = $sameColor; $n >= 1; $n--) {
            $ratio = self::maxRatioForN($histBodies, $histDirs, $evalBody, $n, $config);
            if ($ratio === null) {
                continue;
            }
            $id = $n === 1 ? 'MAX' : 'MAX'.$n;
            $hits[] = [
                'id' => $id,
                'kind' => 'max',
                'label' => $id,
                'emoji' => '',
                'candle_count' => $n,
                'value' => $ratio,
            ];
            if ($onlyLongest) {
                break;
            }
        }

        return $hits;
    }

    /**
     * Все k=1..N должны бить свой порог; эталон — HISTORY_PERIOD закрытых без хвоста N-1.
     *
     * @param  list<float>  $histBodies
     * @param  list<int>  $histDirs
     * @param  array<string, mixed>  $config
     */
    private static function maxRatioForN(array $histBodies, array $histDirs, float $evalBody, int $n, array $config): ?float
    {
        $sCurr = $evalBody;
        if ($n > 1) {
            $tail = array_slice($histBodies, -($n - 1));
            $sCurr += array_sum($tail);
        }
        if ($sCurr <= self::EPSILON) {
            return null;
        }

        $end = count($histBodies);
        if ($n > 1) {
            $end -= ($n - 1);
        }
        $histPeriod = ImpulseLvl1minConfig::historyPeriod($config);
        $start = max(0, $end - $histPeriod);
        $histN = $end - $start;
        $targetRatio = 0.0;

        for ($k = 1; $k <= $n; $k++) {
            $percent = ImpulseLvl1minConfig::maxPercentForLength($k, $config);
            $maxPrev = 0.0;
            if ($histN >= $k) {
                $lastStart = $start + $histN - $k;
                for ($i = $start; $i <= $lastStart; $i++) {
                    $firstDir = $histDirs[$i];
                    if ($firstDir === 0) {
                        continue;
                    }
                    $sum = $histBodies[$i];
                    $same = true;
                    for ($j = 1; $j < $k; $j++) {
                        if ($histDirs[$i + $j] !== $firstDir) {
                            $same = false;
                            break;
                        }
                        $sum += $histBodies[$i + $j];
                    }
                    if ($same && $sum > $maxPrev) {
                        $maxPrev = $sum;
                    }
                }
            }
            if ($maxPrev <= self::EPSILON) {
                if ($k === $n) {
                    return null;
                }

                continue;
            }
            if ($sCurr + self::EPSILON < $maxPrev * ($percent / 100.0)) {
                return null;
            }
            if ($k === $n) {
                $targetRatio = ($sCurr / $maxPrev) * 100.0;
            }
        }

        return $targetRatio > self::EPSILON ? $targetRatio : null;
    }

    /**
     * Все включённые профили, у которых сейчас совпало хотя бы одно правило N (длинные N раньше, как в 1min).
     *
     * @param  list<float>  $recentBodies
     * @param  list<int>  $recentDirs
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private static function findProfileHits(array $recentBodies, array $recentDirs, float $mean, array $config): array
    {
        $hits = [];
        foreach (ImpulseLvl1minConfig::enabledProfiles($config) as $profile) {
            $matched = self::matchProfileMultipliers($recentBodies, $recentDirs, $mean, $profile);
            if ($matched === null) {
                continue;
            }
            $hits[] = [
                'id' => (string) $profile['id'],
                'kind' => 'profile',
                'label' => (string) ($profile['label'] ?? $profile['id']),
                'emoji' => (string) ($profile['emoji'] ?? ''),
                'candle_count' => (int) $matched['candle_count'],
                'value' => $matched['value'],
                'needs_lvl' => (bool) ($profile['filter_enabled'] ?? true),
            ];
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{value:float,candle_count:int}|null
     */
    private static function matchProfileMultipliers(array $recentBodies, array $recentDirs, float $mean, array $profile): ?array
    {
        $multipliers = ImpulseLvl1minConfig::profileMultipliers($profile);
        $body = $recentBodies[array_key_last($recentBodies)] ?? 0.0;
        $multiCounts = [];
        $single = null;
        foreach ($multipliers as $count => $mult) {
            if ((int) $count > 1) {
                $multiCounts[(int) $count] = (float) $mult;
            } else {
                $single = (float) $mult;
            }
        }
        krsort($multiCounts, SORT_NUMERIC);
        foreach ($multiCounts as $count => $mult) {
            if (count($recentBodies) < $count || count($recentDirs) < $count) {
                continue;
            }
            $dirs = array_slice($recentDirs, -$count);
            $first = $dirs[0];
            if ($first === 0) {
                continue;
            }
            $same = true;
            foreach ($dirs as $d) {
                if ($d !== $first) {
                    $same = false;
                    break;
                }
            }
            $sum = array_sum(array_slice($recentBodies, -$count));
            if ($same && $sum + self::EPSILON >= $mean * $mult) {
                return ['value' => $sum / $mean, 'candle_count' => (int) $count];
            }
        }
        if ($single !== null && $body + self::EPSILON >= $mean * $single) {
            return ['value' => $body / $mean, 'candle_count' => 1];
        }

        return null;
    }

    /**
     * @param  array{open?:float,close?:float}  $candle
     */
    public static function candleBody(array $candle): float
    {
        return abs((float) ($candle['close'] ?? 0) - (float) ($candle['open'] ?? 0));
    }

    /**
     * 1 бычья, -1 медвежья, 0 доджи (рвёт серию).
     *
     * @param  array{open?:float,close?:float}  $candle
     */
    public static function candleDirection(array $candle): int
    {
        $body = (float) ($candle['close'] ?? 0) - (float) ($candle['open'] ?? 0);
        if (abs($body) <= self::EPSILON) {
            return 0;
        }

        return $body > 0 ? 1 : -1;
    }
}
