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
     * @return array{ok:bool,labels:list<string>,tf_seconds:list<int>,zones:list<array<string,mixed>>}
     */
    public static function match(array $m1Closed, array $evalCandle, array $mtf, int $direction, array $config = []): array
    {
        $zones = self::matchedZones($m1Closed, $evalCandle, $mtf, $direction, $config);
        if ($zones === []) {
            return ['ok' => false, 'labels' => [], 'tf_seconds' => [], 'zones' => []];
        }

        $labels = [];
        $seconds = [];
        foreach ($zones as $zone) {
            $seconds[] = (int) $zone['tf_seconds'];
            $labels[] = (string) $zone['tf'];
        }
        $labels = array_values(array_unique($labels));
        sort($seconds);

        return ['ok' => true, 'labels' => $labels, 'tf_seconds' => array_values(array_unique($seconds)), 'zones' => $zones];
    }

    /**
     * Точные swing-зоны, прошедшие LVL-фильтр (как matched_signal_levels в updateBotM1).
     * zone_min/max уже с allowance — готовы к отрисовке на Telegram-графике.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1Closed
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @param  array<string, list<array<string, mixed>>>  $mtf
     * @param  array<string, mixed>  $config
     * @return list<array{
     *   tf:string,
     *   tf_seconds:int,
     *   timestamp:int,
     *   price:float,
     *   zone_min:float,
     *   zone_max:float
     * }>
     */
    public static function matchedZones(array $m1Closed, array $evalCandle, array $mtf, int $direction, array $config = []): array
    {
        if ($direction === 0) {
            return [];
        }

        $found = [];
        $m1ForBreak = $m1Closed;
        $m1ForBreak[] = $evalCandle;
        $ladder = ImpulseLvl1minConfig::lvlTfLadder($config);
        $baseSec = ImpulseLvl1minConfig::barPeriodSec($config);

        foreach ($ladder as $tfId => $tfSeconds) {
            if ($tfSeconds !== $baseSec) {
                continue;
            }
            foreach (self::matchingOnSeries($m1Closed, $m1ForBreak, $tfId, $tfSeconds, $evalCandle, $direction, $config) as $hit) {
                $found[] = $hit;
            }
        }

        // Как 1min LEVEL_UPDATE_HIGHER_TF. Выкл. в настройках — только закрытые HTF из seriesWithMtf.
        if (ImpulseLvl1minConfig::flag($config, 'overlay_forming_htf', true)) {
            $mtf = self::overlayFormingHtfFromM1($m1ForBreak, $mtf, $config);
        }

        foreach ($ladder as $tfId => $tfSeconds) {
            if ($tfSeconds === $baseSec) {
                continue;
            }
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
            return [];
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

        $out = [];
        foreach ($accepted as $lvl) {
            $tfSeconds = (int) $lvl['timeframe'];
            $out[] = [
                'tf' => self::tfLabel($tfSeconds),
                'tf_seconds' => $tfSeconds,
                'timestamp' => (int) $lvl['timestamp'],
                'price' => (float) $lvl['price'],
                'zone_min' => (float) $lvl['zone_min'],
                'zone_max' => (float) $lvl['zone_max'],
            ];
        }

        return $out;
    }

    /**
     * Направление поиска LVL по цвету импульса (не по CALL/PUT).
     *
     * @param  array<string, mixed>  $features
     * @param  array{time?:int,open?:float,high?:float,low?:float,close?:float}  $evalCandle
     */
    public static function chartDirectionFromFeatures(array $features, array $evalCandle): int
    {
        $fromColor = ImpulseLvl1minConfig::impulseDirectionFromColor((string) ($features['impulse_color'] ?? ''));
        if ($fromColor !== 0) {
            return $fromColor;
        }
        $open = (float) ($evalCandle['open'] ?? 0);
        $close = (float) ($evalCandle['close'] ?? 0);

        return $close <=> $open;
    }

    /**
     * Зоны для PNG: сначала те, что уже прошли фильтр сигнала; иначе пересчёт по цвету импульса.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1Closed
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @param  array<string, list<array<string, mixed>>>  $mtf
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    public static function zonesForTelegramChart(
        array $m1Closed,
        array $evalCandle,
        array $mtf,
        array $features,
        array $config,
    ): array {
        $stored = is_array($features['lvl_zones'] ?? null) ? $features['lvl_zones'] : [];
        if ($stored !== []) {
            return array_values($stored);
        }
        $dir = self::chartDirectionFromFeatures($features, $evalCandle);
        if ($dir === 0) {
            return [];
        }

        return self::matchedZones($m1Closed, $evalCandle, $mtf, $dir, $config);
    }

    /**
     * Непробитые LVL-зоны обоих направлений (для LLM и chart_levels), без фильтра «цена уже в зоне».
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1Closed
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @param  array<string, list<array<string, mixed>>>  $mtf
     * @param  array<string, mixed>  $config
     * @return list<array{
     *   tf:string,
     *   tf_seconds:int,
     *   side:string,
     *   kind:string,
     *   price:float,
     *   zone_min:float,
     *   zone_max:float,
     *   time:int,
     *   touching:bool
     * }>
     */
    public static function activeZones(array $m1Closed, array $evalCandle, array $mtf, array $config = []): array
    {
        $found = [];
        $m1ForBreak = $m1Closed;
        $m1ForBreak[] = $evalCandle;
        $ladder = ImpulseLvl1minConfig::lvlTfLadder($config);
        $baseSec = ImpulseLvl1minConfig::barPeriodSec($config);

        foreach ($ladder as $tfId => $tfSeconds) {
            if ($tfSeconds !== $baseSec) {
                continue;
            }
            foreach (self::unbrokenOnSeries($m1Closed, $m1ForBreak, $tfId, $tfSeconds, $evalCandle, $config) as $zone) {
                $found[] = $zone;
            }
        }

        if (ImpulseLvl1minConfig::flag($config, 'overlay_forming_htf', true)) {
            $mtf = self::overlayFormingHtfFromM1($m1ForBreak, $mtf, $config);
        }

        foreach ($ladder as $tfId => $tfSeconds) {
            if ($tfSeconds === $baseSec) {
                continue;
            }
            $series = is_array($mtf[(string) $tfId] ?? null) ? array_values($mtf[(string) $tfId]) : [];
            $normalized = self::normalizeSeries($series);
            if ($normalized === []) {
                continue;
            }
            foreach (self::unbrokenOnSeries($normalized, $normalized, $tfId, $tfSeconds, $evalCandle, $config) as $zone) {
                $found[] = $zone;
            }
        }

        usort($found, static function (array $a, array $b): int {
            $tf = ((int) $b['tf_seconds']) <=> ((int) $a['tf_seconds']);
            if ($tf !== 0) {
                return $tf;
            }

            return ((int) $b['time']) <=> ((int) $a['time']);
        });

        return $found;
    }

    /**
     * Хвост HTF из M1 до eval: последние 2 бакета ТФ, как _merge_recent_higher_timeframe_tail.
     * Закрытые бакеты из seriesWithMtf не трогаем; подменяем только текущий/предыдущий.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1ThroughEval
     * @param  array<string, list<array<string, mixed>>>  $mtf
     * @param  array<string, mixed>  $config
     * @return array<string, list<array<string, mixed>>>
     */
    public static function overlayFormingHtfFromM1(array $m1ThroughEval, array $mtf, array $config = []): array
    {
        $lastTime = 0;
        foreach ($m1ThroughEval as $row) {
            $lastTime = max($lastTime, (int) ($row['time'] ?? 0));
        }
        if ($lastTime <= 0) {
            return $mtf;
        }

        $htf = ImpulseLvl1minConfig::htfOverlayLadder($config);
        if ($htf === []) {
            $htf = [5 => 300, 15 => 900, 30 => 1800];
        }

        foreach ($htf as $tfId => $tfSeconds) {
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
        $tfSeconds = max(15, $tfSeconds);
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
     * @return list<array{timestamp:int,timeframe:int,price:float,zone_min:float,zone_max:float}>
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
            // Как в updateBotM1 render_signal_chart: зона на PNG уже с allowance.
            $hits[] = [
                'timestamp' => (int) $level['time'],
                'timeframe' => $tfSeconds,
                'price' => (float) $level['price'],
                'zone_min' => $lo,
                'zone_max' => $hi,
            ];
        }

        return $hits;
    }

    /**
     * Непробитые swing-зоны одного ТФ, оба направления; не больше 5 на сторону.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $forSwings
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $forBreaks
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @return list<array{
     *   tf:string,
     *   tf_seconds:int,
     *   side:string,
     *   kind:string,
     *   price:float,
     *   zone_min:float,
     *   zone_max:float,
     *   time:int,
     *   touching:bool
     * }>
     */
    private static function unbrokenOnSeries(
        array $forSwings,
        array $forBreaks,
        int $dashboardTf,
        int $tfSeconds,
        array $evalCandle,
        array $config = [],
    ): array {
        $settings = ImpulseLvl1minConfig::levelSettingsForTf($dashboardTf, $config);
        $pivotLeft = (int) $settings['pivot_left'];
        $pivotRight = (int) $settings['pivot_right'];
        $allowance = self::allowance($forSwings, (float) $settings['distance_body_multiplier']);
        $evalHigh = (float) ($evalCandle['high'] ?? 0);
        $evalLow = (float) ($evalCandle['low'] ?? 0);
        $out = [];

        foreach ([1 => 'resistance', -1 => 'support'] as $direction => $kind) {
            $swings = $direction > 0
                ? self::findSwings($forSwings, $pivotLeft, $pivotRight, true)
                : self::findSwings($forSwings, $pivotLeft, $pivotRight, false);
            $kept = [];
            foreach (array_reverse($swings) as $level) {
                if (self::isBroken($level, $forBreaks, $direction, $tfSeconds, $pivotRight, $allowance, $settings)) {
                    continue;
                }
                [$zoneMin, $zoneMax] = self::zoneBounds($level, $direction, $settings);
                $lo = $zoneMin - $allowance;
                $hi = $zoneMax + $allowance;
                $price = $direction > 0 ? $evalHigh : $evalLow;
                $touching = ! ($price + self::EPSILON < $lo || $price - self::EPSILON > $hi);
                $kept[] = [
                    'tf' => self::tfLabel($tfSeconds),
                    'tf_seconds' => $tfSeconds,
                    'side' => $direction > 0 ? 'PUT' : 'CALL',
                    'kind' => $kind,
                    'price' => (float) $level['price'],
                    'zone_min' => $zoneMin,
                    'zone_max' => $zoneMax,
                    'time' => (int) $level['time'],
                    'touching' => $touching,
                ];
                if (count($kept) >= 5) {
                    break;
                }
            }
            foreach ($kept as $zone) {
                $out[] = $zone;
            }
        }

        return $out;
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
            15 => 'S15',
            60 => 'M1',
            300 => 'M5',
            900 => 'M15',
            1800 => 'M30',
            default => $tfSeconds < 60
                ? 'S'.$tfSeconds
                : 'M'.max(1, intdiv($tfSeconds, 60)),
        };
    }
}
