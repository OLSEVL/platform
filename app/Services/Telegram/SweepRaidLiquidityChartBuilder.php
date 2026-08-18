<?php

namespace App\Services\Telegram;

use App\Services\Indicators\SweepRaidLiquidityIndicatorService;

/**
 * Собирает оверлей Sweep / Raid Liquidity для PNG-алерта в Telegram.
 *
 * На картинке только сетап текущего входа: фрактал, снятие и (если нужно) откат.
 * Исторические SWEEP/RAID/PB не рисуем — иначе они забивают график и левый край.
 */
class SweepRaidLiquidityChartBuilder
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
        $entryMode = (string) ($features['entry_mode'] ?? 'sweep');
        $setup = (string) ($features['setup'] ?? '');
        [$kind, $location] = $this->splitSetup($setup, $entryMode);
        $params = array_replace(SweepRaidLiquidityIndicatorService::defaultParams(), $indicatorParams);
        $levelPrice = (float) ($features['level_price'] ?? 0);
        $entryPrice = (float) ($features['entry_price'] ?? 0);
        $entryTime = (int) ($features['event_time'] ?? 0);
        $levelTime = (int) ($features['level_time'] ?? 0);
        $takedownTime = (int) ($features['takedown_time'] ?? $entryTime);
        $takedownPrice = (float) ($features['takedown_price'] ?? $entryPrice);

        $lastBar = $closedCandles !== [] ? $closedCandles[array_key_last($closedCandles)] : null;
        if ($entryTime <= 0 && is_array($lastBar)) {
            $entryTime = (int) ($lastBar['time'] ?? 0);
        }
        if ($entryPrice <= 0 && is_array($lastBar)) {
            $entryPrice = (float) ($lastBar['close'] ?? 0);
        }
        if ($takedownTime <= 0) {
            $takedownTime = $entryTime;
        }

        $isHigh = $location === 'high';
        $fractalColor = $isHigh ? (string) $params['color_fractal_high'] : (string) $params['color_fractal_low'];
        $setupColor = $kind === 'raid' ? (string) $params['color_raid'] : (string) $params['color_sweep'];
        $pullbackColor = (string) $params['color_pullback'];

        $markers = [];
        $segments = [];

        if ($levelTime > 0 && $levelPrice > 0) {
            $markers[] = [
                'time' => $levelTime,
                'price' => $levelPrice,
                'text' => $isHigh ? 'FH' : 'FL',
                'color' => $fractalColor,
                'position' => $isHigh ? 'above' : 'below',
            ];
            $lineEnd = $takedownTime > $levelTime ? $takedownTime : $entryTime;
            $segments[] = [
                'time_start' => $levelTime,
                'time_end' => $lineEnd > $levelTime ? $lineEnd : $levelTime,
                'price' => $levelPrice,
                'color' => $fractalColor,
                'style' => 'dashed',
            ];
        }

        if ($takedownTime > 0 && $takedownPrice > 0) {
            $markers[] = [
                'time' => $takedownTime,
                'price' => $takedownPrice,
                'text' => ($isHigh ? '▼ ' : '▲ ').strtoupper($kind).' '.($isHigh ? 'H' : 'L').' · '.$side,
                'color' => $setupColor,
                'position' => $isHigh ? 'above' : 'below',
            ];
        }

        if ($entryMode === 'pullback' && $entryTime > 0 && $entryTime !== $takedownTime) {
            $markers[] = [
                'time' => $entryTime,
                'price' => $entryPrice,
                'text' => ($isHigh ? '▼ ' : '▲ ').'PB · '.$side,
                'color' => $pullbackColor,
                'position' => $isHigh ? 'above' : 'below',
            ];
        }

        $levels = [];
        if ($levelPrice > 0) {
            $levels[] = [
                'price' => $levelPrice,
                'label' => $isHigh ? 'FH ликвидность' : 'FL ликвидность',
                'timeframe' => $isHigh ? 'M1' : 'M5',
                'style' => 'dashed',
                'kind' => $isHigh ? 'resistance' : 'support',
            ];
        }

        $entry = null;
        if ($entryPrice > 0) {
            $entry = [
                'price' => $entryPrice,
                'side' => $side,
                'time' => $entryTime,
                'label' => $this->entryMarkerLabel($side, $kind, $location, $entryMode),
            ];
        }

        return [
            'levels' => $levels,
            'zones' => [],
            'entry' => $entry,
            'segments' => $segments,
            'markers' => $markers,
            'subtitle' => $this->whySubtitle($side, $kind, $location, $entryMode, $levelPrice),
            'legend' => 'FH/FL фрактал · SWEEP свип тенью · RAID рейд/возврат · PB первая откатная · точка = вход бота',
        ];
    }

    /** @return array{0:string,1:string} */
    private function splitSetup(string $setup, string $entryMode): array
    {
        if (str_contains($setup, '_')) {
            [$kind, $location] = explode('_', $setup, 2);

            return [$kind, $location === 'high' ? 'high' : 'low'];
        }

        return [$entryMode === 'sweep' ? 'sweep' : 'raid', 'high'];
    }

    private function entryMarkerLabel(string $side, string $kind, string $location, string $entryMode): string
    {
        if ($entryMode === 'sweep') {
            return sprintf('Вход %s', $side);
        }

        return sprintf('Вход %s · PB', $side);
    }

    private function whySubtitle(string $side, string $kind, string $location, string $entryMode, float $levelPrice): string
    {
        $where = $location === 'high' ? 'хая (FH)' : 'лоу (FL)';
        $setup = $kind === 'sweep' ? 'свип' : 'рейд';
        $level = $levelPrice > 0 ? ' @ '.$this->shortPrice($levelPrice) : '';
        if ($entryMode === 'sweep') {
            $how = $location === 'high'
                ? 'зелёная сняла уровень тенью и закрылась ниже'
                : 'красная сняла уровень тенью и закрылась выше';

            return sprintf('Вход %s: %s %s%s — %s', $side, $setup, $where, $level, $how);
        }
        $pull = $location === 'high'
            ? 'первая зелёная откатная после снятия сверху'
            : 'первая красная откатная после снятия снизу';

        return sprintf('Вход %s: %s — %s %s%s', $side, $pull, $setup, $where, $level);
    }

    private function shortPrice(float $price): string
    {
        if ($price >= 1000) {
            return number_format($price, 2, '.', '');
        }
        if ($price >= 10) {
            return number_format($price, 4, '.', '');
        }

        return rtrim(rtrim(number_format($price, 5, '.', ''), '0'), '.') ?: '0';
    }
}
