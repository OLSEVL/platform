<?php

namespace App\Services\Indicators;

use App\Services\CandleService;

/**
 * Порт индикатора Pine "Price Action - Support & Resistance by DGT" (strategy1.txt).
 * Считает S/R, volume spike, ATR-волатильность, volume profile, supply/demand, VWCB и маркеры.
 * Возвращает структуру для отрисовки на lightweight-charts (оверлей + опционально цвета свечей).
 */
class PriceActionDgtIndicatorService
{
    public const ID = 'tv.price_action_dgt';

    /** Дефолты совпадают с Pine-скриптом (strategy1.txt). */
    public static function defaultParams(): array
    {
        return [
            // Важно: sr_fixed_lookback_bars учитывается только если здесь режим fixed
            // (строка содержит "fixed", напр. "fixed" или как в Pine "Fixed Range").
            'sr_lookback_range' => 'visible',
            'sr_fixed_lookback_bars' => 360,
            'sr_source' => 'volume', // volume | price
            'sr_lines' => true,
            'sr_line_color' => '#4dd0e141',
            'sr_line_width' => 3,
            'sr_line_style' => 'Solid',

            'v_spike_markers' => true,
            'v_spike_threshold' => 4.669,
            'v_spike_lines' => true,
            'v_spike_line_color' => '#ffb74d69',
            'v_spike_line_width' => 3,
            'v_spike_line_style' => 'Solid',
            'v_spike_bull_level' => 'Both', // High | Close | Both
            'v_spike_bear_level' => 'Both',

            'atr_markers' => true,
            'atr_length' => 11,
            'atr_mult' => 2.718,
            'atr_lines' => true,
            'atr_line_color' => '#81c78455',
            'atr_line_width' => 3,
            'atr_line_style' => 'Solid',
            'atr_bull_level' => 'Both',
            'atr_bear_level' => 'Both',

            'volume_sma_length' => 89,

            'volume_profile' => true,
            // vp_fixed_lookback_bars только при режиме fixed (строка с подстрокой "fixed").
            'vp_lookback_range' => 'visible',
            'vp_fixed_lookback_bars' => 360,
            'vp_profile_levels' => 100,
            'vp_horizontal_offset_bars' => 13,
            'vp_value_area_pct' => 68,
            'vp_vah' => true,
            'vp_vah_color' => '#ffeb3b',
            'vp_poc' => true,
            'vp_poc_color' => '#ff0000',
            'vp_val' => true,
            'vp_val_color' => '#ffeb3b',
            'vp_price_levels' => true,
            'vp_label_color' => '#8c92a4',
            'vp_avg_node_color' => '#787b8640',
            'vp_high_node_color' => '#f57c0080',
            'vp_low_node_color' => '#787b86bf',

            'supply_demand' => true,
            'sd_low_vol_ratio_pct' => 15,
            'sd_zone_color' => '#512da8cc',

            'vwcb' => true,
            'vwcb_high' => 1.618,
            'vwcb_low' => 0.618,
        ];
    }

