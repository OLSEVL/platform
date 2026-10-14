<?php

namespace App\Services\Telegram;

use App\Services\Indicators\EngulfingDetector;
use App\Services\Indicators\EngulfingLiquidityIndicatorService;
use App\Support\EngulfingLiquidityConfig;

/**
 * PNG-алерт Telegram для бота engulfing_liquidity: русские метки 3 шагов + стрелка входа.
 *
 * Цепочка сценария D: ① поглощение у уровня → ② реакция → ③ контр-поглощение = вход.
 * Опционально — горизонталь ближайшего FH/FL («сработает ли отбой»).
 */
class EngulfingLiquidityChartBuilder
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $closedCandles
     * @param  array<string, mixed>  $indicatorParams
     * @param  array<string, mixed>  $botConfig  config бота (флаги Telegram-уровня)
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
    public function chartLevelsFromDecision(
        array $decision,
        array $closedCandles,
        array $indicatorParams = [],
        array $botConfig = [],
    ): array {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $side = strtoupper((string) ($decision['side'] ?? ''));
        $params = array_replace(EngulfingLiquidityIndicatorService::defaultParams(), $indicatorParams);
        $engulfKind = (string) ($features['engulf_kind'] ?? 'classic');
        $kindRu = $engulfKind === 'same_color' ? 'одного цвета' : 'класси.';

        $step1Time = (int) ($features['step1_time'] ?? 0);
        $step2Time = (int) ($features['step2_time'] ?? 0);
        $step3Time = (int) ($features['step3_time'] ?? 0);
        $entryPrice = (float) ($features['entry_price'] ?? 0);
        $entryTime = (int) ($features['event_time'] ?? $step3Time);
        $reactionGap = max(1, (int) ($features['reaction_gap_bars'] ?? 1));

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
        // CALL: метки шагов 1/3 снизу (покупаем вверх), шаг 2 сверху.
        // PUT: зеркально.
        $isCall = $side === 'CALL';
        $sideRu = $isCall ? 'CALL ▲ вверх' : ($side === 'PUT' ? 'PUT ▼ вниз' : ($side !== '' ? $side : '—'));

        $barAt = static function (array $candles, int $time): ?array {
            foreach ($candles as $c) {
                if ((int) ($c['time'] ?? 0) === $time) {
                    return is_array($c) ? $c : null;
                }
            }

            return null;
        };

        $indexAt = static function (array $candles, int $time): int {
            foreach ($candles as $i => $c) {
                if ((int) ($c['time'] ?? 0) === $time) {
                    return (int) $i;
                }
            }

            return -1;
        };

        $markers = [];

        // ① Первое поглощение — контекст / снятие ликвидности у уровня.
        if ($step1Time > 0) {
            $bar = $barAt($closedCandles, $step1Time);
            $markers[] = [
                'time' => $step1Time,
                'price' => $bar !== null
                    ? ($isCall ? (float) $bar['low'] : (float) $bar['high'])
                    : 0.0,
                'text' => '① Поглощение',
                'lines' => ['① Поглощение', '('.$kindRu.')'],
                'color' => (string) $params['color_step1'],
                'position' => $isCall ? 'below' : 'above',
                'arrow' => $isCall ? 'up' : 'down',
                'size' => 'normal',
            ];
        }

        // ② Реакция рынка (откат после шага 1).
        if ($step2Time > 0) {
            $bar = $barAt($closedCandles, $step2Time);
            $markers[] = [
                'time' => $step2Time,
                'price' => $bar !== null
                    ? ($isCall ? (float) $bar['high'] : (float) $bar['low'])
                    : 0.0,
                'text' => '② Реакция',
                'lines' => ['② Реакция', $reactionGap.' бар'.($reactionGap === 1 ? '' : 'а')],
                'color' => (string) $params['color_step2'],
                'position' => $isCall ? 'above' : 'below',
                'arrow' => $isCall ? 'down' : 'up',
                'size' => 'normal',
            ];
        }

        // ③ Контр-поглощение = точка входа (close этой свечи).
        if ($step3Time > 0 || $entryTime > 0) {
            $t = $step3Time > 0 ? $step3Time : $entryTime;
            $bar = $barAt($closedCandles, $t);
            $price = $entryPrice > 0
                ? $entryPrice
                : ($bar !== null ? (float) $bar['close'] : 0.0);
            $sideRu = $isCall ? 'CALL ▲ вверх' : ($side === 'PUT' ? 'PUT ▼ вниз' : $side);
            $markers[] = [
                'time' => $t,
                'price' => $price,
                'text' => '③ ВХОД '.$sideRu,
                'lines' => [
                    '③ ВХОД',
                    $sideRu,
                    'на close свечи',
                ],
                'color' => $step3Color,
                'position' => $isCall ? 'below' : 'above',
                // Стрелка указывает направление сделки: CALL вверх, PUT вниз.
                'arrow' => $isCall ? 'up' : 'down',
                'size' => 'entry',
            ];
        }

        // Горизонталь цены входа — «куда ставить».
        $levels = [];
        if ($entryPrice > 0) {
            $levels[] = [
                'price' => $entryPrice,
                'label' => 'вход '.number_format($entryPrice, 5, '.', ''),
                'color' => $step3Color,
            ];
        }

        // Ближайший важный FH/FL — видно, будет ли отбой от уровня.
        if (EngulfingLiquidityConfig::telegramShowNearestLevel($botConfig !== [] ? $botConfig : ['telegram_show_nearest_level' => true])) {
            $levelPrice = (float) ($features['level_price'] ?? 0);
            $levelType = (string) ($features['level_type'] ?? '');
            if ($levelPrice <= 0 && $closedCandles !== [] && $side !== '') {
                $refIdx = $step1Time > 0 ? $indexAt($closedCandles, $step1Time) : -1;
                if ($refIdx < 0) {
                    $refIdx = count($closedCandles) - 1;
                }
                $lookback = EngulfingLiquidityConfig::telegramLevelLookback(
                    $botConfig !== [] ? $botConfig : [],
                    (int) ($params['level_lookback'] ?? 40),
                );
                $found = (new EngulfingDetector)->nearestImportantLevel(
                    $closedCandles,
                    $refIdx,
                    $side,
                    $lookback,
                    $entryPrice > 0 ? $entryPrice : null,
                );
                if ($found !== null) {
                    $levelPrice = (float) $found['price'];
                    $levelType = (string) $found['type'];
                }
            }
            if ($levelPrice > 0) {
                $typeRu = $levelType === 'FH' ? 'FH (сопр.)' : ($levelType === 'FL' ? 'FL (подд.)' : 'уровень');
                $levels[] = [
                    'price' => $levelPrice,
                    'label' => $typeRu.' '.number_format($levelPrice, 5, '.', ''),
                    // Янтарный — отличается от зелёной/красной линии входа.
                    'color' => '#fbbf24',
                ];
            }
        }

        return [
            'levels' => $levels,
            'zones' => [],
            'entry' => $entryTime > 0 ? [
                'time' => $entryTime,
                'price' => $entryPrice,
                'side' => $side,
            ] : null,
            'segments' => [],
            'markers' => $markers,
            'subtitle' => 'Поглощение · '.$sideRu,
            'legend' => '① поглощение → ② реакция → ③ вход',
        ];
    }
}
