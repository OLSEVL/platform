<?php

namespace App\Services\Indicators;

/**
 * Детектор сетапов из стратегии «свип / рейд ликвидности».
 *
 * Один и тот же расчёт используют индикатор на графике и торговая стратегия.
 * Это критично: пользователь видит ровно тот сигнал, который может отправить
 * бот, без расхождения между визуальным слоем и исполнением.
 */
class SweepRaidLiquidityDetector
{
    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $candles
     * @return list<array<string,mixed>>
     */
    public function detect(array $candles, int $pivotLength = 2): array
    {
        $pivotLength = max(1, min(100, $pivotLength));
        $events = [];
        $activeHigh = null;
        $activeLow = null;
        $n = count($candles);

        for ($i = 0; $i < $n; $i++) {
            // Фрактал подтверждается только после pivotLength следующих свечей:
            // бот никогда не использует уровень, который ещё может перерисоваться.
            $pivotIndex = $i - $pivotLength;
            if ($pivotIndex >= $pivotLength) {
                if ($this->isPivotHigh($candles, $pivotIndex, $pivotLength)) {
                    $activeHigh = [
                        'price' => (float) $candles[$pivotIndex]['high'],
                        'time' => (int) $candles[$pivotIndex]['time'],
                        'index' => $pivotIndex,
                        'outside_closes' => 0,
                    ];
                }
                if ($this->isPivotLow($candles, $pivotIndex, $pivotLength)) {
                    $activeLow = [
                        'price' => (float) $candles[$pivotIndex]['low'],
                        'time' => (int) $candles[$pivotIndex]['time'],
                        'index' => $pivotIndex,
                        'outside_closes' => 0,
                    ];
                }
            }

            $candle = $candles[$i];
            $open = (float) $candle['open'];
            $high = (float) $candle['high'];
            $low = (float) $candle['low'];
            $close = (float) $candle['close'];
            $isGreen = $close > $open;
            $isRed = $close < $open;

            if ($activeHigh !== null && $i > $activeHigh['index']) {
                $level = (float) $activeHigh['price'];
                // Свип хая: зелёная свеча сняла максимум тенью и закрылась под ним.
                if ($high > $level && $close < $level && $isGreen) {
                    $events[] = $this->event('sweep', 'high', 'PUT', $i, $candle, $activeHigh, $level);
                // Рейд одной свечой: уровень снят, но цвет не удовлетворяет правилу свипа.
                } elseif ($high > $level && $close < $level && $isRed) {
                    $events[] = $this->event('raid', 'high', 'PUT', $i, $candle, $activeHigh, $level);
                // Рейд двумя свечами: предыдущее тело закрепилось сверху, текущее вернулось ниже.
                } elseif ($i > 0
                    && (float) $candles[$i - 1]['close'] > $level
                    && $close < $level) {
                    $events[] = $this->event('raid', 'high', 'PUT', $i, $candle, $activeHigh, $level);
                }
                $activeHigh['outside_closes'] = $close > $level ? $activeHigh['outside_closes'] + 1 : 0;
                // Два последовательных закрытия за уровнем = закреп, уровень больше не торгуем.
                if ($activeHigh['outside_closes'] >= 2) {
                    $activeHigh = null;
                }
            }

            if ($activeLow !== null && $i > $activeLow['index']) {
                $level = (float) $activeLow['price'];
                // Свип лоу: красная свеча сняла минимум тенью и закрылась над ним.
                if ($low < $level && $close > $level && $isRed) {
                    $events[] = $this->event('sweep', 'low', 'CALL', $i, $candle, $activeLow, $level);
                // Рейд одной свечой с «неправильным» цветом.
                } elseif ($low < $level && $close > $level && $isGreen) {
                    $events[] = $this->event('raid', 'low', 'CALL', $i, $candle, $activeLow, $level);
                // Рейд двумя свечами: сначала закрылись ниже, затем вернулись выше.
                } elseif ($i > 0
                    && (float) $candles[$i - 1]['close'] < $level
                    && $close > $level) {
                    $events[] = $this->event('raid', 'low', 'CALL', $i, $candle, $activeLow, $level);
                }
                $activeLow['outside_closes'] = $close < $level ? $activeLow['outside_closes'] + 1 : 0;
                if ($activeLow['outside_closes'] >= 2) {
                    $activeLow = null;
                }
            }
        }

        return $events;
    }

    /**
     * Возвращает первый допустимый откат после каждого события.
     *
     * Для ликвидности сверху ждём первую зелёную свечу и продолжаем вниз (PUT);
     * для ликвидности снизу — первую красную и продолжаем вверх (CALL).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $candles
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    public function pullbackEntries(array $candles, array $events): array
    {
        $entries = [];
        foreach ($events as $event) {
            $start = (int) $event['bar_index'] + 1;
            for ($i = $start, $count = count($candles); $i < $count; $i++) {
                $candle = $candles[$i];
                $open = (float) $candle['open'];
                $close = (float) $candle['close'];
                $matches = $event['side'] === 'PUT' ? $close > $open : $close < $open;
                if ($matches) {
                    $entries[] = array_merge($event, [
                        'setup' => 'pullback',
                        'bar_index' => $i,
                        'time' => (int) $candle['time'],
                        'entry_price' => $close,
                        // Исходное снятие (свип/рейд) нужно графику: time выше перезаписывается на откат.
                        'takedown_time' => (int) $event['time'],
                        'takedown_price' => (float) $event['entry_price'],
                    ]);
                    break;
                }
            }
        }

        return $entries;
    }

    /** @param array<string,mixed> $candle @param array<string,mixed> $level */
    private function event(string $kind, string $location, string $side, int $index, array $candle, array $level, float $price): array
    {
        return [
            'setup' => $kind,
            'kind' => $kind,
            'location' => $location,
            'side' => $side,
            'bar_index' => $index,
            'time' => (int) $candle['time'],
            'entry_price' => (float) $candle['close'],
            'level_price' => $price,
            'level_time' => (int) $level['time'],
            'level_index' => (int) $level['index'],
        ];
    }

    /** @param list<array<string,mixed>> $candles */
    private function isPivotHigh(array $candles, int $index, int $length): bool
    {
        $price = (float) $candles[$index]['high'];
        for ($i = $index - $length; $i <= $index + $length; $i++) {
            if ($i !== $index && (float) $candles[$i]['high'] > $price) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $candles */
    private function isPivotLow(array $candles, int $index, int $length): bool
    {
        $price = (float) $candles[$index]['low'];
        for ($i = $index - $length; $i <= $index + $length; $i++) {
            if ($i !== $index && (float) $candles[$i]['low'] < $price) {
                return false;
            }
        }

        return true;
    }
}
