<?php

namespace App\Services\Indicators;

/**
 * Pivot Floor — порт OsEngine Scripts/PivotFloor.cs.
 *
 * Классические pivot-уровни по H/L/C предыдущего периода (Daily или Weekly).
 * Свечи имеют поле time в unix-секундах.
 *
 * Логика границ периода (как в OsEngine ProcessAll):
 * — Daily: новый период, когда меняется календарный день (Day).
 * — Weekly: новый период в понедельник (DayOfWeek.Monday), если DayOfYear изменился.
 * После второго периода вызывается Reload: H/L/C берутся из [startPrev .. startCurr−1].
 * Уровни P, R1–R3, S1–S3 протягиваются вперёд до следующего пересчёта.
 */
class PivotFloorIndicatorService
{
    public const ID = 'os.pivot_floor';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'period' => 'Daily',
        'show_p' => true,
        'show_r1' => true,
        'show_r2' => true,
        'show_r3' => true,
        'show_s1' => true,
        'show_s2' => true,
        'show_s3' => true,
        'color_p' => '#7cfc00', // LawnGreen
        'color_r' => '#1e90ff', // DodgerBlue
        'color_s' => '#8b0000', // DarkRed
        'line_width' => 1,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{p: list<float>, r1: list<float>, r2: list<float>, r3: list<float>, s1: list<float>, s2: list<float>, s3: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $periodMode = (string) $p['period'];
        $n = count($rows);

        $outP = array_fill(0, $n, 0.0);
        $outR1 = array_fill(0, $n, 0.0);
        $outR2 = array_fill(0, $n, 0.0);
        $outR3 = array_fill(0, $n, 0.0);
        $outS1 = array_fill(0, $n, 0.0);
        $outS2 = array_fill(0, $n, 0.0);
        $outS3 = array_fill(0, $n, 0.0);

        // Текущие уровни — переносятся на каждый бар до Reload.
        $pivot = 0.0;
        $r1 = 0.0;
        $r2 = 0.0;
        $r3 = 0.0;
        $s1 = 0.0;
        $s2 = 0.0;
        $s3 = 0.0;

        /** @var list<int> $periodStarts — индексы свечей, с которых начинается новый период */
        $periodStarts = [];
        $lastTimeReload = null;
        $periodCount = 0;

        for ($i = 0; $i < $n; $i++) {
            $ts = (int) $rows[$i]['time'];
            $isNewPeriod = $this->isNewPeriod($ts, $lastTimeReload, $periodMode);

            if ($isNewPeriod) {
                $lastTimeReload = $ts;
                $periodStarts[] = $i;
                $periodCount++;

                // Reload возможен только после накопления двух периодов (count >= 2).
                if ($periodCount >= 2) {
                    [$pivot, $r1, $r2, $r3, $s1, $s2, $s3] = $this->reloadLevels(
                        $rows,
                        $periodStarts,
                        count($periodStarts) - 2,
                        count($periodStarts) - 1
                    );
                }
            }

            // Протягиваем последние рассчитанные уровни на текущий бар.
            $outP[$i] = $pivot;
            $outR1[$i] = $r1;
            $outR2[$i] = $r2;
            $outR3[$i] = $r3;
            $outS1[$i] = $s1;
            $outS2[$i] = $s2;
            $outS3[$i] = $s3;
        }

        return [
            'p' => $outP,
            'r1' => $outR1,
            'r2' => $outR2,
            'r3' => $outR3,
            's1' => $outS1,
            's2' => $outS2,
            's3' => $outS3,
        ];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array<string,mixed>, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];

        $map = [
            'p' => 'show_p',
            'r1' => 'show_r1',
            'r2' => 'show_r2',
            'r3' => 'show_r3',
            's1' => 'show_s1',
            's2' => 'show_s2',
            's3' => 'show_s3',
        ];

        foreach ($map as $key => $showKey) {
            if ((bool) $p[$showKey]) {
                $series[$key] = OsEngineCandleMath::packSeries($rows, $raw[$key]);
            }
        }

        return [
            'series' => $series,
            'style' => [
                'color_p' => (string) $p['color_p'],
                'color_r' => (string) $p['color_r'],
                'color_s' => (string) $p['color_s'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * Проверка начала нового торгового периода по unix-time.
     * Daily: смена календарного дня. Weekly: понедельник + смена day-of-year.
     */
    private function isNewPeriod(int $ts, ?int $lastTs, string $periodMode): bool
    {
        if ($lastTs === null) {
            return true;
        }

        if ($periodMode === 'Weekly') {
            // OsEngine: DayOfWeek.Monday && DayOfYear != last.DayOfYear.
            $isMonday = (int) gmdate('w', $ts) === 1;
            $dayOfYear = (int) gmdate('z', $ts);
            $lastDayOfYear = (int) gmdate('z', $lastTs);

            return $isMonday && $dayOfYear !== $lastDayOfYear;
        }

        // Daily: смена дня (OsEngine: _lastTimeReload.Day != candle.TimeStart.Day).
        return gmdate('Y-m-d', $ts) !== gmdate('Y-m-d', $lastTs);
    }

    /**
     * Reload из PivotFloor.cs: H/L/C предыдущего периода [startPrev .. startCurr−1].
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<int>  $periodStarts
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}
     */
    private function reloadLevels(array $rows, array $periodStarts, int $prevIdx, int $currIdx): array
    {
        $start = $periodStarts[$prevIdx];
        $end = $periodStarts[$currIdx];

        $h = 0.0;
        $l = PHP_FLOAT_MAX;
        $c = 0.0;

        for ($i = $start; $i < $end; $i++) {
            $hi = (float) $rows[$i]['high'];
            $lo = (float) $rows[$i]['low'];
            if ($h < $hi) {
                $h = $hi;
            }
            if ($l > $lo) {
                $l = $lo;
            }
            $c = (float) $rows[$i]['close'];
        }

        if ($l === PHP_FLOAT_MAX || $h == 0.0 || $c == 0.0) {
            return [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        }

        // Pivot-Floor формулы OsEngine.
        $pivot = ($h + $l + $c) / 3.0;
        $s1 = (2.0 * $pivot) - $h;
        $r1 = (2.0 * $pivot) - $l;
        $r2 = $pivot + $h - $l;
        $r3 = ($pivot - $s1) + $r2;
        $s2 = $pivot - $h + $l;
        $s3 = $pivot - ($r2 - $s1);

        return [$pivot, $r1, $r2, $r3, $s1, $s2, $s3];
    }
}
