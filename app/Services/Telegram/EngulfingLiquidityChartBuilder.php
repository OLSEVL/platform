<?php

namespace App\Services\Telegram;

use App\Services\Indicators\EngulfingLiquidityIndicatorService;

/**
 * PNG-алерт Telegram для бота engulfing_liquidity: метки 3 шагов цепочки.
 */
class EngulfingLiquidityChartBuilder
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $closedCandles
     * @param  array<string, mixed>  $indicatorParams
     * @return array{
     *     levels:list<array<string,mixed>>,
     *     zones:list<array<string,mixed>>,
     *     entry:?array<string,mixed>,
     *     segments:list<array<string,mixed>>,
     *     markers:list<array<string,mixed>>,
     *     subtitle:string,
     *     legend:string
     * }
     */
    public function chartLevelsFromDecision(array $decision, array $closedCandles, array $indicatorParams = []): array
    {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $side = strtoupper((string) ($decision['side'] ?? ''));
        $params = array_replace(EngulfingLiquidityIndicatorService::defaultParams(), $indicatorParams);
        $engulfKind = (string) ($features['engulf_kind'] ?? 'classic');

        $step1Time = (int) ($features['step1_time'] ?? 0);
        $step2Time = (int) ($features['step2_time'] ?? 0);
        $step3Time = (int) ($features['step3_time'] ?? 0);
        $entryPrice = (float) ($features['entry_price'] ?? 0);
        $entryTime = (int) ($features['event_time'] ?? $step3Time);

        $lastBar = $closedCandles !== [] ? $closedCandles[array_key_last($closedCandles)] : null;
        if ($entryTime <= 0 && is_array($lastBar)) {
            $entryTime = (int) ($lastBar['time'] ?? 0);
        }
        if ($entryPrice <= 0 && is_array($lastBar)) {
            $entryPrice = (float) ($lastBar['close'] ?? 0);
        }

        $step3Color = $side === 'CALL'
            ? (string) $params['color_step3_call']
            : (string) $params['color_step3_put'];
        $isUp = $side === 'CALL';

        $markers = [];
        $priceAt = static function (array $candles, int $time): float {
            foreach ($candles as $c) {
                if ((int) ($c['time'] ?? 0) === $time) {
                    return (float) ($c['close'] ?? 0);
                }
            }

            return 0.0;
        };

        if ($step1Time > 0) {
            $markers[] = [
                'time' => $step1Time,
                'price' => $priceAt($closedCandles, $step1Time),
                'text' => 'ENGULF 1',
                'color' => (string) $params['color_step1'],
                'position' => $isUp ? 'below' : 'above',
            ];
        }
        if ($step2Time > 0) {
            $markers[] = [
                'time' => $step2Time,
                'price' => $priceAt($closedCandles, $step2Time),
                'text' => 'ENGULF 2',
                'color' => (string) $params['color_step2'],
                'position' => $isUp ? 'above' : 'below',
            ];
        }
        if ($step3Time > 0) {
            $kindTag = $engulfKind === 'same_color' ? ' SC' : '';
            $markers[] = [
                'time' => $step3Time,
                'price' => $entryPrice > 0 ? $entryPrice : $priceAt($closedCandles, $step3Time),
                'text' => 'ENGULF 3 · '.$side.$kindTag,
                'color' => $step3Color,
                'position' => $isUp ? 'below' : 'above',
            ];
        }

        return [
            'levels' => [],
            'zones' => [],
            'entry' => $entryTime > 0 ? [
                'time' => $entryTime,
                'price' => $entryPrice,
                'side' => $side,
            ] : null,
            'segments' => [],
            'markers' => $markers,
            'subtitle' => 'Engulfing 3-step · '.$side,
            'legend' => '1=контекст 2=реакция 3=вход',
        ];
    }
}
