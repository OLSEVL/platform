<?php

namespace App\Services\Indicators;

/**
 * VolatilityAverageChannel — порт OsEngine Indicators/Scripts/VolatilityAverageChannel.cs.
 *
 * Как VolatilityAverage, но две SMA (fast/slow) по воле и канал вокруг slow:
 * up = slow + slow * deviation, down = slow − slow * deviation.
 * По умолчанию slow SMA не рисуется (IsPainted=false).
 */
class VolatilityAverageChannelIndicatorService
{
    public const ID = 'os.volatility_average_channel';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'volatility_base_type' => 'Day', // Day | Week | Candle
        'volatility_variable_type' => 'Percent', // Percent | Absolute
        'sma_len_slow' => 21,
        'sma_len_fast' => 7,
        'channel_deviation' => 0.25,
        'show_volatility' => true,
        'show_sma_fast' => true,
        'show_sma_slow' => false, // IsPainted=false в OsEngine
        'show_up' => true,
        'show_down' => true,
        'color_volatility' => '#1e90ff', // DodgerBlue
        'color_sma_fast' => '#8b0000', // DarkRed
        'color_sma_slow' => '#f5f5f5', // WhiteSmoke
        'color_up' => '#f5f5f5', // WhiteSmoke
        'color_down' => '#f5f5f5', // WhiteSmoke
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
     * @return array{
     *   volatility: list<float>,
     *   sma_fast: list<float>,
     *   sma_slow: list<float>,
     *   up: list<float>,
     *   down: list<float>
     * }
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $baseType = (string) $p['volatility_base_type'];
        $varType = (string) $p['volatility_variable_type'];
        $lenFast = max(1, (int) $p['sma_len_fast']);
        $lenSlow = max(1, (int) $p['sma_len_slow']);
        $dev = (float) $p['channel_deviation'];
        $n = count($rows);

        $volatility = array_fill(0, $n, 0.0);
        $smaFast = array_fill(0, $n, 0.0);
        $smaSlow = array_fill(0, $n, 0.0);
        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        if ($n === 0) {
            return [
                'volatility' => $volatility,
                'sma_fast' => $smaFast,
                'sma_slow' => $smaSlow,
                'up' => $up,
                'down' => $down,
            ];
        }

        for ($index = 0; $index < $n; $index++) {
            if ($baseType === 'Week') {
                $this->getVolatilityWeek($rows, $index, $varType, $lenFast, $lenSlow, $dev, $volatility, $smaFast, $smaSlow, $up, $down);
            } elseif ($baseType === 'Candle') {
                $this->getVolatilityCandle($rows, $index, $varType, $lenFast, $lenSlow, $dev, $volatility, $smaFast, $smaSlow, $up, $down);
            } else {
                $this->getVolatilityDay($rows, $index, $varType, $lenFast, $lenSlow, $dev, $volatility, $smaFast, $smaSlow, $up, $down);
            }
        }

