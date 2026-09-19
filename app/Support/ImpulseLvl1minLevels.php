<?php

namespace App\Support;

/**
 * Swing-зоны 1min: M1 body_to_wick + M5/M15/M30 wick_tip, касание high/low, снятие при пробое экстремумом.
 */
final class ImpulseLvl1minLevels
{
    private const EPSILON = 1e-12;

    /**
     * Есть ли активный LVL в сторону импульса и метки ТФ (M1 M5 …) после дедупа «старший побеждает».
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1Closed
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @param  array<string, list<array<string, mixed>>>  $mtf  ключи dashboard TF: 5/15/30
     * @param  array<string, mixed>  $config
     * @return array{ok:bool,labels:list<string>,tf_seconds:list<int>}
     */
    public static function match(array $m1Closed, array $evalCandle, array $mtf, int $direction, array $config = []): array
    {
        if ($direction === 0) {
            return ['ok' => false, 'labels' => [], 'tf_seconds' => []];
        }

        $found = [];
        $m1ForBreak = $m1Closed;
        $m1ForBreak[] = $evalCandle;
        $m1Hit = self::matchingOnSeries($m1Closed, $m1ForBreak, 1, 60, $evalCandle, $direction, $config);
        foreach ($m1Hit as $hit) {
            $found[] = $hit;
        }

        // Как 1min LEVEL_UPDATE_HIGHER_TF. Выкл. в настройках — только закрытые HTF из seriesWithMtf.
        if (ImpulseLvl1minConfig::flag($config, 'overlay_forming_htf', true)) {
            $mtf = self::overlayFormingHtfFromM1($m1ForBreak, $mtf);
        }

        foreach ([5 => 300, 15 => 900, 30 => 1800] as $tfId => $tfSeconds) {
            $series = is_array($mtf[(string) $tfId] ?? null) ? array_values($mtf[(string) $tfId]) : [];
            $normalized = self::normalizeSeries($series);
            if ($normalized === []) {
                continue;
            }
            foreach (self::matchingOnSeries($normalized, $normalized, $tfId, $tfSeconds, $evalCandle, $direction, $config) as $hit) {
                $found[] = $hit;
            }
        }

        if ($found === []) {
            return ['ok' => false, 'labels' => [], 'tf_seconds' => []];
        }

        usort($found, static function (array $a, array $b): int {
            $tf = $b['timeframe'] <=> $a['timeframe'];

            return $tf !== 0 ? $tf : ($b['timestamp'] <=> $a['timestamp']);
        });

        $accepted = [];
        foreach ($found as $lvl) {
            $dup = false;
            foreach ($accepted as $other) {
                if (abs($lvl['timestamp'] - $other['timestamp']) <= max($lvl['timeframe'], $other['timeframe'])) {
                    $dup = true;
                    break;
                }
            }
            if (! $dup) {
                $accepted[] = $lvl;
            }
        }

        $labels = [];
        $seconds = [];
        foreach ($accepted as $lvl) {
            $seconds[] = (int) $lvl['timeframe'];
            $labels[] = self::tfLabel((int) $lvl['timeframe']);
        }
        $labels = array_values(array_unique($labels));
        sort($seconds);

        return ['ok' => $accepted !== [], 'labels' => $labels, 'tf_seconds' => array_values(array_unique($seconds))];
    }

