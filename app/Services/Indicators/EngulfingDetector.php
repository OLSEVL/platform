<?php

namespace App\Services\Indicators;

/**
 * Детектор 3-шагового поглощения (engulfing) по стратегии автора.
 *
 * Цепочка входа (только после close 3-й свечи цепочки):
 *   1) Поглощение — контекст (CALL ↑ или PUT ↓), без входа;
 *   2) Реакция — свеча противоположного цвета (в окне 1…N баров);
 *   3) Контр-поглощение — тело перекрывает реакцию в сторону шага 1 → вход.
 *
 * Со стримов Ihj/Vun (сен 2026):
 *   - опционально шаг 1 у фрактального FH/FL;
 *   - skip «уход» (импульс без реакции);
 *   - skip при закрепе пробоя уровня (ветка E, не D).
 */
class EngulfingDetector
{
    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     * @param  array{
     *   allow_classic?:bool,
     *   allow_same_color?:bool,
     *   require_level?:bool,
     *   level_lookback?:int,
     *   level_touch_pct?:float,
     *   reaction_max_bars?:int,
     *   skip_if_left?:bool,
     *   skip_if_breakout_hold?:bool,
     *   breakout_hold_closes?:int
     * }  $options
     * @return list<array<string,mixed>>
     */
    public function detect(array $candles, array $options = []): array
    {
        $allowClassic = (bool) ($options['allow_classic'] ?? true);
        $allowSameColor = (bool) ($options['allow_same_color'] ?? true);
        $requireLevel = (bool) ($options['require_level'] ?? false);
        $levelLookback = max(10, (int) ($options['level_lookback'] ?? 40));
        $levelTouchPct = max(0.01, (float) ($options['level_touch_pct'] ?? 0.15));
        $reactionMaxBars = max(1, min(8, (int) ($options['reaction_max_bars'] ?? 1)));
        $skipIfLeft = (bool) ($options['skip_if_left'] ?? false);
        $skipIfBreakoutHold = (bool) ($options['skip_if_breakout_hold'] ?? false);
        $breakoutHoldCloses = max(1, min(4, (int) ($options['breakout_hold_closes'] ?? 2)));

        $entries = [];
        $n = count($candles);

        // Нужны минимум 4 бара: prev + шаг1 + шаг2 + шаг3 (вход).
        if ($n < 4) {
            return [];
        }

        for ($i = 1; $i < $n - 2; $i++) {
            $step1 = $this->detectStep1($candles, $i, $allowClassic, $allowSameColor);
            if ($step1 === null) {
                continue;
            }

            $side = (string) $step1['side'];

            // Со стрима: поглощение читается у уровня (фрактал FH/FL).
            if ($requireLevel && ! $this->nearFractalLevel($candles, $i, $side, $levelLookback, $levelTouchPct)) {
                continue;
            }

            $maxJ = min($i + $reactionMaxBars, $n - 2);
            $reactionIndex = null;
            $leftRun = 0;

            for ($j = $i + 1; $j <= $maxJ; $j++) {
                // «Уход»: подряд свечи в сторону шага 1 без реакции → пропуск.
                if ($skipIfLeft && $this->isContinuationCandle($candles[$j], $side)) {
                    $leftRun++;
                    if ($leftRun >= 2) {
                        $reactionIndex = null;
                        break;
                    }
                } else {
                    $leftRun = 0;
                }

                if ($this->isReactionCandle($candles[$j], $side)) {
                    $reactionIndex = $j;
                    break;
                }
            }

            if ($reactionIndex === null) {
                continue;
            }

            $entryIndex = $reactionIndex + 1;
            if ($entryIndex >= $n) {
                continue;
            }

            // Ветка E: close закрепился за уровнем до/на входе → не fade D.
            if ($skipIfBreakoutHold
                && $this->hasBreakoutHold($candles, $i, $entryIndex, $side, $levelLookback, $breakoutHoldCloses)) {
                continue;
            }

            if (! $this->isCounterEngulf($candles[$entryIndex], $candles[$reactionIndex], $side)) {
                continue;
            }

            $entryCandle = $candles[$entryIndex];
            $level = $this->nearestImportantLevel($candles, $i, $side, $levelLookback);
            $row = [
                'setup' => 'engulf_entry',
                'kind' => 'engulf',
                'side' => $side,
                'engulf_kind' => (string) $step1['engulf_kind'],
                'bar_index' => $entryIndex,
                'time' => (int) $entryCandle['time'],
                'entry_price' => (float) $entryCandle['close'],
                'step1_bar_index' => $i,
                'step1_time' => (int) $candles[$i]['time'],
                'step2_bar_index' => $reactionIndex,
                'step2_time' => (int) $candles[$reactionIndex]['time'],
                'step3_bar_index' => $entryIndex,
                'step3_time' => (int) $entryCandle['time'],
                'reaction_gap_bars' => $reactionIndex - $i,
            ];
            if ($level !== null) {
                $row['level_price'] = $level['price'];
                $row['level_type'] = $level['type'];
                $row['level_time'] = $level['time'];
                $row['level_dist_pct'] = $level['dist_pct'];
            }
            $entries[] = $row;
        }

        return $entries;
    }

