<?php

namespace App\Services\Indicators;

/**
 * Серверная реализация оверлея «Liquidity Swings» по мотивам Pine-скрипта LuxAlgo (indicator2.txt).
 *
 * Назначение: те же swing high / swing low, полупрозрачные зоны, горизонтали и подписи объёма,
 * что на скриншоте TradingView, но для Lightweight Charts через JSON → HTML-оверлей.
 *
 * Ограничения относительно оригинала:
 * - Intrabar Precision и request.security_lower_tf() не поддерживаются — считаем только по переданным барам.
 * - Детекция пивотов упрощена до симметричного окна (как ta.pivothigh / ta.pivotlow с одинаковыми left/right).
 */
class LiquiditySwingsIndicatorService
{
    /** Дефолты совпадают с inputs Pine (length=14, фильтр Count, порог 0). */
    public const DEFAULT_PARAMS = [
        'length' => 14,
        /** 'wick' = Wick Extremity; 'full' = Full Range */
        'area' => 'wick',
        /** 'count' | 'volume' — что сравниваем с filter_value для отображения зоны */
        'filter_by' => 'count',
        'filter_value' => 0.0,
        'show_swing_high' => true,
        'show_swing_low' => true,
        'color_high_line' => '#ef4444',
        'color_high_fill' => 'rgba(239,68,68,0.38)',
        'color_low_line' => '#14b8a6',
        'color_low_fill' => 'rgba(20,184,166,0.38)',
        /** tiny | small | normal — размер подписей объёма (как Labels Size в Pine). */
        'label_size' => 'tiny',
    ];

