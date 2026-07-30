<?php

namespace App\Services\Indicators;

/**
 * Alligator — порт OsEngine Indicators/Scripts/Alligator.cs.
 *
 * Jaw/Teeth/Lips = SSMA(Close) со сдвигом offset назад:
 * series[index] = ssma[index - shift] при index - shift >= 0.
 */
class AlligatorIndicatorService
{
    public const ID = 'os.alligator';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length_jaw' => 13,
        'length_teeth' => 8,
        'length_lips' => 5,
        'shift_jaw' => 8,
        'shift_teeth' => 5,
        'shift_lips' => 3,
        'candle_point' => 'Close',
        'show_jaw' => true,
        'show_teeth' => true,
        'show_lips' => true,
        'color_jaw' => '#1e90ff', // DodgerBlue
        'color_teeth' => '#8b0000', // DarkRed
        'color_lips' => '#7cfc00', // LawnGreen
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
     * @return array{jaw: list<float>, teeth: list<float>, lips: list<float>}
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $point = (string) $p['candle_point'];
        $n = count($rows);

        $ssmaSvc = new SsmaIndicatorService;
        $ssmaJaw = $ssmaSvc->computeSeries($rows, ['length' => max(1, (int) $p['length_jaw']), 'candle_point' => $point]);
        $ssmaTeeth = $ssmaSvc->computeSeries($rows, ['length' => max(1, (int) $p['length_teeth']), 'candle_point' => $point]);
        $ssmaLips = $ssmaSvc->computeSeries($rows, ['length' => max(1, (int) $p['length_lips']), 'candle_point' => $point]);

        $shiftJaw = max(0, (int) $p['shift_jaw']);
        $shiftTeeth = max(0, (int) $p['shift_teeth']);
        $shiftLips = max(0, (int) $p['shift_lips']);

        $jaw = array_fill(0, $n, 0.0);
        $teeth = array_fill(0, $n, 0.0);
        $lips = array_fill(0, $n, 0.0);

        for ($index = 0; $index < $n; $index++) {
            if ($index - $shiftTeeth >= 0) {
                $teeth[$index] = $ssmaTeeth[$index - $shiftTeeth];
            }
            if ($index - $shiftLips >= 0) {
                $lips[$index] = $ssmaLips[$index - $shiftLips];
            }
            if ($index - $shiftJaw >= 0) {
                $jaw[$index] = $ssmaJaw[$index - $shiftJaw];
            }
        }

        return ['jaw' => $jaw, 'teeth' => $teeth, 'lips' => $lips];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{
     *   series: array{jaw?: list<array{time:int,value:float}>, teeth?: list<array{time:int,value:float}>, lips?: list<array{time:int,value:float}>},
     *   style: array<string,mixed>
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_jaw']) {
            $series['jaw'] = OsEngineCandleMath::packSeries($rows, $raw['jaw']);
        }
        if ((bool) $p['show_teeth']) {
            $series['teeth'] = OsEngineCandleMath::packSeries($rows, $raw['teeth']);
        }
        if ((bool) $p['show_lips']) {
            $series['lips'] = OsEngineCandleMath::packSeries($rows, $raw['lips']);
        }

        return [
            'series' => $series,
            'style' => [
                'color_jaw' => (string) $p['color_jaw'],
                'color_teeth' => (string) $p['color_teeth'],
                'color_lips' => (string) $p['color_lips'],
                'line_width' => max(1, (int) $p['line_width']),
            ],
        ];
    }
}