    /**
     * Шаг 1: первое поглощение — только контекст, не вход.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     * @return array{side:string,engulf_kind:string}|null
     */
    private function detectStep1(array $candles, int $index, bool $allowClassic, bool $allowSameColor): ?array
    {
        $prev = $candles[$index - 1];
        $cur = $candles[$index];

        // Классика: зелёная «съела» красную → контекст вверх (CALL).
        if ($allowClassic
            && $this->isGreen($cur)
            && $this->isRed($prev)
            && $this->bodyEngulfs($cur, $prev)) {
            return ['side' => 'CALL', 'engulf_kind' => 'classic'];
        }

        // Классика: красная «съела» зелёную → контекст вниз (PUT).
        if ($allowClassic
            && $this->isRed($cur)
            && $this->isGreen($prev)
            && $this->bodyEngulfs($cur, $prev)) {
            return ['side' => 'PUT', 'engulf_kind' => 'classic'];
        }

        // Same-color: зелёная сняла high соседа и закрылась зелёной.
        if ($allowSameColor
            && $this->isGreen($cur)
            && $this->isGreen($prev)
            && $this->bodyEngulfs($cur, $prev)
            && (float) $cur['high'] > (float) $prev['high']) {
            return ['side' => 'CALL', 'engulf_kind' => 'same_color'];
        }

        // Same-color: красная сняла low соседа и закрылась красной.
        if ($allowSameColor
            && $this->isRed($cur)
            && $this->isRed($prev)
            && $this->bodyEngulfs($cur, $prev)
            && (float) $cur['low'] < (float) $prev['low']) {
            return ['side' => 'PUT', 'engulf_kind' => 'same_color'];
        }

        return null;
    }

    /** Реакция (шаг 2): свеча против направления первого поглощения. */
    private function isReactionCandle(array $candle, string $side): bool
    {
        return $side === 'CALL' ? $this->isRed($candle) : $this->isGreen($candle);
    }

    /** Продолжение в сторону шага 1 (для фильтра «уход»). */
    private function isContinuationCandle(array $candle, string $side): bool
    {
        return $side === 'CALL' ? $this->isGreen($candle) : $this->isRed($candle);
    }

    /**
     * Контр-поглощение (шаг 3): текущая свеча перекрывает тело реакции
     * в сторону первого импульса (CALL → зелёная, PUT → красная).
     */
    private function isCounterEngulf(array $counter, array $reaction, string $side): bool
    {
        if ($side === 'CALL') {
            return $this->isGreen($counter) && $this->bodyEngulfs($counter, $reaction);
        }

        return $this->isRed($counter) && $this->bodyEngulfs($counter, $reaction);
    }

