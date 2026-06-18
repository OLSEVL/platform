<?php

namespace App\Services;

use App\Services\Indicators\OrderBlocksIndicatorService;

/**
 * Детекция ордерблоков (LuxAlgo OB & Breaker Blocks) и имбалансов (FVG) по свечам.
 */
class CandleStructureZoneDetector
{
    private const TYPE_FVG_BULL = 'imbalance_bull';

    private const TYPE_FVG_BEAR = 'imbalance_bear';

    public function __construct(
        private ?OrderBlocksIndicatorService $orderBlocksService = null,
        private ?LuxOrderBlockBreakerDetector $orderBlockDetector = null,
        /** @var array<string, mixed>|null */
        private ?array $orderBlockParams = null,
    ) {}

    private function orderBlocksService(): OrderBlocksIndicatorService
    {
        return $this->orderBlocksService ??= new OrderBlocksIndicatorService;
    }

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float}>  $candles  по возрастанию time
     * @param  array<string, mixed>|null  $orderBlockParams  настройки ордерблоков (из indicator_settings бота)
     * @return array<int, array{type: string, time_start: int, time_end: int, price_low: float, price_high: float, formed_at: int}>
     */
    public function activeZones(array $candles, ?array $orderBlockParams = null): array
    {
        if (count($candles) < 3) {
            return [];
        }

        $n = count($candles);
        $lastTime = (int) $candles[$n - 1]['time'];
        $obParams = array_replace(
            OrderBlocksIndicatorService::defaultParams(),
            $this->orderBlockParams ?? [],
            $orderBlockParams ?? [],
        );

        $service = $this->orderBlocksService();
        $detector = $this->orderBlockDetector ?? $service->createDetector($obParams);
        $zones = $service->applyStylesToZones($detector->activeZones($candles), $obParams);

        for ($i = 2; $i < $n; $i++) {
            $zones = array_merge($zones, $this->fairValueGapsAt($candles, $i, $lastTime));
        }

        return $zones;
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
