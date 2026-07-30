<?php

namespace App\Services\Indicators;

/**
 * Ichimoku — порт OsEngine Indicators/Scripts/Ichimoku.cs (ProcessAll / полный пересчёт).
 *
 * Tenkan/Kijun/Senkou B = середина high/low окна (GetLine).
 * Senkou A = (kijun[i-disp] + tenkan[i-disp]) / 2 с проверками прогрева.
 * Chinkou на баре t: close бара t+chinkouSpan (сдвиг назад на графике).
 */
class IchimokuIndicatorService
{
    public const ID = 'os.ichimoku';

    /** @var array<string, mixed> */
    public const DEFAULT_PARAMS = [
        'tenkan' => 9,
        'kijun' => 26,
        'senkou_b' => 52,
        'displacement' => 26,
        'chinkou_span' => 26,
        'show_tenkan' => true,
        'show_kijun' => true,
        'show_senkou_a' => true,
        'show_senkou_b' => true,
        'show_chinkou' => true,
        'color_tenkan' => '#8a2be2', // BlueViolet
        'color_kijun' => '#ff4500', // OrangeRed
        'color_senkou_a' => '#32cd32', // LimeGreen
        'color_senkou_b' => '#1e90ff', // DodgerBlue
        'color_chinkou' => '#8b0000', // DarkRed
        'line_width' => 1,
        'chinkou_point_radius' => 2,
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
     *   tenkan: list<float>,
     *   kijun: list<float>,
     *   senkou_a: list<float>,
     *   senkou_b: list<float>,
     *   chinkou: list<float>,
     *   chinkou_times: list<int>
     * }
     */
    public function computeSeries(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $tenkanLen = max(1, (int) $p['tenkan']);
        $kijunLen = max(1, (int) $p['kijun']);
        $senkouBLen = max(1, (int) $p['senkou_b']);
        $displacement = max(0, (int) $p['displacement']);
        $chinkouSpan = max(0, (int) $p['chinkou_span']);
        $n = count($rows);

        // Внутренние буферы как в OsEngine ProcessAll (имена переменных сохранены для читаемости diff).
        $kijunInternal = [];
        $tenkanInternal = [];
        $chinkouInternal = [];
        $senkouAInternal = [];
        $senkouBInternal = [];

        for ($i = 0; $i < $n; $i++) {
            $kijunInternal[] = $this->getLine($rows, $i, $kijunLen, 0);
            $tenkanInternal[] = $this->getLine($rows, $i, $tenkanLen, 0);

            if ($i >= $chinkouSpan) {
                $chinkouInternal[] = $this->getLineLate($rows, $i - $chinkouSpan, $chinkouSpan);
            }

            $senkouAInternal[] = $this->getLineFirst(
                $kijunInternal,
                $tenkanInternal,
                $i,
                $displacement,
                $tenkanLen
            );
            $senkouBInternal[] = $this->getLine($rows, $i, $senkouBLen, $displacement);
        }

        // Выходные серии на текущих барах (Tenkan/Kijun/Senkou A/B).
        $tenkan = array_fill(0, $n, 0.0);
        $kijun = array_fill(0, $n, 0.0);
        $senkouA = array_fill(0, $n, 0.0);
        $senkouB = array_fill(0, $n, 0.0);
        $chinkou = array_fill(0, $n, 0.0);
        $chinkouTimes = array_fill(0, $n, 0);

        for ($index = 0; $index < $n; $index++) {
            // OsEngine OnProcess: seriesEtalon=tenkanInternal, seriesRoundet=kijunInternal (swap имён в C#).
            $tenkan[$index] = $tenkanInternal[$index];
            $kijun[$index] = $kijunInternal[$index];
            $senkouA[$index] = $senkouAInternal[$index];
            $senkouB[$index] = $senkouBInternal[$index];

            // Chinkou: значение записывается на бар index - chinkouSpan (сдвиг назад по времени).
            if ($index - $chinkouSpan > 0 && ($index - $chinkouSpan) < count($chinkouInternal)) {
                $plotAt = $index - $chinkouSpan;
                $chinkou[$plotAt] = $chinkouInternal[$plotAt];
                $chinkouTimes[$plotAt] = (int) $rows[$plotAt]['time'];
            }
        }

        return [
            'tenkan' => $tenkan,
            'kijun' => $kijun,
            'senkou_a' => $senkouA,
            'senkou_b' => $senkouB,
            'chinkou' => $chinkou,
            'chinkou_times' => $chinkouTimes,
        ];
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @param  array<string,mixed>|null  $params
     * @return array{series: array<string, list<array{time:int,value:float}>>, style: array<string,mixed>}
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $raw = $this->computeSeries($rows, $p);
        $series = [];

        if ((bool) $p['show_tenkan']) {
            $series['tenkan'] = OsEngineCandleMath::packSeries($rows, $raw['tenkan']);
        }
        if ((bool) $p['show_kijun']) {
            $series['kijun'] = OsEngineCandleMath::packSeries($rows, $raw['kijun']);
        }
        if ((bool) $p['show_senkou_a']) {
            $series['senkou_a'] = OsEngineCandleMath::packSeries($rows, $raw['senkou_a']);
        }
        if ((bool) $p['show_senkou_b']) {
            $series['senkou_b'] = OsEngineCandleMath::packSeries($rows, $raw['senkou_b']);
        }
        if ((bool) $p['show_chinkou']) {
            // Chinkou — точки на сдвинутых барах (time бара t, value = close t+span).
            $chinkouPoints = [];
            $n = count($rows);
            for ($i = 0; $i < $n; $i++) {
                if ($raw['chinkou'][$i] == 0.0 && $raw['chinkou_times'][$i] === 0) {
                    continue;
                }
                $chinkouPoints[] = [
                    'time' => (int) $rows[$i]['time'],
                    'value' => (float) $raw['chinkou'][$i],
                ];
            }
            if ($chinkouPoints !== []) {
                $series['chinkou'] = $chinkouPoints;
            }
        }

        return [
            'series' => $series,
            'style' => [
                'color_tenkan' => (string) $p['color_tenkan'],
                'color_kijun' => (string) $p['color_kijun'],
                'color_senkou_a' => (string) $p['color_senkou_a'],
                'color_senkou_b' => (string) $p['color_senkou_b'],
                'color_chinkou' => (string) $p['color_chinkou'],
                'line_width' => max(1, (int) $p['line_width']),
                'chinkou_point_radius' => max(1, (int) $p['chinkou_point_radius']),
            ],
        ];
    }

