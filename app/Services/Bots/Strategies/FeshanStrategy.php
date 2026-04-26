<?php

namespace App\Services\Bots\Strategies;

/**
 * Feshan (v2): только логика снятия ликвидности (liquidity sweep) на M1.
 *
 * Убраны MTF-триггеры и вспомогательные условия:
 * - вход CALL только после sweep локального low и возврата выше уровня;
 * - вход PUT только после sweep локального high и возврата ниже уровня.
 */
class FeshanStrategy implements BotStrategyInterface
{
    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string,mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        // Опциональная фиксация экспирации в секундах для live-режима.
        $fixedExpirySec = isset($cfg['fixed_expiry_sec']) ? (int) $cfg['fixed_expiry_sec'] : null;
        // Разрешённая сторона входа:
        // both (по умолчанию), call, put.
        // Используется для "pro" режима, когда нужно торговать только сторону с лучшей статистикой.
        $allowedSide = strtolower((string) ($cfg['allowed_side'] ?? 'both'));
        if (! in_array($allowedSide, ['both', 'call', 'put'], true)) {
            $allowedSide = 'both';
        }
        $lookback = max(5, min(50, (int) ($cfg['liquidity_lookback'] ?? 20)));
        $minWickRatio = max(0.05, min(0.8, (float) ($cfg['sweep_min_wick_ratio'] ?? 0.35)));
        $minReclaimRatio = max(0.05, min(0.8, (float) ($cfg['sweep_min_reclaim_ratio'] ?? 0.12)));
        // Насколько близко цена ретеста должна подойти к уровню после sweep.
        // 0.10 = не дальше 10% от диапазона окна уровня.
        $entryExtremaDistance = max(0.02, min(0.4, (float) ($cfg['entry_extrema_distance'] ?? 0.14)));
        // Допуск на "касание" уровня при ретесте:
        // используем долю от диапазона окна, чтобы порог был адаптивным к волатильности.
        $retestTouchToleranceRatio = max(0.005, min(0.2, (float) ($cfg['retest_touch_tolerance_ratio'] ?? 0.03)));
        // Максимальная "давность" sweep (в барах до текущего ретеста):
        // более старые sweep чаще дают ложные/запаздывающие входы.
        $maxSweepBarsAgo = max(1, min(6, (int) ($cfg['max_sweep_bars_ago'] ?? 3)));
        // Минимальная доля тела у свечи ретеста: отсекаем doji/шум возле уровня.
        $minRetestBodyRatio = max(0.05, min(0.8, (float) ($cfg['min_retest_body_ratio'] ?? 0.12)));
        // Минимальная тень реакции от уровня:
        // CALL -> нижняя тень, PUT -> верхняя тень.
        $minRetestReactionWickRatio = max(0.05, min(0.8, (float) ($cfg['min_retest_reaction_wick_ratio'] ?? 0.18)));
        // Дополнительный фильтр качества: вход только по локальному микро-тренду.
        // Оставляем его опциональным через конфиг, но по умолчанию включаем для повышения winrate.
        $useLocalTrendFilter = ! array_key_exists('use_local_trend_filter', $cfg)
            ? true
            : (bool) $cfg['use_local_trend_filter'];
        // Управление строгостью подтверждения ретеста.
        $useRetestTouchFilter = ! array_key_exists('use_retest_touch_filter', $cfg)
            ? true
            : (bool) $cfg['use_retest_touch_filter'];
        $useRetestQualityFilter = ! array_key_exists('use_retest_quality_filter', $cfg)
            ? true
            : (bool) $cfg['use_retest_quality_filter'];
        $localTrendLookback = max(4, min(30, (int) ($cfg['local_trend_lookback'] ?? 8)));
        $localTrendMinMoveRatio = max(0.00005, min(0.02, (float) ($cfg['local_trend_min_move_ratio'] ?? 0.0004)));
        // Сколько последних закрытых свечей проверяем как "свечу снятия ликвидности".
        // Если partial-свеча есть, ретест ищем на partial; если нет — на последней закрытой.
        $retestSweepMemoryBars = max(1, min(6, (int) ($cfg['retest_sweep_memory_bars'] ?? 3)));

        if (count($closedCandles) < $lookback + 2) {
            return null;
        }

        $entryCandidate = (float) (($partialCandle['close'] ?? null) ?? $closedCandles[array_key_last($closedCandles)]['close']);
        $trendSample = array_slice($closedCandles, -$localTrendLookback);
        $localTrend = $this->detectLocalTrend($trendSample, $localTrendMinMoveRatio);
        $recentSweep = $this->detectRecentSweepForRetest(
            $closedCandles,
            $lookback,
            $retestSweepMemoryBars,
            $minWickRatio,
            $minReclaimRatio,
            $partialCandle !== null
        );
        if ($recentSweep === null) {
            return null;
        }

