<?php

namespace App\Support;

/**
 * Фильтр «грязной» OTC-пары по частоте гэпов между свечами.
 *
 * Автор на стриме kAyG3wJDPBU отказывается торговать пару, где между
 * close→open соседних свечей постоянно огромные разрывы («гэпы»).
 * Метрика: доля баров с |open[i]−close[i−1]| / typical_range выше порога.
 */
class OtcGapFilter
{
    /**
     * @param  array<string,mixed>  $cfg  Ключи: {prefix}_gap_filter_enabled, _gap_lookback, _gap_max_ratio, _gap_size_mult
     * @param  list<array{open?:float|int,high?:float|int,low?:float|int,close?:float|int}>  $candles
     */
    public static function shouldSkip(array $candles, array $cfg, string $prefix = 'srl'): bool
    {
        if (! (bool) ($cfg[$prefix.'_gap_filter_enabled'] ?? false)) {
            return false;
        }

        $stats = self::measure($candles, $cfg, $prefix);
        if ($stats === null) {
            return false;
        }

        $maxRatio = max(0.05, min(1.0, (float) ($cfg[$prefix.'_gap_max_ratio'] ?? 0.35)));

        return $stats['gap_ratio'] >= $maxRatio;
    }

    /**
     * @param  array<string,mixed>  $cfg
     * @param  list<array{open?:float|int,high?:float|int,low?:float|int,close?:float|int}>  $candles
     * @return array{gap_ratio:float,gaps:int,bars:int,avg_gap:float}|null
     */
    public static function measure(array $candles, array $cfg, string $prefix = 'srl'): ?array
    {
        $lookback = max(5, min(500, (int) ($cfg[$prefix.'_gap_lookback'] ?? 40)));
        $sizeMult = max(0.1, min(10.0, (float) ($cfg[$prefix.'_gap_size_mult'] ?? 0.5)));
        $n = count($candles);
        if ($n < 3) {
            return null;
        }

        $start = max(1, $n - $lookback);
        $gaps = 0;
        $checked = 0;
        $gapSum = 0.0;

        for ($i = $start; $i < $n; $i++) {
            $prevClose = (float) ($candles[$i - 1]['close'] ?? 0);
            $open = (float) ($candles[$i]['open'] ?? 0);
            $high = (float) ($candles[$i]['high'] ?? $open);
            $low = (float) ($candles[$i]['low'] ?? $open);
            $range = max(abs($high - $low), abs((float) ($candles[$i - 1]['high'] ?? $prevClose) - (float) ($candles[$i - 1]['low'] ?? $prevClose)), 1e-12);
            $gap = abs($open - $prevClose);
            $checked++;
            $gapSum += $gap / $range;
            if ($gap >= $range * $sizeMult) {
                $gaps++;
            }
        }

        if ($checked === 0) {
            return null;
        }

        return [
            'gap_ratio' => round($gaps / $checked, 4),
            'gaps' => $gaps,
            'bars' => $checked,
            'avg_gap' => round($gapSum / $checked, 4),
        ];
    }
}
