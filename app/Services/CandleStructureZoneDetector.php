<?php

namespace App\Services;

/**
 * Детекция активных (не смягчённых) ордерблоков и имбалансов (FVG) по M1 свечам.
 * Правила смягчения — как в спецификации для коротких экспираций на FX.
 */
class CandleStructureZoneDetector
{
    private const TYPE_OB_BULL = 'order_block_bull';

    private const TYPE_OB_BEAR = 'order_block_bear';

    private const TYPE_FVG_BULL = 'imbalance_bull';

    private const TYPE_FVG_BEAR = 'imbalance_bear';

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float}>  $candles  по возрастанию time
     * @return array<int, array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}>
     */
    public function activeZones(array $candles): array
    {
        if (count($candles) < 3) {
            return [];
        }

        $n = count($candles);
        $lastTime = (int) $candles[$n - 1]['time'];

        $zones = [];

        for ($i = 1; $i < $n - 1; $i++) {
            $zones = array_merge($zones, $this->orderBlocksAt($candles, $i, $lastTime));
        }

        for ($i = 2; $i < $n; $i++) {
            $zones = array_merge($zones, $this->fairValueGapsAt($candles, $i, $lastTime));
        }

        return $zones;
    }

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float}>  $candles
     * @return array<int, array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}>
     */
    private function orderBlocksAt(array $candles, int $impulseIdx, int $lastTime): array
    {
        $out = [];
        $ob = $impulseIdx - 1;
        $imp = $candles[$impulseIdx];
        $base = $candles[$ob];

        $bullishImpulse = $imp['close'] > $imp['open'] && $base['close'] < $base['open']
            && $imp['close'] > $base['high'];
        if ($bullishImpulse) {
            $low = (float) $base['low'];
            $high = (float) $base['high'];
            if (! $this->isBullishObMitigated($candles, $ob + 2, $low, $high)) {
                $out[] = $this->zonePayload(self::TYPE_OB_BULL, (int) $base['time'], $lastTime, $low, $high);
            }
        }

        $bearishImpulse = $imp['close'] < $imp['open'] && $base['close'] > $base['open']
            && $imp['close'] < $base['low'];
        if ($bearishImpulse) {
            $low = (float) $base['low'];
            $high = (float) $base['high'];
            if (! $this->isBearishObMitigated($candles, $ob + 2, $low, $high)) {
                $out[] = $this->zonePayload(self::TYPE_OB_BEAR, (int) $base['time'], $lastTime, $low, $high);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float}>  $candles
     */
    private function isBullishObMitigated(array $candles, int $fromIdx, float $low, float $high): bool
    {
        $n = count($candles);
        for ($j = $fromIdx; $j < $n; $j++) {
            $c = (float) $candles[$j]['close'];
            if ($c >= $low && $c <= $high) {
                return true;
            }
            if ($c < $low) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float}>  $candles
     */
    private function isBearishObMitigated(array $candles, int $fromIdx, float $low, float $high): bool
    {
        $n = count($candles);
        for ($j = $fromIdx; $j < $n; $j++) {
            $c = (float) $candles[$j]['close'];
            if ($c >= $low && $c <= $high) {
                return true;
            }
            if ($c > $high) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float}>  $candles
     * @return array<int, array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}>
     */
    private function fairValueGapsAt(array $candles, int $i, int $lastTime): array
    {
        $out = [];
        $a = $candles[$i - 2];
        $c = $candles[$i];

        $lowC = (float) $c['low'];
        $highA = (float) $a['high'];
        if ($lowC > $highA) {
            $gLow = $highA;
            $gHigh = $lowC;
            if (! $this->isFvgMitigated($candles, $i + 1, $gLow, $gHigh)) {
                $out[] = $this->zonePayload(self::TYPE_FVG_BULL, (int) $a['time'], $lastTime, $gLow, $gHigh);
            }
        }

        $highC = (float) $c['high'];
        $lowA = (float) $a['low'];
        if ($highC < $lowA) {
            $gLow = $highC;
            $gHigh = $lowA;
            if (! $this->isFvgMitigated($candles, $i + 1, $gLow, $gHigh)) {
                $out[] = $this->zonePayload(self::TYPE_FVG_BEAR, (int) $a['time'], $lastTime, $gLow, $gHigh);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float}>  $candles
     */
    private function isFvgMitigated(array $candles, int $fromIdx, float $gLow, float $gHigh): bool
    {
        $n = count($candles);
        for ($j = $fromIdx; $j < $n; $j++) {
            $lo = (float) $candles[$j]['low'];
            $hi = (float) $candles[$j]['high'];
            if ($lo <= $gLow && $hi >= $gHigh) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}
     */
    private function zonePayload(string $type, int $formedAt, int $lastTime, float $low, float $high): array
    {
        return [
            'type' => $type,
            'time_start' => $formedAt,
            'time_end' => $lastTime,
            'price_low' => round($low, 5),
            'price_high' => round($high, 5),
            'formed_at' => $formedAt,
        ];
    }
}