        return [
            'volatility' => $volatility,
            'sma_fast' => $smaFast,
            'sma_slow' => $smaSlow,
            'up' => $up,
            'down' => $down,
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

        if ((bool) $p['show_volatility']) {
            $series['volatility'] = OsEngineCandleMath::packSeries($rows, $raw['volatility']);
        }
        if ((bool) $p['show_sma_fast']) {
            $series['sma_fast'] = OsEngineCandleMath::packSeries($rows, $raw['sma_fast']);
        }
        if ((bool) $p['show_sma_slow']) {
            $series['sma_slow'] = OsEngineCandleMath::packSeries($rows, $raw['sma_slow']);
        }
        if ((bool) $p['show_up']) {
            $series['up'] = OsEngineCandleMath::packSeries($rows, $raw['up']);
        }
        if ((bool) $p['show_down']) {
            $series['down'] = OsEngineCandleMath::packSeries($rows, $raw['down']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_volatility' => (string) $p['color_volatility'],
                'color_sma_fast' => (string) $p['color_sma_fast'],
                'color_sma_slow' => (string) $p['color_sma_slow'],
                'color_up' => (string) $p['color_up'],
                'color_down' => (string) $p['color_down'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $smaFast
     * @param  list<float>  $smaSlow
     * @param  list<float>  $up
     * @param  list<float>  $down
     */
    private function getVolatilityDay(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        float $dev,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow,
        array &$up,
        array &$down
    ): void {
        $curDay = gmdate('Y-m-d', (int) $rows[$index]['time']);
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
            return;
        }

        $value = $this->rangeToVolatility($highToday, $lowToday, $varType);

        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $volatility[$i] = $value;
        }

        // Fast SMA по уникальным значениям волы.
        $fastSma = $this->smaDistinctVolatility($volatility, $index, $lenFast);
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $smaFast[$i] = round($fastSma, 5);
        }

        // Slow SMA + канал вокруг неё.
        $slowSma = $this->smaDistinctVolatility($volatility, $index, $lenSlow);
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $smaSlow[$i] = round($slowSma, 5);
            $up[$i] = round($slowSma + $slowSma * $dev, 5);
            $down[$i] = round($slowSma - $slowSma * $dev, 5);
        }
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $smaFast
     * @param  list<float>  $smaSlow
     * @param  list<float>  $up
     * @param  list<float>  $down
     */
    private function getVolatilityWeek(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        float $dev,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow,
        array &$up,
        array &$down
    ): void {
        $lastSundayTs = $this->previousSundayTimestamp((int) $rows[$index]['time']);
        $lastSundayDate = gmdate('Y-m-d', $lastSundayTs);

        $lowToday = PHP_FLOAT_MAX;
        $highToday = -PHP_FLOAT_MAX;

        for ($i = $index; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', (int) $rows[$i]['time']);
            if ($d <= $lastSundayDate) {
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
            return;
        }

        $value = $this->rangeToVolatility($highToday, $lowToday, $varType);

        for ($i = $index; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', (int) $rows[$i]['time']);
            if ($d <= $lastSundayDate) {
                break;
            }
            $volatility[$i] = $value;
        }

        // Week-fast в OsEngine без Round на SMA; канал — Round 5.
        $fastSma = $this->smaDistinctVolatility($volatility, $index, $lenFast);
        for ($i = $index; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', (int) $rows[$i]['time']);
            if ($d <= $lastSundayDate) {
                break;
            }
            $smaFast[$i] = $fastSma;
        }

        $slowSma = $this->smaDistinctVolatility($volatility, $index, $lenSlow);
        for ($i = $index; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', (int) $rows[$i]['time']);
            if ($d <= $lastSundayDate) {
                break;
            }
            $smaSlow[$i] = $slowSma;
            $up[$i] = round($slowSma + $slowSma * $dev, 5);
            $down[$i] = round($slowSma - $slowSma * $dev, 5);
        }
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $smaFast
     * @param  list<float>  $smaSlow
     * @param  list<float>  $up
     * @param  list<float>  $down
     */
    private function getVolatilityCandle(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        float $dev,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow,
        array &$up,
        array &$down
    ): void {
        $h = (float) $rows[$index]['high'];
        $l = (float) $rows[$index]['low'];
        $volatility[$index] = $this->rangeToVolatility($h, $l, $varType);

        $smaFast[$index] = $this->smaLastBars($volatility, $index, $lenFast);
        $slow = $this->smaLastBars($volatility, $index, $lenSlow);
        $smaSlow[$index] = $slow;
        $up[$index] = round($slow + $slow * $dev, 5);
        $down[$index] = round($slow - $slow * $dev, 5);
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

    /**
     * @param  list<float>  $volatility
     */
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

        if ($countChangeVola <= 0) {
            return 0.0;
        }

        return $summValue / $countChangeVola;
    }

    /**
     * Простая SMA по последним len барам волы (режим Candle).
     *
     * @param  list<float>  $volatility
     */
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
        $date = gmdate('Y-m-d', $cur);

        return (int) strtotime($date.' UTC');
    }
}
