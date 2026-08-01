<?php

namespace App\Services\Indicators;

/**
 * NRTR_WATR — порт OsEngine Indicators/Scripts/NRTR_WATR.cs.
 *
 * Trailing-stop по ATR: в бычьем режиме линия = hp − ATR×mult,
 * в медвежьем = lp + ATR×mult; разворот при пробое close через nrtr.
 * Вложенный ATR — стандартный Wilder ATR (как ProcessIndicator("ATR") в OsEngine).
 */
class NrtrWatrIndicatorService
{
    public const ID = 'os.nrtr_watr';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 21,
        'atr_multiplicator' => 2.5,
        'show_line' => true,
        'color' => '#ff0000', // Red
        'line_width' => 1,
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * Сырая серия NRTR_WATR (длина = count($rows)); 0 = нет значения.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return list<float>
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $period = max(1, (int) $p['length']);
        $mult = (float) $p['atr_multiplicator'];
        $n = count($rows);

        $values = array_fill(0, $n, 0.0);

        // Вложенный ATR с периодом Length (как _atr.Parameters[0].Bind(_period)).
        $atr = (new AtrIndicatorService)->computeSeries($rows, [
            'length' => $period,
            'series_type' => 'Absolute',
        ]);

        // Состояние OsEngine: _trend, hp, lp, nrtr.
        $trend = 0;
        $hp = -INF;
        $lp = INF;
        $nrtr = 0.0;

        for ($index = 0; $index < $n; $index++) {
            // OsEngine: if (index < _period.ValueInt) return;
            if ($index < $period) {
                continue;
            }

            $close = (float) $rows[$index]['close'];
            $atrVal = $atr[$index];

            if ($trend >= 0) {
                // Бычий / нейтральный: тянем максимум close и стоп снизу.
                if ($close > $hp) {
                    $hp = $close;
                }

                $nrtr = $hp - $atrVal * $mult;

                if ($close <= $nrtr) {
                    // Пробой вниз → разворот в медвежий режим.
                    $trend = -1;
                    $lp = $close;
                    $nrtr = $lp + $atrVal * $mult;
                }
            } elseif ($close < $lp) {
                // Медвежий: обновляем минимум close и стоп сверху.
                // В исходнике: lp = lp = candles[index].Close (двойное присвоение).
                $lp = $close;
                $nrtr = $lp + $atrVal * $mult;
            } elseif ($close > $nrtr) {
                // Пробой вверх → разворот в бычий режим.
                $trend = 1;
                $hp = $close;
                $nrtr = $hp - $atrVal * $mult;
            }

            $values[$index] = $nrtr;
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{nrtr?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['nrtr'] = OsEngineCandleMath::packSeries($rows, $raw);
        }

        return [
            'series' => $series,
            'style' => [
                'color' => (string) $p['color'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