    /**
     * Шаг 1 рядом с локальным фракталом FH (для PUT-контекста) или FL (для CALL).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $candles
     */
    private function nearFractalLevel(
        array $candles,
        int $step1Index,
        string $side,
        int $lookback,
        float $touchPct,
    ): bool {
        $levels = $this->fractalLevels($candles, $step1Index, $lookback);
        if ($levels === []) {
            return false;
        }

        $step = $candles[$step1Index];
        $ref = $side === 'PUT'
            ? (float) $step['high']
            : (float) $step['low'];
        // Также проверяем close шага 1 — автор часто указывает на тело у линии.
        $refs = [$ref, (float) $step['close'], (float) $step['open']];

        foreach ($levels as $lvl) {
            // PUT-контекст — интересны FH; CALL — FL. Чужой тип тоже допускаем (роль разворота).
            foreach ($refs as $price) {
                if ($price <= 0.0) {
                    continue;
                }
                $distPct = abs($price - $lvl) / $lvl * 100.0;
                if ($distPct <= $touchPct) {
                    return true;
                }
            }
            // Касание тенью уровня.
            if ((float) $step['low'] <= $lvl && (float) $step['high'] >= $lvl) {
                return true;
            }
        }

        return false;
    }

    /**
     * 3-барные фракталы до step1 (не включая будущие бары).
     *
     * @param  list<array{high:float,low:float}>  $candles
     * @return list<float>
     */
    private function fractalLevels(array $candles, int $step1Index, int $lookback): array
    {
        return array_map(
            static fn (array $row): float => (float) $row['price'],
            $this->fractalLevelPoints($candles, $step1Index, $lookback)
        );
    }

    /**
     * Фракталы FH/FL с временем бара — для линии «ближайший уровень» на PNG.
     *
     * @param  list<array{time?:int,high:float,low:float}>  $candles
     * @return list<array{price:float,type:string,time:int}>
     */
    private function fractalLevelPoints(array $candles, int $step1Index, int $lookback): array
    {
        $from = max(1, $step1Index - $lookback);
        $to = $step1Index;
        $levels = [];

        for ($i = $from; $i <= $to; $i++) {
            if ($i < 1 || $i >= count($candles) - 1) {
                continue;
            }
            $h = (float) $candles[$i]['high'];
            $l = (float) $candles[$i]['low'];
            $hL = (float) $candles[$i - 1]['high'];
            $hR = (float) $candles[$i + 1]['high'];
            $lL = (float) $candles[$i - 1]['low'];
            $lR = (float) $candles[$i + 1]['low'];
            $t = (int) ($candles[$i]['time'] ?? 0);

            if ($h >= $hL && $h >= $hR) {
                $levels[] = ['price' => $h, 'type' => 'FH', 'time' => $t];
            }
            if ($l <= $lL && $l <= $lR) {
                $levels[] = ['price' => $l, 'type' => 'FL', 'time' => $t];
            }
        }

        return $levels;
    }

