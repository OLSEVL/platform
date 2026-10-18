<?php

namespace App\Services\Bots\AuthorFlow;

/**
 * Контекст v1: structure bias по confirmed swings + упрощённая «открытая ликвидность».
 */
final class AuthorFlowContextBuilder implements AuthorFlowContextBuilderInterface
{
    public function build(array $closed, array $config): array
    {
        $pivot = max(1, min(10, (int) ($config['af_structure_pivot'] ?? 2)));
        $lookback = max(10, min(200, (int) ($config['af_liquidity_lookback'] ?? 40)));
        $n = count($closed);

        $swingsHigh = [];
        $swingsLow = [];
        $levels = [];

        // Confirmed fractal pivots (как SRL: индекс подтверждается через pivot баров).
        for ($i = $pivot; $i < $n - $pivot; $i++) {
            if ($this->isPivotHigh($closed, $i, $pivot)) {
                $price = (float) $closed[$i]['high'];
                $time = (int) ($closed[$i]['time'] ?? 0);
                $swingsHigh[] = ['price' => $price, 'time' => $time, 'index' => $i];
                $levels[] = ['price' => $price, 'type' => 'FH', 'time' => $time];
            }
            if ($this->isPivotLow($closed, $i, $pivot)) {
                $price = (float) $closed[$i]['low'];
                $time = (int) ($closed[$i]['time'] ?? 0);
                $swingsLow[] = ['price' => $price, 'time' => $time, 'index' => $i];
                $levels[] = ['price' => $price, 'type' => 'FL', 'time' => $time];
            }
        }

        $lastTwoHigh = array_slice($swingsHigh, -2);
        $lastTwoLow = array_slice($swingsLow, -2);
        $bias = 'range';
        if (count($lastTwoHigh) === 2 && count($lastTwoLow) === 2) {
            $hh = $lastTwoHigh[1]['price'] > $lastTwoHigh[0]['price'];
            $hl = $lastTwoLow[1]['price'] > $lastTwoLow[0]['price'];
            $lh = $lastTwoHigh[1]['price'] < $lastTwoHigh[0]['price'];
            $ll = $lastTwoLow[1]['price'] < $lastTwoLow[0]['price'];
            if ($hh && $hl) {
                $bias = 'up';
            } elseif ($lh && $ll) {
                $bias = 'down';
            }
        }

        $lastSwingHigh = $swingsHigh !== [] ? (float) $swingsHigh[array_key_last($swingsHigh)]['price'] : null;
        $lastSwingLow = $swingsLow !== [] ? (float) $swingsLow[array_key_last($swingsLow)]['price'] : null;

        // Открытая ликвидность: экстремум lookback ещё не снят телом close в сторону.
        $openCall = false;
        $openPut = false;
        if ($n >= 3) {
            $start = max(0, $n - $lookback);
            $minLow = null;
            $maxHigh = null;
            for ($i = $start; $i < $n; $i++) {
                $low = (float) ($closed[$i]['low'] ?? 0);
                $high = (float) ($closed[$i]['high'] ?? 0);
                if ($minLow === null || $low < $minLow) {
                    $minLow = $low;
                }
                if ($maxHigh === null || $high > $maxHigh) {
                    $maxHigh = $high;
                }
            }
            $lastClose = (float) ($closed[$n - 1]['close'] ?? 0);
            // Пул снизу «открыт» для CALL, если close ещё не ушёл далеко ниже minLow (не сняли телом).
            if ($minLow !== null) {
                $openCall = $lastClose > $minLow;
            }
            if ($maxHigh !== null) {
                $openPut = $lastClose < $maxHigh;
            }
        }

        return [
            'structure_bias' => $bias,
            'last_swing_high' => $lastSwingHigh,
            'last_swing_low' => $lastSwingLow,
            'levels' => $levels,
            'open_liquidity_call' => $openCall,
            'open_liquidity_put' => $openPut,
            'config' => $config,
        ];
    }

    /** @param  list<array<string,mixed>>  $candles */
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

    /** @param  list<array<string,mixed>>  $candles */
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
