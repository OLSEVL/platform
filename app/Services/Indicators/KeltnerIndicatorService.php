<?php

namespace App\Services\Indicators;

/**
 * Keltner Channel — порт OsEngine Indicators/Scripts/KeltnerChannel.cs.
 * Середина = EMA(Close); полосы = EMA ± atr_coef * EMA(ATR).
 * Прогрев: index >= max(atrPeriod+atrEmaPeriod, emaPeriod).
 */
class KeltnerIndicatorService
{
    public const ID = 'os.keltner';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'ema_period' => 20,
        'atr_period' => 20,
        'atr_ema_period' => 20,
        'atr_coef' => 2.0,
        // В OsEngine параметр Candle Point есть, но в OnProcess не используется (EMA = Close).
        'candle_point' => 'Typical',
        'show_up' => true,
        'show_mid' => true,
        'show_down' => true,
        'color_up' => '#1e90ff', // DodgerBlue
        'color_mid' => '#808080', // Gray
        'color_down' => '#1e90ff',
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
     * @return array{mid: list<float>, up: list<float>, down: list<float>, ema_atr: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $emaPeriod = max(1, (int) $p['ema_period']);
        $atrPeriod = max(1, (int) $p['atr_period']);
        $atrEmaPeriod = max(1, (int) $p['atr_ema_period']);
        $atrCoef = (float) $p['atr_coef'];
        $n = count($rows);

        $mid = array_fill(0, $n, 0.0);
        $up = array_fill(0, $n, 0.0);
        $down = array_fill(0, $n, 0.0);
        $emaAtr = array_fill(0, $n, 0.0);

        // Вложенные индикаторы — как ProcessIndicator("EMA"/"ATR") в OsEngine.
        $ema = (new EmaIndicatorService)->computeSeries($rows, [
            'length' => $emaPeriod,
            'candle_point' => 'Close',
        ]);
        $atr = (new AtrIndicatorService)->computeSeries($rows, [
            'length' => $atrPeriod,
            'series_type' => 'Absolute',
        ]);

        $need = max($atrPeriod + $atrEmaPeriod, $emaPeriod);
        for ($index = 0; $index < $n; $index++) {
            if ($need > $index) {
                continue;
            }

            if ($index === $atrPeriod + $atrEmaPeriod) {
                // Seed: SMA окна ATR. В исходнике OsEngine опечатка Values[index] в цикле —
                // здесь суммируем Values[i], иначе seed = atr*period / period (= один бар).
                $smaAtr = 0.0;
                $from = $index - $atrEmaPeriod + 1;
                for ($i = $from; $i <= $index; $i++) {
                    $smaAtr += $atr[$i];
                }
                $emaAtr[$index] = $smaAtr / $atrEmaPeriod;
            } elseif ($index > $atrPeriod + $atrEmaPeriod) {
                $a = 2.0 / ($atrEmaPeriod + 1);
                $emaAtr[$index] = $emaAtr[$index - 1] + ($a * ($atr[$index] - $emaAtr[$index - 1]));
            }

            $mid[$index] = $ema[$index];
            $up[$index] = $ema[$index] + $atrCoef * $emaAtr[$index];
            $down[$index] = $ema[$index] - $atrCoef * $emaAtr[$index];
        }

        return ['mid' => $mid, 'up' => $up, 'down' => $down, 'ema_atr' => $emaAtr];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{mid?: list<array{time:int,value:float}>, up?: list<array{time:int,value:float}>, down?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_mid']) {
            $series['mid'] = OsEngineCandleMath::packSeries($rows, $raw['mid']);
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
                'color_up' => (string) $p['color_up'],
                'color_mid' => (string) $p['color_mid'],
                'color_down' => (string) $p['color_down'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
