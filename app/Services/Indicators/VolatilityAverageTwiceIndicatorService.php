<?php

namespace App\Services\Indicators;

/**
 * VolatilityAverageTwice — порт OsEngine Indicators/Scripts/VolatilityAverageTwice.cs.
 *
 * Волатильность Day/Week/Candle (Absolute|Percent) + две SMA (fast/slow).
 * Day/Week: SMA по «уникальным» соседним значениям волы; Candle — по последним N барам.
 * Пересечение fast/slow — смена активности рынка.
 */
class VolatilityAverageTwiceIndicatorService
{
    public const ID = 'os.volatility_average_twice';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'volatility_base_type' => 'Day', // Day | Week | Candle
        'volatility_variable_type' => 'Percent', // Percent | Absolute
        'sma_len_slow' => 21,
        'sma_len_fast' => 7,
        'show_volatility' => true,
        'show_sma_fast' => true,
        'show_sma_slow' => true,
        'color_volatility' => '#1e90ff', // DodgerBlue
        'color_sma_fast' => '#8b0000', // DarkRed
        'color_sma_slow' => '#f5f5f5', // WhiteSmoke
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
     * @return array{volatility: list<float>, sma_fast: list<float>, sma_slow: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $baseType = (string) $p['volatility_base_type'];
        $varType = (string) $p['volatility_variable_type'];
        $lenFast = max(1, (int) $p['sma_len_fast']);
        $lenSlow = max(1, (int) $p['sma_len_slow']);
        $n = count($rows);

        $volatility = array_fill(0, $n, 0.0);
        $smaFast = array_fill(0, $n, 0.0);
        $smaSlow = array_fill(0, $n, 0.0);

        if ($n === 0) {
            return ['volatility' => $volatility, 'sma_fast' => $smaFast, 'sma_slow' => $smaSlow];
        }

        for ($index = 0; $index < $n; $index++) {
            if ($baseType === 'Week') {
                $this->getVolatilityWeek($rows, $index, $varType, $lenFast, $lenSlow, $volatility, $smaFast, $smaSlow);
            } elseif ($baseType === 'Candle') {
                $this->getVolatilityCandle($rows, $index, $varType, $lenFast, $lenSlow, $volatility, $smaFast, $smaSlow);
            } else {
                $this->getVolatilityDay($rows, $index, $varType, $lenFast, $lenSlow, $volatility, $smaFast, $smaSlow);
            }
        }

        return ['volatility' => $volatility, 'sma_fast' => $smaFast, 'sma_slow' => $smaSlow];
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
        if ((bool) $p['show_volatility']) {
            $series['volatility'] = OsEngineCandleMath::packSeries($rows, $raw['volatility']);
        }
        if ((bool) $p['show_sma_fast']) {
            $series['sma_fast'] = OsEngineCandleMath::packSeries($rows, $raw['sma_fast']);
        }
        if ((bool) $p['show_sma_slow']) {
            $series['sma_slow'] = OsEngineCandleMath::packSeries($rows, $raw['sma_slow']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_volatility' => (string) $p['color_volatility'],
                'color_sma_fast' => (string) $p['color_sma_fast'],
                'color_sma_slow' => (string) $p['color_sma_slow'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $smaFast
     * @param  list<float>  $smaSlow
     */
    private function getVolatilityDay(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow
    ): void {
        $curDay = gmdate('Y-m-d', (int) $rows[$index]['time']);
        [$lowToday, $highToday] = $this->dayRange($rows, $index, $curDay);
        if ($lowToday === null) {
            return;
        }

        $value = $this->rangeToVolatility($highToday, $lowToday, $varType);
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $volatility[$i] = $value;
        }

        $fast = $this->smaDistinctVolatility($volatility, $index, $lenFast);
        $slow = $this->smaDistinctVolatility($volatility, $index, $lenSlow);
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $smaFast[$i] = round($fast, 5);
            $smaSlow[$i] = round($slow, 5);
        }
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $smaFast
     * @param  list<float>  $smaSlow
     */
    private function getVolatilityWeek(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow
    ): void {
        $lastSundayDate = gmdate('Y-m-d', $this->previousSundayTimestamp((int) $rows[$index]['time']));
        [$lowToday, $highToday] = $this->weekRange($rows, $index, $lastSundayDate);
        if ($lowToday === null) {
            return;
        }

        $value = $this->rangeToVolatility($highToday, $lowToday, $varType);
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) <= $lastSundayDate) {
                break;
            }
            $volatility[$i] = $value;
        }

        $fast = $this->smaDistinctVolatility($volatility, $index, $lenFast);
        $slow = $this->smaDistinctVolatility($volatility, $index, $lenSlow);
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) <= $lastSundayDate) {
                break;
            }
            $smaFast[$i] = round($fast, 5);
            $smaSlow[$i] = round($slow, 5);
        }
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $smaFast
     * @param  list<float>  $smaSlow
     */
    private function getVolatilityCandle(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow
    ): void {
        $h = (float) $rows[$index]['high'];
        $l = (float) $rows[$index]['low'];
        $volatility[$index] = $this->rangeToVolatility($h, $l, $varType);
        $smaFast[$index] = round($this->smaLastBars($volatility, $index, $lenFast), 5);
        $smaSlow[$index] = round($this->smaLastBars($volatility, $index, $lenSlow), 5);
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @return array{0: ?float, 1: float}
     */
    private function dayRange(array $rows, int $index, string $curDay): array
    {
        $lowToday = PHP_FLOAT_MAX;
        $highToday = -PHP_FLOAT_MAX;
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $l = (float) $rows[$i]['low'];
            $h = (float) $rows[$i]['high'];
            if ($lowToday > $l) {
                $lowToday = $l;
            }
            if ($highToday < $h) {
                $highToday = $h;
            }
        }
        if ($lowToday === PHP_FLOAT_MAX || $highToday === -PHP_FLOAT_MAX) {
            return [null, 0.0];
        }

        return [$lowToday, $highToday];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @return array{0: ?float, 1: float}
     */
    private function weekRange(array $rows, int $index, string $lastSundayDate): array
    {
        $lowToday = PHP_FLOAT_MAX;
        $highToday = -PHP_FLOAT_MAX;
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) <= $lastSundayDate) {
                break;
            }
            $l = (float) $rows[$i]['low'];
            $h = (float) $rows[$i]['high'];
            if ($lowToday > $l) {
                $lowToday = $l;
            }
            if ($highToday < $h) {
                $highToday = $h;
            }
        }
        if ($lowToday === PHP_FLOAT_MAX || $highToday === -PHP_FLOAT_MAX) {
            return [null, 0.0];
        }

        return [$lowToday, $highToday];
    }

    private function rangeToVolatility(float $high, float $low, string $varType): float
    {
        if ($varType === 'Absolute') {
            return $high - $low;
        }
        if ($low == 0.0) {
            return 0.0;
        }

        return round(($high - $low) / ($low / 100.0), 5);
    }

    /** @param  list<float>  $volatility */
    private function smaDistinctVolatility(array $volatility, int $index, int $smaLen): float
    {
        $summValue = 0.0;
        $countChangeVola = 0;
        $lastValue = 0.0;
        for ($i = $index; $i >= 0; $i--) {
            if ($lastValue != 0.0 && $volatility[$i] == $lastValue) {
                continue;
            }
            $lastValue = $volatility[$i];
            $countChangeVola++;
            $summValue += $lastValue;
            if ($countChangeVola >= $smaLen) {
                break;
            }
        }

        return $countChangeVola > 0 ? $summValue / $countChangeVola : 0.0;
    }

    /** @param  list<float>  $volatility */
    private function smaLastBars(array $volatility, int $index, int $len): float
    {
        $summValue = 0.0;
        $count = 0;
        for ($i = $index; $i >= 0; $i--) {
            $count++;
            $summValue += $volatility[$i];
            if ($count >= $len) {
                break;
            }
        }

        return $count > 0 ? $summValue / $count : 0.0;
    }

    private function previousSundayTimestamp(int $ts): int
    {
        $dow = (int) gmdate('w', $ts);
        $cur = $ts;
        if ($dow === 0) {
            $cur -= 86400;
        }
        while ((int) gmdate('w', $cur) !== 0) {
            $cur -= 86400;
        }

        return (int) strtotime(gmdate('Y-m-d', $cur).' UTC');
    }
}
