<?php

namespace App\Support;

/**
 * Дневная волатильность как в OsEngine Patterns/*VolatilityAdaptive*:
 * за N календарных дней считаем (high−low)/low*100, затем SMA этих % —
 * порог сигнала = avgVola% × (volaPercentParam / 100).
 *
 * Нужна длинная M1-история (≈ days×1440 баров). Если дней не хватает — null.
 */
final class OsEngineDailyVolatilityAdaptive
{
    /**
     * @param  list<array{time?:int,high?:float|int|string,low?:float|int|string}>  $candles
     * @return float|null средняя дневная волатильность в процентах
     */
    public static function averageDailyVolaPercent(array $candles, int $days): ?float
    {
        if ($days <= 0 || count($candles) < 5) {
            return null;
        }

        $minValueInDay = PHP_FLOAT_MAX;
        $maxValueInDay = -PHP_FLOAT_MAX;
        /** @var list<float> $volaInDaysPercent */
        $volaInDaysPercent = [];

        $n = count($candles);
        $lastTime = (int) ($candles[$n - 1]['time'] ?? 0);
        if ($lastTime <= 0) {
            return null;
        }

        // Календарная дата UTC последнего бара (как OsEngine .Date).
        $dateYmd = gmdate('Y-m-d', $lastTime);
        $daysSeen = 0;

        for ($i = $n - 1; $i >= 0; $i--) {
            $cur = $candles[$i];
            $t = (int) ($cur['time'] ?? 0);
            if ($t <= 0) {
                continue;
            }
            $curYmd = gmdate('Y-m-d', $t);

            if ($curYmd < $dateYmd) {
                $dateYmd = $curYmd;
                $daysSeen++;

                if ($minValueInDay < PHP_FLOAT_MAX && $maxValueInDay > -PHP_FLOAT_MAX && $minValueInDay > 0.0) {
                    $volaAbs = $maxValueInDay - $minValueInDay;
                    // OsEngine: volaAbs / (minValueInDay / 100)
                    $volaInDaysPercent[] = $volaAbs / ($minValueInDay / 100.0);
                }

                $minValueInDay = PHP_FLOAT_MAX;
                $maxValueInDay = -PHP_FLOAT_MAX;
            }

            if ($daysSeen >= $days) {
                break;
            }

            $high = (float) ($cur['high'] ?? 0);
            $low = (float) ($cur['low'] ?? 0);
            if ($high > $maxValueInDay) {
                $maxValueInDay = $high;
            }
            if ($low < $minValueInDay) {
                $minValueInDay = $low;
            }

            // Последний (самый старый) бар в выборке — закрываем незавершённый день.
            if ($i === 0) {
                $daysSeen++;
                if ($minValueInDay < PHP_FLOAT_MAX && $maxValueInDay > -PHP_FLOAT_MAX && $minValueInDay > 0.0) {
                    $volaAbs = $maxValueInDay - $minValueInDay;
                    $volaInDaysPercent[] = $volaAbs / ($minValueInDay / 100.0);
                }
            }
        }

        if ($volaInDaysPercent === []) {
            return null;
        }

        return array_sum($volaInDaysPercent) / count($volaInDaysPercent);
    }

    /**
     * Порог высоты свечи/паттерна: avgDailyVola% × (volaSharePercent / 100).
     *
     * @param  list<array{time?:int,high?:float|int|string,low?:float|int|string}>  $candles
     */
    public static function adaptHeightPercent(
        array $candles,
        int $days,
        float $volaSharePercent,
        ?float $fallback = null,
    ): ?float {
        if ($days <= 0 || $volaSharePercent <= 0.0) {
            return $fallback;
        }

        $avg = self::averageDailyVolaPercent($candles, $days);
        if ($avg === null || $avg <= 0.0) {
            return $fallback;
        }

        return $avg * ($volaSharePercent / 100.0);
    }
}
