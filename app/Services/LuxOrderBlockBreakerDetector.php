<?php

namespace App\Services;

/**
 * LuxAlgo «Order Blocks & Breaker Blocks» — порт Pine Script из orderblock.txt.
 *
 * Логика:
 * 1) swings(length) — swing high/low по lookback;
 * 2) bullish OB — при close > swing high ищем свечу с минимальным low (или телом) между swing и текущим баром;
 * 3) bearish OB — при close < swing low ищем свечу с максимальным high;
 * 4) breaker — bullish OB ломается, если min(open, close) < btm; bearish — если max(open, close) > top;
 * 5) после breaker зона удаляется, если close пробивает противоположную границу.
 */
class LuxOrderBlockBreakerDetector
{
    private const TYPE_OB_BULL = 'order_block_bull';

    private const TYPE_OB_BEAR = 'order_block_bear';

    private const TYPE_BREAK_BULL = 'order_block_bull_break';

    private const TYPE_BREAK_BEAR = 'order_block_bear_break';

    public function __construct(
        private int $length = 10,
        private int $showBull = 3,
        private int $showBear = 3,
        private bool $useBody = false,
    ) {
        $this->length = max(3, $this->length);
        $this->showBull = max(0, $this->showBull);
        $this->showBear = max(0, $this->showBear);
    }

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float}>  $candles  по возрастанию time
     * @return array<int, array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}>
     */
    public function activeZones(array $candles): array
    {
        $n = count($candles);
        if ($n < $this->length + 2) {
            return [];
        }

        $time = array_column($candles, 'time');
        $open = array_map('floatval', array_column($candles, 'open'));
        $high = array_map('floatval', array_column($candles, 'high'));
        $low = array_map('floatval', array_column($candles, 'low'));
        $close = array_map('floatval', array_column($candles, 'close'));

        // max/min — high/low или тело свечи (опция Use Candle Body в Pine).
        $max = [];
        $min = [];
        for ($i = 0; $i < $n; $i++) {
            if ($this->useBody) {
                $max[$i] = max($open[$i], $close[$i]);
                $min[$i] = min($open[$i], $close[$i]);
            } else {
                $max[$i] = $high[$i];
                $min[$i] = $low[$i];
            }
        }

        $len = $this->length;
        $os = 0;
        /** @var array{y: ?float, x: ?int, crossed: bool} */
        $top = ['y' => null, 'x' => null, 'crossed' => false];
        /** @var array{y: ?float, x: ?int, crossed: bool} */
        $btm = ['y' => null, 'x' => null, 'crossed' => false];

        /** @var list<array{top: float, btm: float, loc: int, breaker: bool, break_loc: ?int}> */
        $bullishOb = [];
        /** @var list<array{top: float, btm: float, loc: int, breaker: bool, break_loc: ?int}> */
        $bearishOb = [];

        for ($bar = 0; $bar < $n; $bar++) {
            $upper = $this->seriesHighest($high, $bar, $len);
            $lower = $this->seriesLowest($low, $bar, $len);
            $prevOs = $os;

            // swings(len): os=0 — swing high, os=1 — swing low.
            if ($bar >= $len) {
                if ($high[$bar - $len] > $upper) {
                    $os = 0;
                } elseif ($low[$bar - $len] < $lower) {
                    $os = 1;
                }
            }

            if ($os === 0 && $prevOs !== 0) {
                $idx = $bar - $len;
                $top = ['y' => $high[$idx], 'x' => $idx, 'crossed' => false];
            }
            if ($os === 1 && $prevOs !== 1) {
                $idx = $bar - $len;
                $btm = ['y' => $low[$idx], 'x' => $idx, 'crossed' => false];
            }

            // Bullish OB: пробой swing high вверх.
            if ($top['y'] !== null && $top['x'] !== null && $close[$bar] > $top['y'] && ! $top['crossed']) {
                $top['crossed'] = true;
                $bullishOb = [
                    $this->findBullishObCandle($max, $min, $time, $bar, $top['x']),
                    ...$bullishOb,
                ];
            }

            // Обновление состояния bullish OB (breaker / удаление).
            for ($i = count($bullishOb) - 1; $i >= 0; $i--) {
                $element = $bullishOb[$i];
                if (! $element['breaker']) {
                    if (min($close[$bar], $open[$bar]) < $element['btm']) {
                        $element['breaker'] = true;
                        $element['break_loc'] = (int) $time[$bar];
                        $bullishOb[$i] = $element;
                    }
                } elseif ($close[$bar] > $element['top']) {
                    array_splice($bullishOb, $i, 1);
                }
            }

            // Bearish OB: пробой swing low вниз.
            if ($btm['y'] !== null && $btm['x'] !== null && $close[$bar] < $btm['y'] && ! $btm['crossed']) {
                $btm['crossed'] = true;
                $bearishOb = [
                    $this->findBearishObCandle($max, $min, $time, $bar, $btm['x']),
                    ...$bearishOb,
                ];
            }

            // Обновление состояния bearish OB.
            for ($i = count($bearishOb) - 1; $i >= 0; $i--) {
                $element = $bearishOb[$i];
                if (! $element['breaker']) {
                    if (max($close[$bar], $open[$bar]) > $element['top']) {
                        $element['breaker'] = true;
                        $element['break_loc'] = (int) $time[$bar];
                        $bearishOb[$i] = $element;
                    }
                } elseif ($close[$bar] < $element['btm']) {
                    array_splice($bearishOb, $i, 1);
                }
            }
        }

        $lastTime = (int) $time[$n - 1];

        return $this->emitDisplayZones($bullishOb, $bearishOb, $lastTime);
    }

    /**
     * Свеча с минимальным min между swing high и текущим баром (Pine: for i = 1 to (n - top.x)-1).
     *
     * @param  list<float>  $max
     * @param  list<float>  $min
     * @param  list<int>  $time
     * @return array{top: float, btm: float, loc: int, breaker: bool, break_loc: ?int}
     */
    private function findBullishObCandle(array $max, array $min, array $time, int $bar, int $swingX): array
    {
        $minima = $min[$bar - 1];
        $maxima = $max[$bar - 1];
        $loc = (int) $time[$bar - 1];

        $limit = $bar - $swingX - 1;
        for ($j = 1; $j <= $limit; $j++) {
            $idx = $bar - $j;
            $candidateMin = $min[$idx];
            $newMinima = min($candidateMin, $minima);
            if ($newMinima === $candidateMin) {
                $minima = $newMinima;
                $maxima = $max[$idx];
                $loc = (int) $time[$idx];
            } else {
                $minima = $newMinima;
            }
        }

        return [
            'top' => $maxima,
            'btm' => $minima,
            'loc' => $loc,
            'breaker' => false,
            'break_loc' => null,
        ];
    }

    /**
     * Свеча с максимальным max между swing low и текущим баром.
     *
     * @param  list<float>  $max
     * @param  list<float>  $min
     * @param  list<int>  $time
     * @return array{top: float, btm: float, loc: int, breaker: bool, break_loc: ?int}
     */
    private function findBearishObCandle(array $max, array $min, array $time, int $bar, int $swingX): array
    {
        $minima = $min[$bar - 1];
        $maxima = $max[$bar - 1];
        $loc = (int) $time[$bar - 1];

        $limit = $bar - $swingX - 1;
        for ($j = 1; $j <= $limit; $j++) {
            $idx = $bar - $j;
            $candidateMax = $max[$idx];
            $newMaxima = max($candidateMax, $maxima);
            if ($newMaxima === $candidateMax) {
                $maxima = $newMaxima;
                $minima = $min[$idx];
                $loc = (int) $time[$idx];
            } else {
                $maxima = $newMaxima;
            }
        }

        return [
            'top' => $maxima,
            'btm' => $minima,
            'loc' => $loc,
            'breaker' => false,
            'break_loc' => null,
        ];
    }

    /**
     * На последнем баре Pine рисует showBull/showBear последних OB (включая breaker-сегменты).
     *
     * @param  list<array{top: float, btm: float, loc: int, breaker: bool, break_loc: ?int}>  $bullishOb
     * @param  list<array{top: float, btm: float, loc: int, breaker: bool, break_loc: ?int}>  $bearishOb
     * @return array<int, array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}>
     */
    private function emitDisplayZones(array $bullishOb, array $bearishOb, int $lastTime): array
    {
        $zones = [];

        if ($this->showBull > 0) {
            $count = min($this->showBull, count($bullishOb));
            for ($i = 0; $i < $count; $i++) {
                $zones = array_merge($zones, $this->zonesForOb($bullishOb[$i], $lastTime, true));
            }
        }

        if ($this->showBear > 0) {
            $count = min($this->showBear, count($bearishOb));
            for ($i = 0; $i < $count; $i++) {
                $zones = array_merge($zones, $this->zonesForOb($bearishOb[$i], $lastTime, false));
            }
        }

        return $zones;
    }

    /**
     * @param  array{top: float, btm: float, loc: int, breaker: bool, break_loc: ?int}  $ob
     * @return array<int, array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}>
     */
    private function zonesForOb(array $ob, int $lastTime, bool $bullish): array
    {
        $low = round($ob['btm'], 5);
        $high = round($ob['top'], 5);
        $formedAt = (int) $ob['loc'];

        if ($ob['breaker'] && $ob['break_loc'] !== null) {
            $breakTime = (int) $ob['break_loc'];

            return [
                $this->zonePayload(
                    $bullish ? self::TYPE_OB_BULL : self::TYPE_OB_BEAR,
                    $formedAt,
                    $breakTime,
                    $low,
                    $high,
                    $formedAt,
                ),
                $this->zonePayload(
                    $bullish ? self::TYPE_BREAK_BULL : self::TYPE_BREAK_BEAR,
                    $breakTime,
                    $lastTime,
                    $low,
                    $high,
                    $formedAt,
                ),
            ];
        }

        return [
            $this->zonePayload(
                $bullish ? self::TYPE_OB_BULL : self::TYPE_OB_BEAR,
                $formedAt,
                $lastTime,
                $low,
                $high,
                $formedAt,
            ),
        ];
    }

    /**
     * @return array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}
     */
    private function zonePayload(string $type, int $timeStart, int $timeEnd, float $low, float $high, int $formedAt): array
    {
        return [
            'type' => $type,
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
            'price_low' => $low,
            'price_high' => $high,
            'formed_at' => $formedAt,
        ];
    }

    /** @param  list<float>  $series */
    private function seriesHighest(array $series, int $i, int $len): float
    {
        $start = max(0, $i - $len + 1);

        return max(array_slice($series, $start, $i - $start + 1));
    }

    /** @param  list<float>  $series */
    private function seriesLowest(array $series, int $i, int $len): float
    {
        $start = max(0, $i - $len + 1);

        return min(array_slice($series, $start, $i - $start + 1));
    }
}