    /**
     * GetLine из Ichimoku.cs: mid(high, low) окна length с опциональным shift назад.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function getLine(array $rows, int $index, int $length, int $shift): float
    {
        $index = $index - $shift;
        $n = count($rows);

        if ($index < 0) {
            return (float) $rows[$n - 1]['close'];
        }

        $high = 0.0;
        $low = PHP_FLOAT_MAX;

        for ($i = $index; $i > -1 && $i > $index - $length; $i--) {
            $h = (float) $rows[$i]['high'];
            $l = (float) $rows[$i]['low'];
            if ($h > $high) {
                $high = $h;
            }
            if ($l < $low) {
                $low = $l;
            }
        }

        return ($low + $high) / 2.0;
    }

    /**
     * GetLineLate: close свечи index + chinkouSpan (если есть), иначе последний close.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     */
    private function getLineLate(array $rows, int $index, int $chinkouSpan): float
    {
        $n = count($rows);
        if ($index + $chinkouSpan >= $n) {
            return (float) $rows[$n - 1]['close'];
        }

        return (float) $rows[$index + $chinkouSpan]['close'];
    }

    /**
     * GetLineFirst: Senkou A = (kijun[i-disp] + tenkan[i-disp]) / 2 с прогревом OsEngine.
     *
     * @param  list<float>  $kijunInternal
     * @param  list<float>  $tenkanInternal
     */
    private function getLineFirst(array $kijunInternal, array $tenkanInternal, int $index, int $displacement, int $tenkanLen): float
    {
        if ($displacement >= $index + 1
            || $tenkanLen >= $index + 1
            || $index - $displacement < $displacement) {
            return 0.0;
        }

        $src = $index - $displacement;

        return ($kijunInternal[$src] + $tenkanInternal[$src]) / 2.0;
    }
}
