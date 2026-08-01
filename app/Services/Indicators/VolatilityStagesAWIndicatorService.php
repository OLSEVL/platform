<?php

namespace App\Services\Indicators;

/**
 * VolatilityStagesAW — порт OsEngine Indicators/Scripts/VolatilityStagesAW.cs.
 *
 * Считает волу Day/Week/Candle + fast/slow SMA и канал вокруг slow
 * (up/down = slow ± slow*deviation). Затем классифицирует стадию (2/3/4 режима):
 *  - regime 2: 1 = fast<slow, 2 = fast>=slow
 *  - regime 3: 1 ниже канала, 2 внутри, 3 выше
 *  - regime 4: 1 ниже down, 2 down…slow, 3 slow…up, 4 выше up
 *
 * Трейдинг: низкая стадия — искать пробой; высокая — фиксировать / не входить.
 * По умолчанию сырая Volatility не рисуется (IsPainted=false в OsEngine).
 */
class VolatilityStagesAWIndicatorService
{
    public const ID = 'os.volatility_stages_aw';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'stages_regime' => '3', // 2 | 3 | 4
        'volatility_base_type' => 'Day',
        'volatility_variable_type' => 'Percent',
        'sma_len_slow' => 21,
        'sma_len_fast' => 7,
        'channel_deviation' => 0.25,
        'show_stages' => true,
        'show_volatility' => false,
        'show_sma_fast' => true,
        'show_sma_slow' => true,
        'show_up' => true,
        'show_down' => true,
        'color_stages' => '#008000', // Green
        'color_volatility' => '#1e90ff', // DodgerBlue
        'color_sma_fast' => '#8b0000', // DarkRed
        'color_sma_slow' => '#f5f5f5', // WhiteSmoke
        'color_up' => '#f5f5f5',
        'color_down' => '#f5f5f5',
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
     *   stages: list<float>,
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
        $regime = (string) $p['stages_regime'];
        $baseType = (string) $p['volatility_base_type'];
        $varType = (string) $p['volatility_variable_type'];
        $lenFast = max(1, (int) $p['sma_len_fast']);
        $lenSlow = max(1, (int) $p['sma_len_slow']);
        $dev = (float) $p['channel_deviation'];
        $n = count($rows);

