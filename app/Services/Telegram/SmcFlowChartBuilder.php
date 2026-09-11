<?php

namespace App\Services\Telegram;

/**
 * PNG-алерт Telegram для бота smc_flow: метки UT / 3BR и зона LuxAlgo Order Block.
 * Берёт те же features, что вернула SmcFlowStrategy на входе (без повторного расчёта индикатора).
 */
class SmcFlowChartBuilder
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $closedCandles
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
    public function chartLevelsFromDecision(array $decision, array $closedCandles): array
    {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $side = strtoupper((string) ($decision['side'] ?? ''));
        $ctx = is_array($features['signal_context'] ?? null) ? $features['signal_context'] : [];
        $diagnosis = is_array($features['entry_diagnosis'] ?? null) ? $features['entry_diagnosis'] : [];

        $lastBar = $closedCandles !== [] ? $closedCandles[array_key_last($closedCandles)] : null;
        $entryTime = is_array($lastBar) ? (int) ($lastBar['time'] ?? 0) : 0;
        $entryPrice = is_array($lastBar) ? (float) ($lastBar['close'] ?? 0) : 0.0;

        $ut = is_array($ctx['ut'] ?? null) ? $ctx['ut'] : [];
        $threeBr = is_array($ctx['three_br'] ?? null) ? $ctx['three_br'] : [];
        $ob = is_array($ctx['ob'] ?? null) ? $ctx['ob'] : [];

        $isCall = $side === 'CALL';
        $utColor = $isCall ? '#22c55e' : '#f43f5e';
        $brColor = $isCall ? '#38bdf8' : '#fb923c';

        $markers = [];

        $utTime = (int) ($ut['bar_time'] ?? 0);
        if ($utTime > 0) {
            $markers[] = [
                'time' => $utTime,
                'price' => $this->priceAt($closedCandles, $utTime, $entryPrice),
                'text' => $isCall ? 'UT BUY' : 'UT SELL',
                'color' => $utColor,
                'position' => $isCall ? 'below' : 'above',
            ];
        }

        $confirmTime = $isCall
            ? (int) ($threeBr['bull_confirm_time'] ?? 0)
            : (int) ($threeBr['bear_confirm_time'] ?? 0);
        $source = (string) ($diagnosis['three_br_source'] ?? 'confirm');
        if ($confirmTime > 0) {
            $markers[] = [
                'time' => $confirmTime,
                'price' => $this->priceAt($closedCandles, $confirmTime, $entryPrice),
                'text' => $source === 'pattern' ? '3BR PAT' : '3BR ?',
                'color' => $brColor,
                'position' => $isCall ? 'below' : 'above',
            ];
        }

        $zones = [];
        $zoneList = $isCall
            ? (is_array($ob['bull_zones'] ?? null) ? $ob['bull_zones'] : [])
            : (is_array($ob['bear_zones'] ?? null) ? $ob['bear_zones'] : []);
        // На PNG достаточно ближайшей к цене активной зоны — иначе несколько OB забивают картинку.
        $nearest = $this->nearestObZone($zoneList, $entryPrice);
        if ($nearest !== null) {
            $zones[] = [
                'price_low' => (float) $nearest['btm'],
                'price_high' => (float) $nearest['top'],
            ];
        }

        $obOk = (bool) ($diagnosis['ob_ok'] ?? false);
        $legendParts = ['UT='.($isCall ? 'Buy' : 'Sell')];
        $legendParts[] = '3BR='.($source !== '' ? $source : 'confirm');
        $legendParts[] = $obOk ? 'OB=да' : 'OB=выкл';

        return [
            'levels' => [],
            'zones' => $zones,
            'entry' => $entryTime > 0 ? [
                'time' => $entryTime,
                'price' => $entryPrice,
                'side' => $side,
            ] : null,
            'segments' => [],
            'markers' => $markers,
            'subtitle' => 'SMC Flow · UT + 3BR'.($obOk ? ' + OB' : '').' · '.$side,
            'legend' => implode(' · ', $legendParts),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function priceAt(array $candles, int $time, float $fallback): float
    {
        foreach ($candles as $candle) {
            if ((int) ($candle['time'] ?? 0) === $time) {
                return (float) ($candle['close'] ?? $fallback);
            }
        }

        return $fallback;
    }

    /**
     * @param  list<array{top?:float,btm?:float,left_time?:int}>  $zones
     * @return array{top:float,btm:float}|null
     */
    private function nearestObZone(array $zones, float $price): ?array
    {
        $best = null;
        $bestDist = INF;
        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $top = (float) ($zone['top'] ?? 0);
            $btm = (float) ($zone['btm'] ?? 0);
            if ($top <= $btm) {
                continue;
            }
            $mid = ($top + $btm) / 2.0;
            $dist = abs($price - $mid);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = ['top' => $top, 'btm' => $btm];
            }
        }

        return $best;
    }
}