    /** @return array<string, mixed> */
    public static function defaultParams(): array
    {
        return self::DEFAULT_PARAMS;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $rows
     * @param  array<string,mixed>|null  $params  поверх DEFAULT_PARAMS
     * @return array{
     *   boxes: list<array{time_start:int,time_end:int,price_high:float,price_low:float,fill:string,border:string}>,
     *   lines: list<array{time_start:int,time_end:int,price:float,color:string,style:string,width:int}>,
     *   labels: list<array{time:int,price:float,text:string,color:string,position:string}>,
     *   label_size: string
     * }
     */
    public function compute(array $rows, ?array $params = null): array
    {
        $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
        $labelSize = $this->normalizeLabelSize((string) ($p['label_size'] ?? 'tiny'));
        $len = max(1, (int) $p['length']);
        $areaWick = ($p['area'] ?? 'wick') !== 'full';
        $filterByVolume = ($p['filter_by'] ?? 'count') === 'volume';
        $fv = (float) $p['filter_value'];
        $showTop = (bool) $p['show_swing_high'];
        $showBtm = (bool) $p['show_swing_low'];
        $cHighLine = (string) $p['color_high_line'];
        $cHighFill = (string) $p['color_high_fill'];
        $cLowLine = (string) $p['color_low_line'];
        $cLowFill = (string) $p['color_low_fill'];

        $n = count($rows);
        if ($n < $len * 2 + 1) {
            return ['boxes' => [], 'lines' => [], 'labels' => []];
        }

        $time = array_column($rows, 'time');
        $open = array_map('floatval', array_column($rows, 'open'));
        $high = array_map('floatval', array_column($rows, 'high'));
        $low = array_map('floatval', array_column($rows, 'low'));
        $close = array_map('floatval', array_column($rows, 'close'));
        $volume = array_map('floatval', array_column($rows, 'volume'));

        $boxesOut = [];
        $linesOut = [];
        $labelsOut = [];

        // --- Состояние swing high (как var в Pine) ---
        $phTop = null;
        $phBtm = null;
        $phX1 = 0;
        $phCrossed = false;
        $phCount = 0;
        $phVol = 0.0;
        $phTargetPrev = 0.0;

        $openPhBox = null;
        $openPhLine = null;
        /** @var array{time:int,price:float,text:string,color:string,position:string}|null */
        $pendingPhLabel = null;

        // --- Состояние swing low ---
        $plTop = null;
        $plBtm = null;
        $plX1 = 0;
        $plCrossed = false;
        $plCount = 0;
        $plVol = 0.0;
        $plTargetPrev = 0.0;

        $openPlBox = null;
        $openPlLine = null;
        /** @var array{time:int,price:float,text:string,color:string,position:string}|null */
        $pendingPlLabel = null;

        $idxTime = static function (int $idx) use ($time, $n): int {
            $idx = max(0, min($n - 1, $idx));

            return (int) $time[$idx];
        };

        for ($i = 0; $i < $n; $i++) {
            $ph = $showTop && $this->pivotHighConfirmed($high, $i, $len);
            $pl = $showBtm && $this->pivotLowConfirmed($low, $i, $len);

            // get_counts(ph, ...) — при новом пивоте обнуляем счётчики ДО обновления уровней.
            if ($ph) {
                $phCount = 0;
                $phVol = 0.0;
            } elseif ($i >= $len && $phTop !== null && $phBtm !== null) {
                $idx = $i - $len;
                if ($idx >= 0 && $idx < $n) {
                    // Pine: low[length] < top and high[length] > btm
                    if ($low[$idx] < $phTop && $high[$idx] > $phBtm) {
                        $phCount++;
                        $phVol += $volume[$idx];
                    }
                }
            }

            if ($ph && $showTop) {
                $piv = $i - $len;
                $phTop = $high[$piv];
                $phBtm = $areaWick ? max($close[$piv], $open[$piv]) : $low[$piv];
                $phX1 = $piv;
                $phCrossed = false;

                if ($openPhBox !== null) {
                    $boxesOut[] = $this->finalizeBox($openPhBox, $idxTime, $cHighFill);
                    $openPhBox = null;
                }
                if ($openPhLine !== null) {
                    $linesOut[] = $this->finalizeLine($openPhLine, $idxTime, $cHighLine);
                    $openPhLine = null;
                }
                if ($pendingPhLabel !== null) {
                    $labelsOut[] = $pendingPhLabel;
                    $pendingPhLabel = null;
                }
            } else {
                if ($phTop !== null) {
                    $phCrossed = $phCrossed || ($close[$i] > $phTop);
                }
            }

            $phTarget = $filterByVolume ? $phVol : (float) $phCount;
            $phCrossUp = ($phTargetPrev <= $fv && $phTarget > $fv);

            if ($showTop && $phTop !== null && $phBtm !== null) {
                $topPx = max($phTop, $phBtm);
                $btmPx = min($phTop, $phBtm);

                if ($phCrossUp) {
                    $openPhBox = [
                        'leftIdx' => $phX1,
                        'rightIdx' => min($phX1 + $phCount, $i, $n - 1),
                        'price_high' => $topPx,
                        'price_low' => $btmPx,
                        'fill' => $cHighFill,
                    ];
                    $pendingPhLabel = [
                        'time' => $idxTime($phX1),
                        'price' => $phTop,
                        'text' => $this->formatVolume($phVol),
                        'color' => $cHighLine,
                        'position' => 'above',
                    ];
                } elseif ($openPhBox !== null && $phTarget > $fv) {
                    $openPhBox['rightIdx'] = min($phX1 + $phCount, $i, $n - 1);
                    $openPhBox['price_high'] = $topPx;
                    $openPhBox['price_low'] = $btmPx;
                }

                if ($phTarget > $fv) {
                    if ($openPhLine === null) {
                        $openPhLine = [
                            'fromIdx' => $phX1,
                            'toIdx' => min($i, $n - 1),
                            'price' => $phTop,
                            'dashed' => $phCrossed,
                        ];
                    } else {
                        $openPhLine['toIdx'] = min($i, $n - 1);
                        $openPhLine['price'] = $phTop;
                        $openPhLine['dashed'] = $phCrossed;
                    }
                    if ($pendingPhLabel !== null) {
                        $pendingPhLabel['text'] = $this->formatVolume($phVol);
                        $pendingPhLabel['price'] = $phTop;
                    }
                } else {
                    if ($openPhLine !== null) {
                        $linesOut[] = $this->finalizeLine($openPhLine, $idxTime, $cHighLine);
                        $openPhLine = null;
                    }
                }
            }

            $phTargetPrev = $phTarget;

            // --- Swing low (зеркально) ---
            if ($pl) {
                $plCount = 0;
                $plVol = 0.0;
            } elseif ($i >= $len && $plTop !== null && $plBtm !== null) {
                $idx = $i - $len;
                if ($idx >= 0 && $idx < $n) {
                    if ($low[$idx] < $plTop && $high[$idx] > $plBtm) {
                        $plCount++;
                        $plVol += $volume[$idx];
                    }
                }
            }

            if ($pl && $showBtm) {
                $piv = $i - $len;
                $plBtm = $low[$piv];
                $plTop = $areaWick ? min($close[$piv], $open[$piv]) : $high[$piv];
                $plX1 = $piv;
                $plCrossed = false;

                if ($openPlBox !== null) {
                    $boxesOut[] = $this->finalizeBox($openPlBox, $idxTime, $cLowFill);
                    $openPlBox = null;
                }
                if ($openPlLine !== null) {
                    $linesOut[] = $this->finalizeLine($openPlLine, $idxTime, $cLowLine);
                    $openPlLine = null;
                }
                if ($pendingPlLabel !== null) {
                    $labelsOut[] = $pendingPlLabel;
                    $pendingPlLabel = null;
                }
            } else {
                if ($plBtm !== null) {
                    $plCrossed = $plCrossed || ($close[$i] < $plBtm);
                }
            }

            $plTarget = $filterByVolume ? $plVol : (float) $plCount;
            $plCrossUp = ($plTargetPrev <= $fv && $plTarget > $fv);

            if ($showBtm && $plTop !== null && $plBtm !== null) {
                $topPx = max($plTop, $plBtm);
                $btmPx = min($plTop, $plBtm);

                if ($plCrossUp) {
                    $openPlBox = [
                        'leftIdx' => $plX1,
                        'rightIdx' => min($plX1 + $plCount, $i, $n - 1),
                        'price_high' => $topPx,
                        'price_low' => $btmPx,
                        'fill' => $cLowFill,
                    ];
                    $pendingPlLabel = [
                        'time' => $idxTime($plX1),
                        'price' => $plBtm,
                        'text' => $this->formatVolume($plVol),
                        'color' => $cLowLine,
                        'position' => 'below',
                    ];
                } elseif ($openPlBox !== null && $plTarget > $fv) {
                    $openPlBox['rightIdx'] = min($plX1 + $plCount, $i, $n - 1);
                    $openPlBox['price_high'] = $topPx;
                    $openPlBox['price_low'] = $btmPx;
                }

                if ($plTarget > $fv) {
                    if ($openPlLine === null) {
                        $openPlLine = [
                            'fromIdx' => $plX1,
                            'toIdx' => min($i, $n - 1),
                            'price' => $plBtm,
                            'dashed' => $plCrossed,
                        ];
                    } else {
                        $openPlLine['toIdx'] = min($i, $n - 1);
                        $openPlLine['price'] = $plBtm;
                        $openPlLine['dashed'] = $plCrossed;
                    }
                    if ($pendingPlLabel !== null) {
                        $pendingPlLabel['text'] = $this->formatVolume($plVol);
                        $pendingPlLabel['price'] = $plBtm;
                    }
                } else {
                    if ($openPlLine !== null) {
                        $linesOut[] = $this->finalizeLine($openPlLine, $idxTime, $cLowLine);
                        $openPlLine = null;
                    }
                }
            }

            $plTargetPrev = $plTarget;
        }

        if ($openPhBox !== null) {
            $boxesOut[] = $this->finalizeBox($openPhBox, $idxTime, $cHighFill);
        }
        if ($openPhLine !== null) {
            $linesOut[] = $this->finalizeLine($openPhLine, $idxTime, $cHighLine);
        }
        if ($openPlBox !== null) {
            $boxesOut[] = $this->finalizeBox($openPlBox, $idxTime, $cLowFill);
        }
        if ($openPlLine !== null) {
            $linesOut[] = $this->finalizeLine($openPlLine, $idxTime, $cLowLine);
        }
        if ($pendingPhLabel !== null) {
            $labelsOut[] = $pendingPhLabel;
        }
        if ($pendingPlLabel !== null) {
            $labelsOut[] = $pendingPlLabel;
        }

        $cap = 320;
        if (count($boxesOut) > $cap) {
            $boxesOut = array_slice($boxesOut, -$cap);
        }
        if (count($linesOut) > $cap) {
            $linesOut = array_slice($linesOut, -$cap);
        }
        if (count($labelsOut) > $cap) {
            $labelsOut = array_slice($labelsOut, -$cap);
        }

        return [
            'boxes' => $boxesOut,
            'lines' => $linesOut,
            'labels' => $labelsOut,
            'label_size' => $labelSize,
        ];
    }

    private function normalizeLabelSize(string $raw): string
    {
        $s = strtolower(trim($raw));

        return in_array($s, ['tiny', 'small', 'normal'], true) ? $s : 'tiny';
    }

    /**
     * @param  list<float>  $high
     */
    private function pivotHighConfirmed(array $high, int $i, int $len): bool
    {
        $p = $i - $len;
        $n = count($high);
        if ($p < $len || $p + $len >= $n) {
            return false;
        }
        $pv = $high[$p];
        for ($k = $p - $len; $k <= $p + $len; $k++) {
            if ($k !== $p && $high[$k] > $pv) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<float>  $low
     */
    private function pivotLowConfirmed(array $low, int $i, int $len): bool
    {
        $p = $i - $len;
        $n = count($low);
        if ($p < $len || $p + $len >= $n) {
            return false;
        }
        $pv = $low[$p];
        for ($k = $p - $len; $k <= $p + $len; $k++) {
            if ($k !== $p && $low[$k] < $pv) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  callable(int):int  $idxTime
     * @return array{time_start:int,time_end:int,price_high:float,price_low:float,fill:string,border:string}
     */
    private function finalizeBox(array $box, callable $idxTime, string $fill): array
    {
        $t0 = $idxTime((int) $box['leftIdx']);
        $t1 = $idxTime((int) $box['rightIdx']);

        return [
            'time_start' => min($t0, $t1),
            'time_end' => max($t0, $t1),
            'price_high' => (float) $box['price_high'],
            'price_low' => (float) $box['price_low'],
            'fill' => $fill,
            'border' => 'none',
        ];
    }

    /**
     * @param  callable(int):int  $idxTime
     * @return array{time_start:int,time_end:int,price:float,color:string,style:string,width:int}
     */
    private function finalizeLine(array $ln, callable $idxTime, string $color): array
    {
        $t0 = $idxTime((int) $ln['fromIdx']);
        $t1 = $idxTime((int) $ln['toIdx']);

        return [
            'time_start' => min($t0, $t1),
            'time_end' => max($t0, $t1),
            'price' => (float) $ln['price'],
            'color' => $color,
            'style' => ! empty($ln['dashed']) ? 'Dashed' : 'Solid',
            'width' => 1,
        ];
    }

    private function formatVolume(float $v): string
    {
        $av = abs($v);
        if ($av >= 1e9) {
            return rtrim(rtrim(number_format($v / 1e9, 2, '.', ''), '0'), '.').'B';
        }
        if ($av >= 1e6) {
            return rtrim(rtrim(number_format($v / 1e6, 2, '.', ''), '0'), '.').'M';
        }
        if ($av >= 1e3) {
            return rtrim(rtrim(number_format($v / 1e3, 2, '.', ''), '0'), '.').'k';
        }
        if ($av >= 1) {
            return (string) (int) round($v);
        }

        return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
    }

}