        $stages = array_fill(0, $n, 0.0);
        $volatility = array_fill(0, $n, 0.0);
        $smaFast = array_fill(0, $n, 0.0);
        $smaSlow = array_fill(0, $n, 0.0);
        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);

        if ($n === 0) {
            return [
                'stages' => $stages,
                'volatility' => $volatility,
                'sma_fast' => $smaFast,
                'sma_slow' => $smaSlow,
                'up' => $up,
                'down' => $down,
            ];
        }

        for ($index = 0; $index < $n; $index++) {
            if ($baseType === 'Week') {
                $this->getVolatilityWeek($rows, $index, $varType, $lenFast, $lenSlow, $dev, $regime, $volatility, $smaFast, $smaSlow, $up, $down, $stages);
            } elseif ($baseType === 'Candle') {
                $this->getVolatilityCandle($rows, $index, $varType, $lenFast, $lenSlow, $dev, $regime, $volatility, $smaFast, $smaSlow, $up, $down, $stages);
            } else {
                $this->getVolatilityDay($rows, $index, $varType, $lenFast, $lenSlow, $dev, $regime, $volatility, $smaFast, $smaSlow, $up, $down, $stages);
            }
        }

        return [
            'stages' => $stages,
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
        if ((bool) $p['show_stages']) {
            $series['stages'] = OsEngineCandleMath::packSeries($rows, $raw['stages']);
        }
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
                'color_stages' => (string) $p['color_stages'],
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
     * @param  list<float>  $stages
     */
    private function getVolatilityDay(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        float $dev,
        string $regime,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow,
        array &$up,
        array &$down,
        array &$stages
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

        $fast = $this->smaDistinctVolatility($volatility, $index, $lenFast);
        $slow = $this->smaDistinctVolatility($volatility, $index, $lenSlow);
        $upVal = round($slow + $slow * $dev, 5);
        $downVal = round($slow - $slow * $dev, 5);
        $fastR = round($fast, 5);
        $slowR = round($slow, 5);

        // Пишем SMA/канал на индекс, чтобы GetVolatilityStage видел актуальные значения.
        $smaFast[$index] = $fastR;
        $smaSlow[$index] = $slowR;
        $up[$index] = $upVal;
        $down[$index] = $downVal;
        $stage = $this->getVolatilityStage($regime, $fastR, $slowR, $upVal, $downVal);

        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $smaFast[$i] = $fastR;
            $smaSlow[$i] = $slowR;
            $up[$i] = $upVal;
            $down[$i] = $downVal;
            $stages[$i] = $stage;
        }
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $smaFast
     * @param  list<float>  $smaSlow
     * @param  list<float>  $up
     * @param  list<float>  $down
     * @param  list<float>  $stages
     */
    private function getVolatilityWeek(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        float $dev,
        string $regime,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow,
        array &$up,
        array &$down,
        array &$stages
    ): void {
        $lastSundayDate = gmdate('Y-m-d', $this->previousSundayTimestamp((int) $rows[$index]['time']));
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
        $upVal = round($slow + $slow * $dev, 5);
        $downVal = round($slow - $slow * $dev, 5);
        $fastR = round($fast, 5);
        $slowR = round($slow, 5);
        $stage = $this->getVolatilityStage($regime, $fastR, $slowR, $upVal, $downVal);

        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) <= $lastSundayDate) {
                break;
            }
            $smaFast[$i] = $fastR;
            $smaSlow[$i] = $slowR;
            $up[$i] = $upVal;
            $down[$i] = $downVal;
            $stages[$i] = $stage;
        }
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $smaFast
     * @param  list<float>  $smaSlow
     * @param  list<float>  $up
     * @param  list<float>  $down
     * @param  list<float>  $stages
     */
    private function getVolatilityCandle(
        array $rows,
        int $index,
        string $varType,
        int $lenFast,
        int $lenSlow,
        float $dev,
        string $regime,
        array &$volatility,
        array &$smaFast,
        array &$smaSlow,
        array &$up,
        array &$down,
        array &$stages
    ): void {
        $h = (float) $rows[$index]['high'];
        $l = (float) $rows[$index]['low'];
        $volatility[$index] = $this->rangeToVolatility($h, $l, $varType);

        $fast = round($this->smaLastBars($volatility, $index, $lenFast), 5);
        $slow = round($this->smaLastBars($volatility, $index, $lenSlow), 5);
        $upVal = round($slow + $slow * $dev, 5);
        $downVal = round($slow - $slow * $dev, 5);

        $smaFast[$index] = $fast;
        $smaSlow[$index] = $slow;
        $up[$index] = $upVal;
        $down[$index] = $downVal;
        $stages[$index] = $this->getVolatilityStage($regime, $fast, $slow, $upVal, $downVal);
    }

    /** Классификация стадии по regime 2/3/4 — как GetVolatilityStage в OsEngine. */
    private function getVolatilityStage(string $regime, float $smaFast, float $smaSlow, float $upper, float $down): float
    {
        if ($regime === '2') {
            if ($smaFast == 0.0 || $smaSlow == 0.0) {
                return 0.0;
            }

            return $smaFast >= $smaSlow ? 2.0 : 1.0;
        }

        if ($regime === '4') {
            if ($smaFast == 0.0 || $smaSlow == 0.0 || $upper == 0.0 || $down == 0.0) {
                return 0.0;
            }
            if ($smaFast >= $upper) {
                return 4.0;
            }
            if ($smaFast <= $upper && $smaFast >= $smaSlow) {
                return 3.0;
            }
            if ($smaFast <= $smaSlow && $smaFast >= $down) {
                return 2.0;
            }
            if ($smaFast <= $down) {
                return 1.0;
            }

            return 0.0;
        }

        // regime "3" (дефолт)
        if ($smaFast == 0.0 || $smaSlow == 0.0 || $upper == 0.0 || $down == 0.0) {
            return 0.0;
        }
        if ($smaFast >= $upper) {
            return 3.0;
        }
        if ($smaFast <= $upper && $smaFast >= $down) {
            return 2.0;
        }
        if ($smaFast <= $down) {
            return 1.0;
        }

        return 0.0;
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