        $priorLow = $recentSweep['prior_low'];
        $priorHigh = $recentSweep['prior_high'];
        $windowRange = max(1e-8, $priorHigh - $priorLow);
        $levelPrice = $recentSweep['level_price'];
        $touchTolerance = $windowRange * $retestTouchToleranceRatio;
        // Данные свечи ретеста:
        // - если есть partial, используем её high/low/close как текущий ретест;
        // - иначе используем последнюю закрытую свечу.
        $retestBar = $partialCandle ?? $closedCandles[array_key_last($closedCandles)];
        $retestOpen = (float) ($retestBar['open'] ?? $entryCandidate);
        $retestHigh = (float) ($retestBar['high'] ?? $entryCandidate);
        $retestLow = (float) ($retestBar['low'] ?? $entryCandidate);
        $retestClose = (float) ($retestBar['close'] ?? $entryCandidate);
        $retestRange = max(1e-8, $retestHigh - $retestLow);
        $retestBodyRatio = min(1.0, abs($retestClose - $retestOpen) / $retestRange);
        $retestBodyTop = max($retestOpen, $retestClose);
        $retestBodyBot = min($retestOpen, $retestClose);
        $retestLowerWickRatio = min(1.0, max(0.0, ($retestBodyBot - $retestLow) / $retestRange));
        $retestUpperWickRatio = min(1.0, max(0.0, ($retestHigh - $retestBodyTop) / $retestRange));
        // Ретест уровня: цена после sweep должна вернуться к уровню и оставаться на "правильной" стороне.
        $distanceToLevelRatio = abs($entryCandidate - $levelPrice) / $windowRange;
        $levelRetestNear = $distanceToLevelRatio <= $entryExtremaDistance;
        // Жёсткое подтверждение ретеста:
        // CALL: свеча ретеста касается уровня снизу (low <= level + tol) и закрывается обратно выше level.
        // PUT:  свеча ретеста касается уровня сверху (high >= level - tol) и закрывается обратно ниже level.
        $levelTouchOk = $recentSweep['side'] === 'CALL'
            ? $retestLow <= ($levelPrice + $touchTolerance)
            : $retestHigh >= ($levelPrice - $touchTolerance);
        $levelRetestSideOk = $recentSweep['side'] === 'CALL'
            ? $retestClose >= $levelPrice
            : $retestClose <= $levelPrice;
        $levelRetestOk = $levelRetestNear
            && (! $useRetestTouchFilter || $levelTouchOk)
            && $levelRetestSideOk;
        // Качество свечи ретеста:
        // - sweep не слишком старый;
        // - тело не слишком маленькое;
        // - есть реакция тенью именно от тестируемого уровня;
        // - close подтверждает сторону (CALL bullish, PUT bearish).
        $sweepFreshEnough = (int) $recentSweep['bars_ago'] <= $maxSweepBarsAgo;
        $retestBodyOk = $retestBodyRatio >= $minRetestBodyRatio;
        $retestReactionWickOk = $recentSweep['side'] === 'CALL'
            ? $retestLowerWickRatio >= $minRetestReactionWickRatio
            : $retestUpperWickRatio >= $minRetestReactionWickRatio;
        $retestDirectionOk = $recentSweep['side'] === 'CALL'
            ? $retestClose >= $retestOpen
            : $retestClose <= $retestOpen;
        $retestQualityOk = ! $useRetestQualityFilter
            || ($sweepFreshEnough && $retestBodyOk && $retestReactionWickOk && $retestDirectionOk);
        // Мягкий тренд-фильтр:
        // - запрещаем только вход ПРОТИВ явного локального тренда;
        // - при flat допускаем вход по основному паттерну sweep+retest.
        $trendDirectionOk = ! $useLocalTrendFilter || (
            ($recentSweep['side'] === 'CALL' && in_array($localTrend, ['up', 'flat'], true))
            || ($recentSweep['side'] === 'PUT' && in_array($localTrend, ['down', 'flat'], true))
        );

        $side = null;
        $reasons = [];
        if ($recentSweep['side'] === 'CALL' && $levelRetestOk && $retestQualityOk && $trendDirectionOk && $allowedSide !== 'put') {
            $side = 'CALL';
            $reasons[] = 'Sweep low: снятие ликвидности под уровнем + возврат в диапазон';
            $reasons[] = 'Ретест уровня low: вход только после возврата к уровню';
            $reasons[] = 'Качество ретеста подтверждено: реакция от уровня и свеча в сторону CALL';
            if ($useLocalTrendFilter) {
                $reasons[] = 'Фильтр локального тренда: вход только по направлению up';
            }
        } elseif ($recentSweep['side'] === 'PUT' && $levelRetestOk && $retestQualityOk && $trendDirectionOk && $allowedSide !== 'call') {
            $side = 'PUT';
            $reasons[] = 'Sweep high: снятие ликвидности над уровнем + возврат в диапазон';
            $reasons[] = 'Ретест уровня high: вход только после возврата к уровню';
            $reasons[] = 'Качество ретеста подтверждено: реакция от уровня и свеча в сторону PUT';
            if ($useLocalTrendFilter) {
                $reasons[] = 'Фильтр локального тренда: вход только по направлению down';
            }
        }

