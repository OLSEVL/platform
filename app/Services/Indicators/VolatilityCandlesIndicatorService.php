<?php

namespace App\Services\Indicators;

use App\Support\PriceScale;

/**
 * VolatilityCandles — порт OsEngine Indicators/Scripts/VolatilityCandles.cs.
 *
 * Оценивает относительную амплитуду бара: (High − Median) / Median × 100,
 * где Median = (High+Low)/2. Это эквивалентно (H−L)/(H+L)×100.
 * Затем по окну Length итеративно сглаживает с весом WeightCoeff (k):
 *   vol := k·V_i + (1−k)·vol
 * Чем k ближе к 1 — сильнее вес последних баров и больше шум.
 *
 * Линия в отдельной pane (не на цене). Volume не нужен — подходит для OTC.
 */
class VolatilityCandlesIndicatorService
{
    public const ID = 'os.volatility_candles';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 50,
        'weight_coeff' => 0.2, // WeightCoeff в OsEngine (0…1)
        'show_line' => true,
        'color' => '#00ffff', // Aqua
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
     * @return list<float>
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $length = max(1, (int) $p['length']);
        // Вес последних баров; ограничиваем [0, 1] как в комментарии OsEngine.
        $koeff = max(0.0, min(1.0, (float) $p['weight_coeff']));
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (Length + 1 > index) return; → нужно index >= Length + 1.
            if ($length + 1 > $index) {
                continue;
            }

            // Стартовое зерно — сырая волатильность предыдущего бара (index−1).
            $volatility = $this->rawVolatility($rows[$index - 1]);

            // Итеративное сглаживание по окну [index−Length+1 … index] включительно.
            $from = $index - $length + 1;
            for ($i = $from; $i <= $index; $i++) {
                $ivolatility = $this->rawVolatility($rows[$i]);
                $volatility = $koeff * $ivolatility + (1.0 - $koeff) * $volatility;
            }

            // Math.Round(volatility, 3) в C#.
            $values[$index] = PriceScale::roundMarket($volatility);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{vc?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            // Прогрев = 0; после прогрева значения обычно > 0, нули пропускаем.
            $series['vc'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }

    /**
     * Сырая относительная амплитуда одной свечи: (High − Median) / Median × 100.
     *
     * @param  array{time:int,open:float,high:float,low:float,close:float,volume?:float}  $candle
     */
    private function rawVolatility(array $candle): float
    {
        $high = OsEngineCandleMath::candlePoint($candle, 'High');
        $median = OsEngineCandleMath::candlePoint($candle, 'Median');
        // Защита от деления на ноль (у OsEngine её нет; на OTC mid почти всегда > 0).
        if ($median == 0.0) {
            return 0.0;
        }

        return ($high - $median) / $median * 100.0;
    }
}