    /**
     * Ближайший важный уровень к входу/шагу 1:
     * CALL → FL снизу (поддержка), PUT → FH сверху (сопротивление).
     * Нужен, чтобы на графике видеть, сработает ли отбой.
     *
     * @param  list<array{time?:int,open?:float,high:float,low:float,close?:float}>  $candles
     * @return array{price:float,type:string,time:int,dist_pct:float}|null
     */
    public function nearestImportantLevel(
        array $candles,
        int $refIndex,
        string $side,
        int $lookback = 40,
        ?float $refPrice = null,
    ): ?array {
        $side = strtoupper($side);
        if ($refIndex < 0 || $refIndex >= count($candles)) {
            return null;
        }
        $points = $this->fractalLevelPoints($candles, $refIndex, max(10, $lookback));
        if ($points === []) {
            return null;
        }

        $bar = $candles[$refIndex];
        $ref = $refPrice !== null && $refPrice > 0
            ? $refPrice
            : (float) ($bar['close'] ?? (($side === 'PUT') ? $bar['high'] : $bar['low']));
        if ($ref <= 0) {
            return null;
        }

        $preferred = [];
        $fallback = [];
        foreach ($points as $pt) {
            $price = (float) $pt['price'];
            if ($price <= 0) {
                continue;
            }
            $distPct = abs($price - $ref) / $ref * 100.0;
            $row = [
                'price' => $price,
                'type' => (string) $pt['type'],
                'time' => (int) $pt['time'],
                'dist_pct' => round($distPct, 4),
            ];
            $fallback[] = $row;
            if ($side === 'CALL' && $pt['type'] === 'FL' && $price <= $ref * 1.0001) {
                $preferred[] = $row;
            } elseif ($side === 'PUT' && $pt['type'] === 'FH' && $price >= $ref * 0.9999) {
                $preferred[] = $row;
            }
        }

        $pool = $preferred !== [] ? $preferred : $fallback;
        usort($pool, static fn (array $a, array $b): int => $a['dist_pct'] <=> $b['dist_pct']);

        return $pool[0] ?? null;
    }

    /**
     * N closes подряд за ближайшим FH (CALL-контекст) / FL (PUT) → пробой+закреп, не D.
     *
     * @param  list<array{high:float,low:float,close:float}>  $candles
     */
    private function hasBreakoutHold(
        array $candles,
        int $step1Index,
        int $entryIndex,
        string $side,
        int $lookback,
        int $needCloses,
    ): bool {
        $levels = $this->fractalLevels($candles, $step1Index, $lookback);
        if ($levels === []) {
            return false;
        }

        $step = $candles[$step1Index];
        // Ближайший FH выше шага 1 (CALL) или FL ниже (PUT).
        $barrier = null;
        if ($side === 'CALL') {
            $base = (float) $step['high'];
            foreach ($levels as $lvl) {
                if ($lvl >= $base && ($barrier === null || $lvl < $barrier)) {
                    $barrier = $lvl;
                }
            }
        } else {
            $base = (float) $step['low'];
            foreach ($levels as $lvl) {
                if ($lvl <= $base && ($barrier === null || $lvl > $barrier)) {
                    $barrier = $lvl;
                }
            }
        }
        if ($barrier === null) {
            return false;
        }

        $streak = 0;
        for ($k = $step1Index + 1; $k <= $entryIndex; $k++) {
            $c = (float) $candles[$k]['close'];
            $beyond = $side === 'CALL' ? ($c > $barrier) : ($c < $barrier);
            if ($beyond) {
                $streak++;
                if ($streak >= $needCloses) {
                    return true;
                }
            } else {
                $streak = 0;
            }
        }

        return false;
    }

    /** Тело $engulfing полностью перекрывает тело $engulfed и шире хотя бы с одной стороны. */
    private function bodyEngulfs(array $engulfing, array $engulfed): bool
    {
        $topE = $this->bodyTop($engulfing);
        $botE = $this->bodyBottom($engulfing);
        $topR = $this->bodyTop($engulfed);
        $botR = $this->bodyBottom($engulfed);

        if ($topE < $topR || $botE > $botR) {
            return false;
        }

        return $topE > $topR || $botE < $botR;
    }

    /** @param array{open:float,close:float} $candle */
    private function bodyTop(array $candle): float
    {
        return max((float) $candle['open'], (float) $candle['close']);
    }

    /** @param array{open:float,close:float} $candle */
    private function bodyBottom(array $candle): float
    {
        return min((float) $candle['open'], (float) $candle['close']);
    }

    /** @param array{open:float,close:float} $candle */
    private function isGreen(array $candle): bool
    {
        return (float) $candle['close'] > (float) $candle['open'];
    }

    /** @param array{open:float,close:float} $candle */
    private function isRed(array $candle): bool
    {
        return (float) $candle['close'] < (float) $candle['open'];
    }
}
