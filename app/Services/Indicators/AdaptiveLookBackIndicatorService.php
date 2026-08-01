<?php

namespace App\Services\Indicators;

/**
 * Adaptive Look Back — порт OsEngine Indicators/Scripts/AdaptiveLookBack.cs
 * (алгоритм совпадает с CandleChart/Indicators/AdaptiveLookBack.cs).
 *
 * Ищет swing-hi / swing-lo по 5 свечам, копит индексы разворотов;
 * значение = (index − swing[count−Period]) / Period (целочисленное деление, как в C#).
 */
class AdaptiveLookBackIndicatorService
{
    public const ID = 'os.adaptive_look_back';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'length' => 5, // Period в Scripts
        'show_line' => true,
        'color' => '#f5f5f5', // WhiteSmoke (Scripts CreateSeries)
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
        $n = count($rows);
        $values = array_fill(0, $n, 0.0);

        /** @var list<int> $swingBarArray индексы баров со свингом */
        $swingBarArray = [];

        // ProcessAll: на каждом баре updateOnly=false (добавляем свинг, не перезаписываем).
        for ($index = 0; $index < $n; $index++) {
            $values[$index] = $this->getValue($rows, $index, $length, $swingBarArray, false);
        }

        return $values;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array{alb?: list<array{time:int,value:float}>}, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];
        if ((bool) $p['show_line']) {
            $series['alb'] = OsEngineCandleMath::packSeries($rows, $raw);
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
     * Порт GetValue.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  list<int>  $swingBarArray
     */
    private function getValue(array $rows, int $index, int $length, array &$swingBarArray, bool $updateOnly): float
    {
        // Прогрев: нужно минимум 5 баров и length+3.
        if ($index < 5 || $index < $length + 3) {
            return 0.0;
        }

        // SwingLo: падали ≥2 бара к index-2, затем растём 2 бара к index.
        $swingLo =
            (float) $rows[$index - 4]['low'] > (float) $rows[$index - 3]['low']
            && (float) $rows[$index - 3]['low'] > (float) $rows[$index - 2]['low']
            && (float) $rows[$index - 3]['high'] >= (float) $rows[$index - 2]['high']
            && (float) $rows[$index - 2]['high'] < (float) $rows[$index - 1]['high']
            && (float) $rows[$index - 1]['high'] < (float) $rows[$index]['high'];

        // SwingHi: росли ≥2 бара к index-2, затем падаем 2 бара к index.
        $swingHi =
            (float) $rows[$index - 4]['high'] < (float) $rows[$index - 3]['high']
            && (float) $rows[$index - 3]['high'] < (float) $rows[$index - 2]['high']
            && (float) $rows[$index - 3]['low'] <= (float) $rows[$index - 2]['low']
            && (float) $rows[$index - 2]['low'] > (float) $rows[$index - 1]['low']
            && (float) $rows[$index - 1]['low'] > (float) $rows[$index]['low'];

        $so = $swingLo ? -1 : ($swingHi ? 1 : 0);

        if ($so !== 0) {
            // Разворот: добавляем индекс или обновляем последний (live-бар).
            if (! $updateOnly) {
                $swingBarArray[] = $index;
            } else {
                if (count($swingBarArray) > 1) {
                    $swingBarArray[count($swingBarArray) - 1] = $index;
                } else {
                    $swingBarArray[] = $index;
                }
            }
        }

        // lastSwingInCalc = count − length; нужно ≥ length свингов.
        $lastSwingInCalc = count($swingBarArray) - $length;
        if ($lastSwingInCalc >= 0) {
            // C#: (int − int) / int → целочисленное деление, потом в decimal.
            return (float) intdiv($index - $swingBarArray[$lastSwingInCalc], $length);
        }

        return 0.0;
    }
}