    /**
     * Хвост HTF из M1 до eval: последние 2 бакета ТФ, как _merge_recent_higher_timeframe_tail.
     * Закрытые бакеты из seriesWithMtf не трогаем; подменяем только текущий/предыдущий.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1ThroughEval
     * @param  array<string, list<array<string, mixed>>>  $mtf
     * @return array<string, list<array<string, mixed>>>
     */
    public static function overlayFormingHtfFromM1(array $m1ThroughEval, array $mtf): array
    {
        $lastTime = 0;
        foreach ($m1ThroughEval as $row) {
            $lastTime = max($lastTime, (int) ($row['time'] ?? 0));
        }
        if ($lastTime <= 0) {
            return $mtf;
        }

        foreach ([5 => 300, 15 => 900, 30 => 1800] as $tfId => $tfSeconds) {
            $tail = self::aggregateLatestHtfBuckets($m1ThroughEval, $tfSeconds, 2);
            if ($tail === []) {
                continue;
            }
            $key = (string) $tfId;
            $existing = self::normalizeSeries(is_array($mtf[$key] ?? null) ? $mtf[$key] : []);
            $replaceFrom = $tail[0]['time'];
            $merged = [];
            foreach ($existing as $bar) {
                if ($bar['time'] < $replaceFrom) {
                    $merged[$bar['time']] = $bar;
                }
            }
            foreach ($tail as $bar) {
                $merged[$bar['time']] = $bar;
            }
            ksort($merged, SORT_NUMERIC);
            $mtf[$key] = array_values($merged);
        }

        return $mtf;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    public static function aggregateLatestHtfBuckets(array $m1, int $tfSeconds, int $bucketCount): array
    {
        $tfSeconds = max(60, $tfSeconds);
        $bucketCount = max(1, $bucketCount);
        $lastTime = 0;
        foreach ($m1 as $row) {
            $lastTime = max($lastTime, (int) ($row['time'] ?? 0));
        }
        if ($lastTime <= 0) {
            return [];
        }
        $currentBucket = intdiv($lastTime, $tfSeconds) * $tfSeconds;
        $from = $currentBucket - ($tfSeconds * ($bucketCount - 1));
        $slice = [];
        foreach ($m1 as $row) {
            if ((int) ($row['time'] ?? 0) >= $from) {
                $slice[] = $row;
            }
        }

        return self::aggregateM1ToTf($slice, $tfSeconds);
    }

    /**
     * @param  list<array{time?:int,open?:float,high?:float,low?:float,close?:float}>  $m1
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    public static function aggregateM1ToTf(array $m1, int $tfSeconds): array
    {
        $tfSeconds = max(1, $tfSeconds);
        $buckets = [];
        foreach ($m1 as $row) {
            $time = (int) ($row['time'] ?? 0);
            if ($time <= 0) {
                continue;
            }
            $key = intdiv($time, $tfSeconds) * $tfSeconds;
            $open = (float) ($row['open'] ?? 0);
            $high = (float) ($row['high'] ?? 0);
            $low = (float) ($row['low'] ?? 0);
            $close = (float) ($row['close'] ?? 0);
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'time' => $key,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                ];
                continue;
            }
            $buckets[$key]['high'] = max($buckets[$key]['high'], $high);
            $buckets[$key]['low'] = min($buckets[$key]['low'], $low);
            $buckets[$key]['close'] = $close;
        }
        ksort($buckets, SORT_NUMERIC);

        return array_values($buckets);
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $forSwings
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $forBreaks
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @return list<array{timestamp:int,timeframe:int,price:float}>
     */
    private static function matchingOnSeries(
        array $forSwings,
        array $forBreaks,
        int $dashboardTf,
        int $tfSeconds,
        array $evalCandle,
        int $direction,
        array $config = [],
    ): array {
        $settings = ImpulseLvl1minConfig::levelSettingsForTf($dashboardTf, $config);
        $pivotLeft = (int) $settings['pivot_left'];
        $pivotRight = (int) $settings['pivot_right'];
        $allowance = self::allowance($forSwings, (float) $settings['distance_body_multiplier']);
        $swings = $direction > 0
            ? self::findSwings($forSwings, $pivotLeft, $pivotRight, true)
            : self::findSwings($forSwings, $pivotLeft, $pivotRight, false);

        $price = $direction > 0 ? (float) $evalCandle['high'] : (float) $evalCandle['low'];
        $hits = [];
        foreach ($swings as $level) {
            if (self::isBroken($level, $forBreaks, $direction, $tfSeconds, $pivotRight, $allowance, $settings)) {
                continue;
            }
            [$zoneMin, $zoneMax] = self::zoneBounds($level, $direction, $settings);
            $lo = $zoneMin - $allowance;
            $hi = $zoneMax + $allowance;
            if ($price + self::EPSILON < $lo || $price - self::EPSILON > $hi) {
                continue;
            }
            $hits[] = [
                'timestamp' => (int) $level['time'],
                'timeframe' => $tfSeconds,
                'price' => (float) $level['price'],
            ];
        }

        return $hits;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $candles
     * @return list<array{time:int,open:float,high:float,low:float,close:float,price:float}>
     */
    public static function findSwings(array $candles, int $pivotLeft, int $pivotRight, bool $highs): array
    {
        $pivotLeft = max(1, $pivotLeft);
        $pivotRight = max(1, $pivotRight);
        $n = count($candles);
        if ($n < $pivotLeft + $pivotRight + 1) {
            return [];
        }

        $out = [];
        for ($i = $pivotLeft; $i < $n - $pivotRight; $i++) {
            $value = $highs ? (float) $candles[$i]['high'] : (float) $candles[$i]['low'];
            $ok = true;
            for ($j = $i - $pivotLeft; $j < $i; $j++) {
                $other = $highs ? (float) $candles[$j]['high'] : (float) $candles[$j]['low'];
                if ($highs ? $value <= $other : $value >= $other) {
                    $ok = false;
                    break;
                }
            }
            if (! $ok) {
                continue;
            }
            for ($j = $i + 1; $j <= $i + $pivotRight; $j++) {
                $other = $highs ? (float) $candles[$j]['high'] : (float) $candles[$j]['low'];
                if ($highs ? $value < $other : $value > $other) {
                    $ok = false;
                    break;
                }
            }
            if (! $ok) {
                continue;
            }
            $c = $candles[$i];
            $out[] = [
                'time' => (int) $c['time'],
                'open' => (float) $c['open'],
                'high' => (float) $c['high'],
                'low' => (float) $c['low'],
                'close' => (float) $c['close'],
                'price' => $value,
            ];
        }

        return $out;
    }

    /**
     * @param  array{open:float,high:float,low:float,close:float,price?:float}  $level
     * @param  array{pivot_left:int,pivot_right:int,zone_mode:string,wick_tip_fraction:float,distance_body_multiplier:float}  $settings
     * @return array{0:float,1:float}
     */
    public static function zoneBounds(array $level, int $direction, array $settings): array
    {
        $open = (float) $level['open'];
        $close = (float) $level['close'];
        $high = (float) $level['high'];
        $low = (float) $level['low'];
        $bodyLow = min($open, $close);
        $bodyHigh = max($open, $close);
        $isUpper = $direction > 0;
        $mode = (string) ($settings['zone_mode'] ?? 'body_to_wick');
        if ($mode === 'wick_tip') {
            $fraction = min(1.0, max(0.0, (float) ($settings['wick_tip_fraction'] ?? 0.25)));
            if ($isUpper) {
                return [$high - (($high - $bodyHigh) * $fraction), $high];
            }

            return [$low, $low + (($bodyLow - $low) * $fraction)];
        }
        if ($isUpper) {
            return [$bodyHigh, $high];
        }

        return [$low, $bodyLow];
    }

    /**
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $level
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $candles
     * @param  array{zone_mode:string,wick_tip_fraction:float}  $settings
     */
    private static function isBroken(
        array $level,
        array $candles,
        int $direction,
        int $tfSeconds,
        int $pivotRight,
        float $allowance,
        array $settings,
    ): bool {
        $fixation = (int) $level['time'] + ($pivotRight * $tfSeconds);
        [$zoneMin, $zoneMax] = self::zoneBounds($level, $direction, $settings);
        $breakAbove = $zoneMax + $allowance;
        $breakBelow = $zoneMin - $allowance;
        foreach ($candles as $c) {
            if ((int) $c['time'] <= $fixation) {
                continue;
            }
            if ($direction > 0) {
                if ((float) $c['high'] > $breakAbove) {
                    return true;
                }
            } elseif ((float) $c['low'] < $breakBelow) {
                return true;
            }
        }

        return false;
    }

    /**
     * Медиана тела × коэффициент (как typical_level_body в 1min).
     *
     * @param  list<array{open?:float,close?:float}>  $candles
     */
    public static function allowance(array $candles, float $multiplier, int $period = 50): float
    {
        $slice = array_slice($candles, -$period);
        $bodies = [];
        foreach ($slice as $c) {
            $body = abs((float) ($c['close'] ?? 0) - (float) ($c['open'] ?? 0));
            if ($body > self::EPSILON) {
                $bodies[] = $body;
            }
        }
        if ($bodies === []) {
            return 0.0;
        }
        sort($bodies);
        $n = count($bodies);
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $bodies[$mid] : ($bodies[$mid - 1] + $bodies[$mid]) / 2.0;

        return $median * max(0.0, $multiplier);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    private static function normalizeSeries(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $time = (int) ($row['time'] ?? 0);
            if ($time <= 0) {
                continue;
            }
            $out[] = [
                'time' => $time,
                'open' => (float) ($row['open'] ?? 0),
                'high' => (float) ($row['high'] ?? 0),
                'low' => (float) ($row['low'] ?? 0),
                'close' => (float) ($row['close'] ?? 0),
            ];
        }

        return $out;
    }

    public static function tfLabel(int $tfSeconds): string
    {
        return match ($tfSeconds) {
            60 => 'M1',
            300 => 'M5',
            900 => 'M15',
            1800 => 'M30',
            default => 'M'.max(1, intdiv($tfSeconds, 60)),
        };
    }
}