        if ($side === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;
        $wickQuality = (float) $recentSweep['wick_ratio'];
        $reclaimQuality = (float) $recentSweep['reclaim_ratio'];
        // Чем ближе ретест к уровню, тем выше качество входа.
        $extremaQuality = 1.0 - min(1.0, $distanceToLevelRatio);
        $structureScore = min(1.0, max(0.0, 0.45 * $wickQuality + 0.35 * $reclaimQuality + 0.20 * $extremaQuality));
        $confidence = round(min(0.95, max(0.55, 0.62 * $structureScore + 0.38 * $payoutFactor)), 4);

        // Адаптивная экспирация сокращена до 2..3 минут:
        // - сильный сетап -> 2 минуты,
        // - все остальные валидные сетапы -> 3 минуты.
        $quality = $wickQuality + $reclaimQuality;
        $expirySec = match (true) {
            $quality >= 1.4 => 120,
            default => 180,
        };
        if ($fixedExpirySec !== null) {
            // Безопасно ограничиваем ручную экспирацию рабочим диапазоном 2..5 минут.
            $expirySec = max(120, min(300, $fixedExpirySec));
        }
        $analysisText = sprintf(
            'feshan_liquidity: side=%s; lookback=%d; sweep_bar_ago=%d; retest_level=%.5f; wick=%.2f reclaim=%.2f entry=%.5f zone=[%.5f..%.5f]; confidence=%.2f',
            $side,
            $lookback,
            (int) $recentSweep['bars_ago'],
            (float) $levelPrice,
            $wickQuality,
            $reclaimQuality,
            $entryCandidate,
            $priorLow,
            $priorHigh,
            $confidence
        );

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => $analysisText,
            'features' => [
                'strategy' => 'feshan_liquidity_sweep_v2',
                'lookback' => $lookback,
                'prior_low' => $priorLow,
                'prior_high' => $priorHigh,
                'retest_level_price' => $levelPrice,
                'entry_candidate' => $entryCandidate,
                'allowed_side' => $allowedSide,
                'distance_to_level_ratio' => round($distanceToLevelRatio, 4),
                'level_retest_ok' => $levelRetestOk,
                'level_touch_ok' => $levelTouchOk,
                'retest_quality_ok' => $retestQualityOk,
                'use_retest_touch_filter' => $useRetestTouchFilter,
                'use_retest_quality_filter' => $useRetestQualityFilter,
                'trend_direction_ok' => $trendDirectionOk,
                'use_local_trend_filter' => $useLocalTrendFilter,
                'local_trend' => $localTrend,
                'local_trend_lookback' => $localTrendLookback,
                'local_trend_min_move_ratio' => $localTrendMinMoveRatio,
                'sweep_fresh_enough' => $sweepFreshEnough,
                'retest_body_ok' => $retestBodyOk,
                'retest_reaction_wick_ok' => $retestReactionWickOk,
                'retest_direction_ok' => $retestDirectionOk,
                'retest_touch_tolerance_ratio' => $retestTouchToleranceRatio,
                'retest_touch_tolerance_abs' => round($touchTolerance, 6),
                'max_sweep_bars_ago' => $maxSweepBarsAgo,
                'min_retest_body_ratio' => $minRetestBodyRatio,
                'min_retest_reaction_wick_ratio' => $minRetestReactionWickRatio,
                'retest_open' => $retestOpen,
                'retest_high' => $retestHigh,
                'retest_low' => $retestLow,
                'retest_close' => $retestClose,
                'retest_body_ratio' => round($retestBodyRatio, 4),
                'retest_lower_wick_ratio' => round($retestLowerWickRatio, 4),
                'retest_upper_wick_ratio' => round($retestUpperWickRatio, 4),
                'sweep_side' => $recentSweep['side'],
                'sweep_bars_ago' => (int) $recentSweep['bars_ago'],
                'wick_ratio' => round($wickQuality, 4),
                'reclaim_ratio' => round($reclaimQuality, 4),
                'sweep_min_wick_ratio' => $minWickRatio,
                'sweep_min_reclaim_ratio' => $minReclaimRatio,
                'entry_extrema_distance' => $entryExtremaDistance,
                'retest_sweep_memory_bars' => $retestSweepMemoryBars,
                'payout' => $payout,
                'expiry_sec_adaptive' => $expirySec,
            ],
            'expiry_sec' => $expirySec,
            'decision_version' => 'feshan_liquidity_v2',
        ];
    }

    /**
     * Ищет последний валидный sweep-бар, от которого можно торговать ретест уровня.
     *
     * Важно:
     * - sweep определяется на закрытой свече;
     * - уровень строится по окну перед sweep-свечой;
     * - если есть partial, ретест ожидается на partial (после закрытого sweep-бара).
     *
     * @param list<array<string,mixed>> $closedCandles
     * @return array{
     *   side:'CALL'|'PUT',
     *   prior_low:float,
     *   prior_high:float,
     *   level_price:float,
     *   wick_ratio:float,
     *   reclaim_ratio:float,
     *   bars_ago:int
     * }|null
     */
    private function detectRecentSweepForRetest(
        array $closedCandles,
        int $lookback,
        int $memoryBars,
        float $minWickRatio,
        float $minReclaimRatio,
        bool $hasPartial
    ): ?array {
        $lastClosedIdx = array_key_last($closedCandles);
        if (! is_int($lastClosedIdx)) {
            return null;
        }

        // Если partial нет, последняя закрытая свеча считается свечой ретеста,
        // поэтому sweep ищем только до неё (на одну свечу раньше).
        $startSweepIdx = $hasPartial ? $lastClosedIdx : $lastClosedIdx - 1;
        if ($startSweepIdx < $lookback) {
            return null;
        }
        $endSweepIdx = max($lookback, $startSweepIdx - $memoryBars + 1);

        for ($sweepIdx = $startSweepIdx; $sweepIdx >= $endSweepIdx; $sweepIdx--) {
            $history = array_slice($closedCandles, $sweepIdx - $lookback, $lookback);
            if (count($history) < $lookback) {
                continue;
            }
            $priorLow = min(array_map(static fn (array $c): float => (float) $c['low'], $history));
            $priorHigh = max(array_map(static fn (array $c): float => (float) $c['high'], $history));
            $windowRange = max(1e-8, $priorHigh - $priorLow);

            $sweep = $closedCandles[$sweepIdx];
            $open = (float) $sweep['open'];
            $close = (float) $sweep['close'];
            $high = (float) $sweep['high'];
            $low = (float) $sweep['low'];
            $range = max(1e-8, $high - $low);
            $bodyTop = max($open, $close);
            $bodyBot = min($open, $close);
            $lowerWickRatio = max(0.0, min(1.0, ($bodyBot - $low) / $range));
            $upperWickRatio = max(0.0, min(1.0, ($high - $bodyTop) / $range));
            $reclaimUpRatio = ($close - $priorLow) / $range;
            $reclaimDownRatio = ($priorHigh - $close) / $range;

            if (
                $low < $priorLow
                && $close > $priorLow
                && $lowerWickRatio >= $minWickRatio
                && $reclaimUpRatio >= $minReclaimRatio
            ) {
                return [
                    'side' => 'CALL',
                    'prior_low' => $priorLow,
                    'prior_high' => $priorHigh,
                    'level_price' => $priorLow,
                    'wick_ratio' => $lowerWickRatio,
                    'reclaim_ratio' => max(0.0, $reclaimUpRatio),
                    'bars_ago' => $lastClosedIdx - $sweepIdx + 1,
                ];
            }

            if (
                $high > $priorHigh
                && $close < $priorHigh
                && $upperWickRatio >= $minWickRatio
                && $reclaimDownRatio >= $minReclaimRatio
            ) {
                return [
                    'side' => 'PUT',
                    'prior_low' => $priorLow,
                    'prior_high' => $priorHigh,
                    'level_price' => $priorHigh,
                    'wick_ratio' => $upperWickRatio,
                    'reclaim_ratio' => max(0.0, $reclaimDownRatio),
                    'bars_ago' => $lastClosedIdx - $sweepIdx + 1,
                ];
            }
        }

        return null;
    }

    /**
     * Локальный тренд по закрытым M1:
     * - up/down при достаточном смещении цены;
     * - flat, если движение в пределах рыночного шума.
     *
     * @param list<array<string,mixed>> $bars
     */
    private function detectLocalTrend(array $bars, float $minMoveRatio): string
    {
        if (count($bars) < 2) {
            return 'flat';
        }
        $first = (float) $bars[0]['close'];
        $last = (float) $bars[array_key_last($bars)]['close'];
        $base = max(1e-8, abs($first));
        $deltaRatio = ($last - $first) / $base;

        if ($deltaRatio > $minMoveRatio) {
            return 'up';
        }
        if ($deltaRatio < -$minMoveRatio) {
            return 'down';
        }

        return 'flat';
    }
}
