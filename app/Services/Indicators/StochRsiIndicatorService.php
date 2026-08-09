<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * Stochastic RSI — порт OsEngine CandleChart/Indicators/StochRsi.cs.
 *
 * RSI через RsiIndicatorService; сырой %K = 100*(RSI−min)/(max−min) за stochastic_length.
 * При max==min сохраняем предыдущий raw K. ValuesK = SMA(raw, K); ValuesD = SMA(ValuesK, D).
 */
class StochRsiIndicatorService
{
    public const ID = 'os.stoch_rsi';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'rsi_length' => 14,
        'stochastic_length' => 14,
        'k' => 3,
        'd' => 3,
        'show_k' => true,
        'show_d' => true,
        'color_k' => '#00ffff', // Aqua
        'color_d' => '#ff4500', // OrangeRed
        'line_width' => 1,
        'show_levels' => true,
        'level_overbought' => 80.0,
        'level_oversold' => 20.0,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{k: list<float>, d: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $rsiLen = max(1, (int) $p['rsi_length']);
        $stochLen = max(1, (int) $p['stochastic_length']);
        $kLen = max(1, (int) $p['k']);
        $dLen = max(1, (int) $p['d']);
        $n = count($rows);

        $rsi = (new RsiIndicatorService)->computeSeries($rows, ['length' => $rsiLen]);

        $rawK = array_fill(0, $n, 0.0);
        $valuesK = array_fill(0, $n, 0.0);
        $valuesD = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: index < rsiLength+3 || index < K || index < D → 0.
            if ($index < $rsiLen + 3 || $index < $kLen || $index < $dLen) {
                continue;
            }

            $high = $this->getHigh($rsi, $index, $stochLen);
            $low = $this->getLow($rsi, $index, $stochLen);

            if ($high - $low != 0.0) {
                $rawK[$index] = 100.0 * ((float) $rsi[$index] - $low) / ($high - $low);
            } else {
                // OsEngine: при равных экстремумах RSI — предыдущий raw K.
                $rawK[$index] = $index > 0 ? $rawK[$index - 1] : 0.0;
            }

            // ValuesK = SMA(raw K, K) через GetMoving.
            $valuesK[$index] = $this->getMoving($rawK, $index, $kLen);
            $valuesD[$index] = $this->getMoving($valuesK, $index, $dLen);
        }

        return ['k' => $valuesK, 'd' => $valuesD];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array<string,mixed>, style: array<string,mixed>, levels: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];

        if ((bool) $p['show_k']) {
            $series['k'] = OsEngineCandleMath::packSeries($rows, $raw['k']);
        }
        if ((bool) $p['show_d']) {
            $series['d'] = OsEngineCandleMath::packSeries($rows, $raw['d']);
        }

        $levels = ['show' => false];
        if ((bool) $p['show_levels']) {
            $levels = [
                'show' => true,
                'overbought' => (float) $p['level_overbought'],
                'oversold' => (float) $p['level_oversold'],
                'color_overbought' => 'rgba(250,204,21,0.7)',
                'color_oversold' => 'rgba(250,204,21,0.7)',
            ];
        }

        return [
            'series' => $series,
            'style' => [
                'color_k' => (string) $p['color_k'],
                'color_d' => (string) $p['color_d'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
            'levels' => $levels,
        ];
    }

    /** @param list<float> $rsi */
    private function getHigh(array $rsi, int $index, int $length): float
    {
        if ($index - $length + 1 <= 0) {
            return 0.0;
        }

        $max = 0.0;
        for ($i = $index - $length + 1; $i <= $index; $i++) {
            if ($rsi[$i] > $max) {
                $max = (float) $rsi[$i];
            }
        }

        return $max;
    }

    /** @param list<float> $rsi */
    private function getLow(array $rsi, int $index, int $length): float
    {
        if ($index - $length + 1 <= 0) {
            return 0.0;
        }

        $min = PHP_FLOAT_MAX;
        for ($i = $index - $length + 1; $i <= $index; $i++) {
            if ($rsi[$i] < $min) {
                $min = (float) $rsi[$i];
            }
        }

        return $min;
    }

    /**
     * GetMoving из StochRsi.cs: SMA последних `length` значений, заканчивая index.
     * Цикл: i = index; i > index−length && i > 0.
     *
     * @param  list<float>  $values
     */
    private function getMoving(array $values, int $index, int $length): float
    {
        if ($index - $length <= 0 || $index >= count($values)) {
            return 0.0;
        }

        $average = 0.0;
        for ($i = $index; $i > $index - $length && $i > 0; $i--) {
            $average += $values[$i];
        }

        return PriceScale::roundMarket($average / $length);
    }
}
