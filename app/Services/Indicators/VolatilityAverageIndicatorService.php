<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * VolatilityAverage — порт OsEngine Indicators/Scripts/VolatilityAverage.cs.
 *
 * Волатильность = High−Low за период (Day / Week / Candle) в Absolute или Percent,
 * затем SMA по «уникальным» значениям волы (для Day/Week) или по последним N барам (Candle).
 * CanReBuildHistoricalValues: в пределах текущего дня/недели переписывает всю зону.
 */
class VolatilityAverageIndicatorService
{
    public const ID = 'os.volatility_average';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'volatility_base_type' => 'Day', // Day | Week | Candle
        'volatility_variable_type' => 'Percent', // Percent | Absolute
        'sma_len' => 10,
        'show_volatility' => true,
        'show_sma' => true,
        'color_volatility' => '#1e90ff', // DodgerBlue
        'color_sma' => '#8b0000', // DarkRed
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
     * @return array{volatility: list<float>, sma: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $baseType = (string) $p['volatility_base_type'];
        $varType = (string) $p['volatility_variable_type'];
        $smaLen = max(1, (int) $p['sma_len']);
        $n = count($rows);

        $volatility = array_fill(0, $n, 0.0);
        $sma = array_fill(0, $n, 0.0);

        if ($n === 0) {
            return ['volatility' => $volatility, 'sma' => $sma];
        }

        for ($index = 0; $index < $n; $index++) {
            if ($baseType === 'Week') {
                $this->getVolatilityWeek($rows, $index, $varType, $smaLen, $volatility, $sma);
            } elseif ($baseType === 'Candle') {
                $this->getVolatilityCandle($rows, $index, $varType, $smaLen, $volatility, $sma);
            } else {
                // Day — дефолт OsEngine
                $this->getVolatilityDay($rows, $index, $varType, $smaLen, $volatility, $sma);
            }
        }

        return ['volatility' => $volatility, 'sma' => $sma];
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
        if ((bool) $p['show_sma']) {
            $series['sma'] = OsEngineCandleMath::packSeries($rows, $raw['sma']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_volatility' => (string) $p['color_volatility'],
                'color_sma' => (string) $p['color_sma'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * Дневной High−Low текущего календарного дня; переписывает все бары этого дня.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $sma
     */
    private function getVolatilityDay(
        array $rows,
        int $index,
        string $varType,
        int $smaLen,
        array &$volatility,
        array &$sma
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

        // Переписываем волу на всех барах текущего дня (CanReBuildHistoricalValues).
        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $volatility[$i] = $value;
        }

        $valueSma = $this->smaDistinctVolatility($volatility, $index, $smaLen);

        for ($i = $index; $i >= 0; $i--) {
            if (gmdate('Y-m-d', (int) $rows[$i]['time']) !== $curDay) {
                break;
            }
            $sma[$i] = PriceScale::roundMarket($valueSma);
        }
    }

    /**
     * Недельный диапазон от (не включая) предыдущего воскресенья до текущего бара.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $sma
     */
    private function getVolatilityWeek(
        array $rows,
        int $index,
        string $varType,
        int $smaLen,
        array &$volatility,
        array &$sma
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

        $valueSma = $this->smaDistinctVolatility($volatility, $index, $smaLen);

        for ($i = $index; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', (int) $rows[$i]['time']);
            if ($d <= $lastSundayDate) {
                break;
            }
            $sma[$i] = PriceScale::roundMarket($valueSma);
        }
    }

    /**
     * Волатильность одной свечи + простая SMA по последним smaLen значениям.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<float>  $volatility
     * @param  list<float>  $sma
     */
    private function getVolatilityCandle(
        array $rows,
        int $index,
        string $varType,
        int $smaLen,
        array &$volatility,
        array &$sma
    ): void {
        $h = (float) $rows[$index]['high'];
        $l = (float) $rows[$index]['low'];
        $volatility[$index] = $this->rangeToVolatility($h, $l, $varType);

        $summValue = 0.0;
        $countChangeVola = 0;
        for ($i = $index; $i >= 0; $i--) {
            $countChangeVola++;
            $summValue += $volatility[$i];
            if ($countChangeVola >= $smaLen) {
                break;
            }
        }

        $sma[$index] = PriceScale::roundMarket($summValue / $countChangeVola);
    }

    /** Absolute = high−low; Percent = (high−low)/(low/100), Round 5. */
    private function rangeToVolatility(float $high, float $low, string $varType): float
    {
        if ($varType === 'Absolute') {
            return $high - $low;
        }

        // Percent (дефолт OsEngine)
        if ($low == 0.0) {
            return 0.0;
        }

        return PriceScale::roundMarket(($high - $low) / ($low / 100.0));
    }

    /**
     * SMA по «уникальным» соседним значениям волы (пропуск одинаковых подряд), как в OsEngine Day/Week.
     *
     * @param  list<float>  $volatility
     */
    private function smaDistinctVolatility(array $volatility, int $index, int $smaLen): float
    {
        $summValue = 0.0;
        $countChangeVola = 0;
        $lastValue = 0.0;

        for ($i = $index; $i >= 0; $i--) {
            // Пропускаем подряд идущие одинаковые значения (кроме старта lastValue==0).
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
     * Дата предыдущего воскресенья относительно ts (как GetVolatilityWeek в OsEngine).
     * Если ts — воскресенье, берём воскресенье неделей раньше.
     */
    private function previousSundayTimestamp(int $ts): int
    {
        // gmdate('w'): 0 = Sunday … 6 = Saturday (как DayOfWeek в .NET).
        $dow = (int) gmdate('w', $ts);
        $cur = $ts;
        if ($dow === 0) {
            $cur -= 86400; // уходим с текущего воскресенья
        }
        while ((int) gmdate('w', $cur) !== 0) {
            $cur -= 86400;
        }

        // Нормализуем к полуночи UTC той даты (аналог DateTime.Date).
        $date = gmdate('Y-m-d', $cur);

        return (int) strtotime($date.' UTC');
    }
}