    /**
     * @param  list<array{time: int, open: float, high: float, low: float, close: float, volume?: float}>  $candles
     * @param  array<string, mixed>  $params
     * @param  int  $barSeconds  длительность одного бара в секундах (для fixed lookback в «времени» как в Pine)
     * @return array<string, mixed>
     */
    public function compute(array $candles, array $params, int $barSeconds = 60): array
    {
        $p = array_replace(self::defaultParams(), $params);
        $n = count($candles);
        if ($n < 3) {
            return $this->emptyPayload();
        }

        $open = [];
        $high = [];
        $low = [];
        $close = [];
        $vol = [];
        $time = [];
        foreach ($candles as $i => $c) {
            $open[$i] = (float) $c['open'];
            $high[$i] = (float) $c['high'];
            $low[$i] = (float) $c['low'];
            $close[$i] = (float) $c['close'];
            $vol[$i] = (float) ($c['volume'] ?? 0.0);
            $time[$i] = (int) $c['time'];
        }

        $vSmaLen = max(1, (int) $p['volume_sma_length']);
        $vSma = $this->smaSeries($vol, $vSmaLen);

        $bull = [];
        $bear = [];
        for ($i = 0; $i < $n; $i++) {
            $bull[$i] = $close[$i] > $open[$i];
            $bear[$i] = $close[$i] < $open[$i];
        }

        $risingPrice = [];
        $fallingPrice = [];
        for ($i = 0; $i < $n; $i++) {
            $risingPrice[$i] = $i > 0 && $close[$i] > $close[$i - 1];
            $fallingPrice[$i] = $i > 0 && $close[$i] < $close[$i - 1];
        }

        $risingVol = [];
        for ($i = 0; $i < $n; $i++) {
            $risingVol[$i] = $i > 0 && $vol[$i] >= $vol[$i - 1];
        }

        $lwst3 = [];
        $hst3 = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i < 2) {
                $lwst3[$i] = $low[$i];
                $hst3[$i] = $high[$i];
            } else {
                $lwst3[$i] = min($low[$i], $low[$i - 1], $low[$i - 2]);
                $hst3[$i] = max($high[$i], $high[$i - 1], $high[$i - 2]);
            }
        }

        $atr = $this->atrWilder($high, $low, $close, (int) $p['atr_length']);
        $atrMult = (float) $p['atr_mult'];
        $weightedAtr = [];
        $range1 = [];
        for ($i = 0; $i < $n; $i++) {
            $range1[$i] = abs($high[$i] - $low[$i]);
            $weightedAtr[$i] = $atr[$i] !== null ? $atrMult * $atr[$i] : null;
        }

        $now = time();
        $sProcessing = $this->computeSProcessing(
            $this->lookbackRangeIsFixed($p['sr_lookback_range'] ?? ''),
            (int) $p['sr_fixed_lookback_bars'],
            $barSeconds,
            $time,
            $now,
            $n
        );

        $falling = [];
        $rising = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i < 2) {
                $falling[$i] = false;
                $rising[$i] = false;

                continue;
            }
            if ($p['sr_source'] === 'volume') {
                $falling[$i] = $bear[$i] && $bear[$i - 1] && $bear[$i - 2]
                    && $vol[$i] > ($vSma[$i] ?? 0)
                    && $risingVol[$i] && $risingVol[$i - 1];
                $rising[$i] = $bull[$i] && $bull[$i - 1] && $bull[$i - 2]
                    && $vol[$i] > ($vSma[$i] ?? 0)
                    && $risingVol[$i] && $risingVol[$i - 1];
            } else {
                $falling[$i] = $bear[$i] && $bear[$i - 1] && $bear[$i - 2]
                    && $fallingPrice[$i] && $fallingPrice[$i - 1] && $fallingPrice[$i - 2];
                $rising[$i] = $bull[$i] && $bull[$i - 1] && $bull[$i - 2]
                    && $risingPrice[$i] && $risingPrice[$i - 1] && $risingPrice[$i - 2];
            }
        }

        $condSr = [];
        for ($i = 0; $i < $n; $i++) {
            $condSr[$i] = $falling[$i] || $rising[$i];
        }

        $lastTime = $time[$n - 1];
        $lines = [];

        // S/R: как в Pine — при подряд одинаковых falling/rising удаляем предыдущий сегмент.
        $lines = $this->buildSrLinesLikePine(
            $n,
            $time,
            $lastTime,
            $sProcessing,
            $falling,
            $rising,
            $condSr,
            $lwst3,
            $hst3,
            (bool) $p['sr_lines'],
            (string) $p['sr_line_color'],
            (int) $p['sr_line_width'],
            (string) $p['sr_line_style']
        );

        $exhaustVol = [];
        for ($i = 0; $i < $n; $i++) {
            $vs = $vSma[$i];
            $exhaustVol[$i] = $vs !== null && $vol[$i] > ((float) $p['v_spike_threshold'] * $vs);
        }

        $highVol = [];
        for ($i = 0; $i < $n; $i++) {
            $highVol[$i] = $weightedAtr[$i] !== null && $range1[$i] > $weightedAtr[$i];
        }

        if ($p['v_spike_lines']) {
            $lines = array_merge($lines, $this->buildSpikeOrAtrLines(
                $n,
                $time,
                $lastTime,
                $sProcessing,
                $exhaustVol,
                $bull,
                $bear,
                true,
                (string) $p['v_spike_bull_level'],
                (string) $p['v_spike_bear_level'],
                (string) $p['v_spike_line_color'],
                (int) $p['v_spike_line_width'],
                (string) $p['v_spike_line_style'],
                $high,
                $low,
                $close
            ));
        }

        if ($p['atr_lines']) {
            $lines = array_merge($lines, $this->buildSpikeOrAtrLines(
                $n,
                $time,
                $lastTime,
                $sProcessing,
                $highVol,
                $bull,
                $bear,
                false,
                (string) $p['atr_bull_level'],
                (string) $p['atr_bear_level'],
                (string) $p['atr_line_color'],
                (int) $p['atr_line_width'],
                (string) $p['atr_line_style'],
                $high,
                $low,
                $close
            ));
        }

        $markers = [];
        for ($i = 0; $i < $n; $i++) {
            if (! $sProcessing[$i]) {
                continue;
            }
            if ($p['v_spike_markers'] && $exhaustVol[$i]) {
                $markers[] = [
                    'time' => $time[$i],
                    'position' => 'aboveBar',
                    'color' => '#f59e0b',
                    'shape' => 'circle',
                    'text' => '!',
                ];
            }
            if ($p['atr_markers'] && $highVol[$i]) {
                $markers[] = [
                    'time' => $time[$i],
                    'position' => 'belowBar',
                    'color' => '#22c55e',
                    'shape' => 'circle',
                    'text' => 'V',
                ];
            }
        }

        $barColors = [];
        if ($p['vwcb']) {
            for ($i = 0; $i < $n; $i++) {
                $vs = $vSma[$i];
                if ($vs === null) {
                    continue;
                }
                $hiT = (float) $p['vwcb_high'];
                $loT = (float) $p['vwcb_low'];
                if ($vol[$i] > $vs * $hiT) {
                    $c = $bear[$i] ? '#910000' : '#006400';
                    $barColors[(string) $time[$i]] = ['color' => $c, 'borderColor' => $c, 'wickColor' => $c];
                } elseif ($vol[$i] < $vs * $loT) {
                    $c = $bear[$i] ? '#FF9800' : '#7FFFD4';
                    $barColors[(string) $time[$i]] = ['color' => $c, 'borderColor' => $c, 'wickColor' => $c];
                }
            }
        }

        $vpPayload = ['lines' => [], 'boxes' => [], 'labels' => [], 'meta' => []];
        if ($p['volume_profile'] || $p['supply_demand']) {
            $vpPayload = $this->computeVolumeProfileBlock(
                $candles,
                $p,
                $open,
                $high,
                $low,
                $close,
                $vol,
                $time,
                $bull,
                $n,
                $barSeconds
            );
            $lines = array_merge($lines, $vpPayload['lines'] ?? []);
        }

        return [
            'id' => self::ID,
            'version' => 1,
            'lines' => array_slice($lines, 0, 500),
            'markers' => $markers,
            'bar_colors' => $barColors,
            'volume_profile' => $vpPayload,
            'meta' => [
                'bars' => $n,
                'bar_seconds' => $barSeconds,
            ],
        ];
    }

    /**
     * Построение S/R сегментов: на каждом баре при новом сигнале — новая линия; при подряд одинаковых — замена последней.
     *
     * @param  array<int, bool>  $sProcessing
     * @param  array<int, bool>  $falling
     * @param  array<int, bool>  $rising
     * @param  array<int, bool>  $condSr
     * @return list<array<string, mixed>>
     */
    private function buildSrLinesLikePine(
        int $n,
        array $time,
        int $lastTime,
        array $sProcessing,
        array $falling,
        array $rising,
        array $condSr,
        array $lwst3,
        array $hst3,
        bool $enabled,
        string $color,
        int $width,
        string $style
    ): array {
        if (! $enabled) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if (! $sProcessing[$i]) {
                continue;
            }
            // Якорь уровня только среди баров в «рабочем» окне (sProcessing), иначе при fixed
            // lookback линия тянулась от сигналов за пределами последних N баров (как valuewhen по всей истории).
            $j = $this->lastTrueIndexInWindow($condSr, $sProcessing, $i);
            if ($j === null) {
                continue;
            }
            // Pine: y/x1 от последнего бара, где было falling|rising (valuewhen occurrence 0).
            $y = $falling[$j] ? $lwst3[$j] : $hst3[$j];
            $x1 = $time[$j];

            if ($falling[$i]) {
                if ($i > 0 && $falling[$i - 1] && $falling[$i]) {
                    array_pop($out);
                }
                $out[] = [
                    'source' => 'sr',
                    'time_start' => $x1,
                    'time_end' => $lastTime,
                    'price' => round($y, 8),
                    'color' => $color,
                    'width' => $width,
                    'style' => $style,
                ];
            } elseif ($rising[$i]) {
                if ($i > 0 && $rising[$i - 1] && $rising[$i]) {
                    array_pop($out);
                }
                $out[] = [
                    'source' => 'sr',
                    'time_start' => $x1,
                    'time_end' => $lastTime,
                    'price' => round($y, 8),
                    'color' => $color,
                    'width' => $width,
                    'style' => $style,
                ];
            }
        }

        return $out;
    }

    /**
     * Spike или ATR линии по тем же правилам уровней, что в Pine.
     *
     * @param  array<int, bool>  $flag  exhaustVol или highVol
     * @return list<array<string, mixed>>
     */
    private function buildSpikeOrAtrLines(
        int $n,
        array $time,
        int $lastTime,
        array $sProcessing,
        array $flag,
        array $bull,
        array $bear,
        bool $isSpike,
        string $bullLevel,
        string $bearLevel,
        string $color,
        int $width,
        string $style,
        array $high,
        array $low,
        array $close
    ): array {
        $out = [];
        $bullLevel = $this->normalizeLevel($bullLevel);
        $bearLevel = $this->normalizeLevel($bearLevel);
        $src = $isSpike ? 'volume_spike' : 'high_volatility';
        $nPopBull = $bullLevel === 'Both' ? 3 : 1;
        $nPopBear = $bearLevel === 'Both' ? 3 : 1;

        for ($i = 0; $i < $n; $i++) {
            if (! $sProcessing[$i] || ! $flag[$i]) {
                continue;
            }
            // Pine: при подряд одинаковых условиях удаляет предыдущую линию (одну или три в режиме Both).
            if ($i > 0 && $flag[$i] && $flag[$i - 1]) {
                if ($bull[$i] && $bull[$i - 1]) {
                    for ($k = 0; $k < $nPopBull; $k++) {
                        $this->popLastMatchingSource($out, $src);
                    }
                } elseif ($bear[$i] && $bear[$i - 1]) {
                    for ($k = 0; $k < $nPopBear; $k++) {
                        $this->popLastMatchingSource($out, $src);
                    }
                }
            }

            $x1 = $time[$i];
            if ($bull[$i]) {
                $levels = $this->bullLevels($bullLevel, $high[$i], $close[$i]);
                foreach ($levels as $idx => $price) {
                    $out[] = [
                        'source' => $src,
                        'time_start' => $x1,
                        'time_end' => $lastTime,
                        'price' => round($price, 8),
                        'color' => $color,
                        'width' => $idx === 1 ? max(1, $width - 1) : $width,
                        'style' => $idx === 1 ? 'Dotted' : $style,
                    ];
                }
            } elseif ($bear[$i]) {
                $levels = $this->bearLevels($bearLevel, $low[$i], $close[$i]);
                foreach ($levels as $idx => $price) {
                    $out[] = [
                        'source' => $src,
                        'time_start' => $x1,
                        'time_end' => $lastTime,
                        'price' => round($price, 8),
                        'color' => $color,
                        'width' => $idx === 1 ? max(1, $width - 1) : $width,
                        'style' => $idx === 1 ? 'Dotted' : $style,
                    ];
                }
            }
        }

        return array_slice($out, 0, 500);
    }

    /**
     * @param  list<array<string, mixed>>  $out
     */
    private function popLastMatchingSource(array &$out, string $src): void
    {
        for ($k = count($out) - 1; $k >= 0; $k--) {
            if (($out[$k]['source'] ?? '') === $src) {
                array_splice($out, $k, 1);

                return;
            }
        }
    }

    private function normalizeLevel(string $s): string
    {
        $s = strtolower(trim($s));

        return match ($s) {
            'high', 'low', 'close', 'both' => ucfirst($s),
            default => 'Both',
        };
    }

    /** @return list<float> */
    private function bullLevels(string $mode, float $high, float $close): array
    {
        return match ($mode) {
            'High' => [$high],
            'Close' => [$close],
            default => [$close, ($high + $close) / 2.0, $high],
        };
    }

    /** @return list<float> */
    private function bearLevels(string $mode, float $low, float $close): array
    {
        return match ($mode) {
            'Low' => [$low],
            'Close' => [$close],
            default => [$low, ($low + $close) / 2.0, $close],
        };
    }

    /**
     * Режим Pine «Fixed Range» vs «Visible Range»: принимаем и короткие ключи, и подписи из UI.
     */
    private function lookbackRangeIsFixed(mixed $value): bool
    {
        $s = strtolower(trim((string) $value));

        return str_contains($s, 'fixed');
    }

    /**
     * Последний индекс j ≤ i, где условие истинно и бар входит в окно обработки (sProcessing).
     * При sr_lookback_range = visible все бары обычно в окне — поведение как у прошлого lastTrueIndex.
     *
     * @param  array<int, bool>  $cond
     * @param  array<int, bool>  $window
     */
    private function lastTrueIndexInWindow(array $cond, array $window, int $i): ?int
    {
        for ($j = $i; $j >= 0; $j--) {
            if (! empty($cond[$j]) && ! empty($window[$j])) {
                return $j;
            }
        }

        return null;
    }

    /**
     * sProcessing как в Pine: для fixed — по времени; для visible — все бары в загруженном окне.
     *
     * @param  array<int, int>  $time
     * @return array<int, bool>
     */
    private function computeSProcessing(
        bool $fixedRange,
        int $lookbackBars,
        int $barSeconds,
        array $time,
        int $now,
        int $n
    ): array {
        $out = [];
        if ($fixedRange && $lookbackBars > 0) {
            $cutoff = $now - $lookbackBars * $barSeconds;
            for ($i = 0; $i < $n; $i++) {
                $out[$i] = $time[$i] > $cutoff;
            }
        } else {
            for ($i = 0; $i < $n; $i++) {
                $out[$i] = true;
            }
        }

        return $out;
    }

    /** @return array<int, float|null> */
    private function smaSeries(array $values, int $len): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($len <= 0) {
            return $out;
        }
        for ($i = 0; $i < $n; $i++) {
            if ($i < $len - 1) {
                continue;
            }
            $s = 0.0;
            for ($k = $i - $len + 1; $k <= $i; $k++) {
                $s += $values[$k];
            }
            $out[$i] = $s / $len;
        }

        return $out;
    }

    /**
     * ATR Wilder (как ta.atr в Pine).
     *
     * @return array<int, float|null>
     */
    private function atrWilder(array $high, array $low, array $close, int $len): array
    {
        $n = count($high);
        $out = array_fill(0, $n, null);
        if ($len <= 1 || $n === 0) {
            return $out;
        }
        $tr = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $tr[$i] = $high[$i] - $low[$i];
            } else {
                $tr[$i] = max(
                    $high[$i] - $low[$i],
                    abs($high[$i] - $close[$i - 1]),
                    abs($low[$i] - $close[$i - 1])
                );
            }
        }
        if ($n < $len) {
            return $out;
        }
        $seed = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $seed += $tr[$i];
        }
        $seed /= $len;
        $out[$len - 1] = $seed;
        for ($i = $len; $i < $n; $i++) {
            $prev = (float) $out[$i - 1];
            $out[$i] = (($prev * ($len - 1)) + $tr[$i]) / $len;
        }

        return $out;
    }

    /**
     * Volume profile + SD + линии POC/VAH/VAL (логика блока barstate.islast в Pine).
     *
     * @param  array<string, mixed>  $p
     * @param  array<int, bool>  $bull
     * @return array{lines: list<array<string, mixed>>, boxes: list<array<string, mixed>>, labels: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function computeVolumeProfileBlock(
        array $candles,
        array $p,
        array $open,
        array $high,
        array $low,
        array $close,
        array $vol,
        array $time,
        array $bull,
        int $n,
        int $barSeconds
    ): array {
        $profileLevels = max(10, min(170, (int) $p['vp_profile_levels']));
        $fixedLb = max(10, min(5000, (int) $p['vp_fixed_lookback_bars']));

        $L = $n;
        if ($this->lookbackRangeIsFixed($p['vp_lookback_range'] ?? '')) {
            $L = min($fixedLb, $n);
        }
        if ($L < 2) {
            return ['lines' => [], 'boxes' => [], 'labels' => [], 'meta' => []];
        }

        $startIdx = $n - $L;
        $priceHighest = $high[$startIdx];
        $priceLowest = $low[$startIdx];
        for ($i = $startIdx; $i < $n; $i++) {
            $priceHighest = max($priceHighest, $high[$i]);
            $priceLowest = min($priceLowest, $low[$i]);
        }
        if ($priceHighest <= $priceLowest) {
            return ['lines' => [], 'boxes' => [], 'labels' => [], 'meta' => []];
        }

        $priceStep = ($priceHighest - $priceLowest) / $profileLevels;
        if ($priceStep <= 0) {
            return ['lines' => [], 'boxes' => [], 'labels' => [], 'meta' => []];
        }

        $volumeStorage = array_fill(0, $profileLevels + 1, 0.0);
        $volumeStorageB = array_fill(0, $profileLevels + 1, 0.0);

        for ($barIndex = 0; $barIndex < $L; $barIndex++) {
            $idx = $n - 1 - $barIndex;
            $barHigh = $high[$idx];
            $barLow = $low[$idx];
            $barVol = $vol[$idx];
            $hl = $barHigh - $barLow;
            $mult = $hl == 0.0 ? 1.0 : ($priceStep / $hl);

            for ($pi = 0; $pi < $profileLevels; $pi++) {
                $priceLevel = $priceLowest + $pi * $priceStep;
                if ($barHigh >= $priceLevel && $barLow < $priceLevel + $priceStep) {
                    $add = $barVol * $mult;
                    $volumeStorage[$pi] += $add;
                    if ($bull[$idx]) {
                        $volumeStorageB[$pi] += $add;
                    }
                }
            }
        }

        $maxVol = max($volumeStorage);
        if ($maxVol <= 0) {
            return ['lines' => [], 'boxes' => [], 'labels' => [], 'meta' => []];
        }

        $pocLevel = 0;
        for ($pi = 1; $pi < $profileLevels; $pi++) {
            if ($volumeStorage[$pi] > $volumeStorage[$pocLevel]) {
                $pocLevel = $pi;
            }
        }

        $isValueArea = ((float) $p['vp_value_area_pct']) / 100.0;
        $totalVolumeTraded = array_sum($volumeStorage) * $isValueArea;
        $valueArea = $volumeStorage[$pocLevel];
        $levelAbovePoc = (int) $pocLevel;
        $levelBelowPoc = (int) $pocLevel;

        while ($valueArea < $totalVolumeTraded) {
            if ($levelBelowPoc === 0 && $levelAbovePoc >= $profileLevels - 1) {
                break;
            }
            $volumeAbovePoc = 0.0;
            if ($levelAbovePoc < $profileLevels - 1) {
                $volumeAbovePoc = $volumeStorage[$levelAbovePoc + 1];
            }
            $volumeBelowPoc = 0.0;
            if ($levelBelowPoc > 0) {
                $volumeBelowPoc = $volumeStorage[$levelBelowPoc - 1];
            }
            if ($volumeAbovePoc >= $volumeBelowPoc) {
                $valueArea += $volumeAbovePoc;
                $levelAbovePoc++;
            } else {
                $valueArea += $volumeBelowPoc;
                $levelBelowPoc--;
            }
        }

        $timeStart = $time[$startIdx];
        $timeEnd = $time[$n - 1];
        $offsetSec = ((int) $p['vp_horizontal_offset_bars'] + 50) * $barSeconds;

        $lines = [];
        $vahPrice = $priceLowest + ($levelAbovePoc + 1.0) * $priceStep;
        $pocPrice = $priceLowest + ($pocLevel + 0.5) * $priceStep;
        $valPrice = $priceLowest + ($levelBelowPoc + 0.0) * $priceStep;

        if ($p['vp_vah']) {
            $lines[] = [
                'source' => 'vp_vah',
                'time_start' => $timeStart,
                'time_end' => $timeEnd + $offsetSec,
                'price' => round($vahPrice, 8),
                'color' => (string) $p['vp_vah_color'],
                'width' => 2,
                'style' => 'Solid',
            ];
        }
        if ($p['vp_poc']) {
            $lines[] = [
                'source' => 'vp_poc',
                'time_start' => $timeStart,
                'time_end' => $timeEnd + $offsetSec,
                'price' => round($pocPrice, 8),
                'color' => (string) $p['vp_poc_color'],
                'width' => 2,
                'style' => 'Solid',
            ];
        }
        if ($p['vp_val']) {
            $lines[] = [
                'source' => 'vp_val',
                'time_start' => $timeStart,
                'time_end' => $timeEnd + $offsetSec,
                'price' => round($valPrice, 8),
                'color' => (string) $p['vp_val_color'],
                'width' => 2,
                'style' => 'Solid',
            ];
        }

        $boxes = [];
        $lowVolRatio = ((float) $p['sd_low_vol_ratio_pct']) / 100.0;

        if ($p['volume_profile']) {
            for ($level = 0; $level < $profileLevels; $level++) {
                $ratio = $volumeStorage[$level] / $maxVol;
                $rowLow = $priceLowest + ($level + 0.25) * $priceStep;
                $rowHigh = $priceLowest + ($level + 0.75) * $priceStep;
                // Цвета узлов VP: имена ключей совпадают с defaultParams (vp_*_color), не «_col».
                $cAvg = (string) $p['vp_avg_node_color'];
                $cHvn = (string) $p['vp_high_node_color'];
                $cLvn = (string) $p['vp_low_node_color'];
                $bg = $ratio > 0.8 ? $cHvn : ($ratio < 0.2 ? $cLvn : $cAvg);
                $boxes[] = [
                    'kind' => 'vp_hist',
                    'time_start' => $timeEnd,
                    'time_end' => $timeEnd + $offsetSec,
                    'price_low' => round($rowLow, 8),
                    'price_high' => round($rowHigh, 8),
                    'background' => $bg,
                    'width_ratio' => max(0.05, min(1.0, $ratio)),
                ];
                $bullBearPower = 2 * $volumeStorageB[$level] - $volumeStorage[$level];
                $domColor = $bullBearPower > 0 ? '#26a69a80' : '#ef535080';
                $boxes[] = [
                    'kind' => 'vp_dom',
                    'time_start' => $timeEnd,
                    'time_end' => $timeEnd + $offsetSec,
                    'price_low' => round($rowLow, 8),
                    'price_high' => round($rowHigh, 8),
                    'background' => $domColor,
                    'bias' => $bullBearPower > 0 ? 1 : -1,
                    'strength_ratio' => $maxVol > 0 ? min(1.0, abs($bullBearPower) / $maxVol) : 0,
                ];
            }
            $boxes[] = [
                'kind' => 'vp_frame',
                'time_start' => $timeStart,
                'time_end' => $timeEnd + $offsetSec,
                'price_low' => round($priceLowest, 8),
                'price_high' => round($priceHighest, 8),
                'border' => 'rgba(156,163,175,0.35)',
            ];
        }

        if ($p['supply_demand']) {
            for ($level = 0; $level < $profileLevels; $level++) {
                $ratio = $volumeStorage[$level] / $maxVol;
                if ($ratio < $lowVolRatio) {
                    $boxes[] = [
                        'kind' => 'sd_zone',
                        'time_start' => $timeStart,
                        'time_end' => $timeEnd + min(7, (int) $p['vp_horizontal_offset_bars']) * $barSeconds,
                        'price_low' => round($priceLowest + $level * $priceStep, 8),
                        'price_high' => round($priceLowest + ($level + 1.0) * $priceStep, 8),
                        'background' => (string) $p['sd_zone_color'],
                    ];
                }
            }
        }

        $labels = [];
        if ($p['vp_price_levels']) {
            $lc = (string) $p['vp_label_color'];
            $labels[] = ['text' => (string) round($priceHighest, 5), 'price' => $priceHighest, 'time' => $timeEnd + $offsetSec, 'color' => $lc, 'place' => 'high'];
            $labels[] = ['text' => (string) round($priceLowest, 5), 'price' => $priceLowest, 'time' => $timeEnd + $offsetSec, 'color' => $lc, 'place' => 'low'];
        }

        return [
            'lines' => $lines,
            'boxes' => $boxes,
            'labels' => $labels,
            'meta' => [
                'lookback_bars' => $L,
                'poc_price' => round($pocPrice, 8),
                'vah_price' => round($vahPrice, 8),
                'val_price' => round($valPrice, 8),
                'price_lowest' => round($priceLowest, 8),
                'price_highest' => round($priceHighest, 8),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return [
            'id' => self::ID,
            'version' => 1,
            'lines' => [],
            'markers' => [],
            'bar_colors' => [],
            'volume_profile' => ['lines' => [], 'boxes' => [], 'labels' => [], 'meta' => []],
            'meta' => ['bars' => 0, 'bar_seconds' => 0],
        ];
    }

    /**
     * Секунд на один бар UI-таймфрейма (как на графике бота).
     */
    public static function barSecondsForTimeframe(int $timeframeMinutes): int
    {
        if ($timeframeMinutes === CandleService::TF_S5) {
            return 5;
        }
        if ($timeframeMinutes === CandleService::TF_S15) {
            return 15;
        }
        if ($timeframeMinutes === CandleService::TF_S30) {
            return 30;
        }

        return max(60, $timeframeMinutes * 60);
    }
}
