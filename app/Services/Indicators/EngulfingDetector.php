<?php

namespace App\Services\Indicators;

/**
 * Детектор 3-шагового поглощения (engulfing) по стратегии автора.
 *
 * Цепочка входа (только после close 3-й свечи):
 *   1) Поглощение — контекст (CALL ↑ или PUT ↓), без входа;
 *   2) Реакция — одна свеча противоположного цвета;
 *   3) Контр-поглощение — тело перекрывает реакцию в сторону шага 1 → вход.
 *
 * Поддерживаются классическое engulf (противоположный цвет) и same-color
 * (тот же цвет + снятие ликвидности слева: high/low соседа).
 *
 * Один расчёт используют индикатор на графике и rule-based бот engulfing_liquidity.
 */
class EngulfingDetector
{
    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $candles
     * @param  array{
     *   allow_classic?:bool,
     *   allow_same_color?:bool
     * }  $options
     * @return list<array<string,mixed>>
     */
    public function detect(array $candles, array $options = []): array
    {
        $allowClassic = (bool) ($options['allow_classic'] ?? true);
        $allowSameColor = (bool) ($options['allow_same_color'] ?? true);
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
            $reactionIndex = $i + 1;
            $entryIndex = $i + 2;

            // Шаг 2: ровно одна свеча реакции противоположного цвета.
            if (! $this->isReactionCandle($candles[$reactionIndex], $side)) {
                continue;
            }

            // Шаг 3: контр-поглощение реакции в сторону первого импульса.
            if (! $this->isCounterEngulf($candles[$entryIndex], $candles[$reactionIndex], $side)) {
                continue;
            }

            $entryCandle = $candles[$entryIndex];
            $entries[] = [
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
            ];
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

    /** Реакция (шаг 2): одна свеча против направления первого поглощения. */
    private function isReactionCandle(array $candle, string $side): bool
    {
        return $side === 'CALL' ? $this->isRed($candle) : $this->isGreen($candle);
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
