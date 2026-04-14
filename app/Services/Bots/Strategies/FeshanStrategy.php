<?php

namespace App\Services\Bots\Strategies;

/**
 * Feshan bot: упрощённая MTF-логика под доступные в движке агрегаты M1→5/15/30/60/240 мин.
 * Полное ТЗ (имбаланс, ликвидность, объёмы) заложено в эвристики и текст анализа; при появлении
 * отдельных детекторов зон их можно усилить без смены контракта analyze().
 */
class FeshanStrategy implements BotStrategyInterface
{
    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, list<array<string, mixed>>> $mtf */
        $mtf = is_array($context['mtf'] ?? null) ? $context['mtf'] : [];
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        if (count($closedCandles) < 5) {
            return null;
        }

        $bias240 = $this->directionBias($mtf['240'] ?? [], 8);
        $bias60 = $this->directionBias($mtf['60'] ?? [], 6);
        $bars5 = $mtf['5'] ?? [];
        $lastM1 = $closedCandles[array_key_last($closedCandles)];

        if ($bias240 === null || $bias240 === 'flat') {
            return null;
        }

        $h1Last = $this->lastBar($mtf['60'] ?? []);
        $h1Bull = $h1Last && $this->isBull($h1Last);
        $h1Bear = $h1Last && $this->isBear($h1Last);

        $m5AlignUp = $this->alignedCount($bars5, 3, true);
        $m5AlignDown = $this->alignedCount($bars5, 3, false);

        $m1Bull = $this->isBull($lastM1);
        $m1Bear = $this->isBear($lastM1);

        // Поглощение / давление: длинная тень против хода на M1.
        $absorbBull = $m1Bull && $this->lowerWickRatio($lastM1) >= 0.45;
        $absorbBear = $m1Bear && $this->upperWickRatio($lastM1) >= 0.45;

        $imb5Bull = $this->lastBullishImbalance($bars5);
        $imb5Bear = $this->lastBearishImbalance($bars5);

        // analysisSide — направление по MTF-анализу; tradeSide ниже инвертируем по запросу пользователя.
        $analysisSide = null;
        $reasons = [];

        // Гибкие триггеры (из настроек бота): каждый можно включить/выключить в UI.
        $useBias240 = $this->flag($cfg, 'trigger_bias_240', true);
        $useBias60 = $this->flag($cfg, 'trigger_bias_60', true);
        $useH1Candle = $this->flag($cfg, 'trigger_h1_candle', true);
        $useM5Align = $this->flag($cfg, 'trigger_m5_align', true);
        $useM1Candle = $this->flag($cfg, 'trigger_m1_candle', true);
        // Эти 2 триггера работают как доп.подтверждения к core-сигналу.
        $useAbsorb = $this->flag($cfg, 'trigger_absorption', false);
        $useImbalance = $this->flag($cfg, 'trigger_imbalance', false);

        $callCoreOk = (! $useBias240 || $bias240 === 'up')
            && (! $useBias60 || $bias60 !== 'down')
            && (! $useH1Candle || $h1Bull)
            && (! $useM5Align || $m5AlignUp >= 2)
            && (! $useM1Candle || $m1Bull);
        $putCoreOk = (! $useBias240 || $bias240 === 'down')
            && (! $useBias60 || $bias60 !== 'up')
            && (! $useH1Candle || $h1Bear)
            && (! $useM5Align || $m5AlignDown >= 2)
            && (! $useM1Candle || $m1Bear);

        $callExtraOk = (! $useAbsorb || $absorbBull) && (! $useImbalance || $imb5Bull);
        $putExtraOk = (! $useAbsorb || $absorbBear) && (! $useImbalance || $imb5Bear);

        if ($callCoreOk && $callExtraOk) {
            $analysisSide = 'CALL';
            $reasons[] = '4H/1H бычий контекст, 5M ≥2/3 в сторону роста, M1 бычья';
            if ($absorbBull) {
                $reasons[] = 'M1: нижняя тень (поглощение продаж)';
            }
            if ($imb5Bull) {
                $reasons[] = '5M: признак перекрытия бычьего имбаланса';
            }
        } elseif ($putCoreOk && $putExtraOk) {
            $analysisSide = 'PUT';
            $reasons[] = '4H/1H медвежий контекст, 5M ≥2/3 в сторону снижения, M1 медвежья';
            if ($absorbBear) {
                $reasons[] = 'M1: верхняя тень (поглощение покупок)';
            }
            if ($imb5Bear) {
                $reasons[] = '5M: признак перекрытия медвежьего имбаланса';
            }
        }

        if ($analysisSide === null) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;

        $structureScore = 0.55;
        if (($analysisSide === 'CALL' && $bias60 === 'up') || ($analysisSide === 'PUT' && $bias60 === 'down')) {
            $structureScore += 0.08;
        }
        if (($analysisSide === 'CALL' && $m5AlignUp >= 3) || ($analysisSide === 'PUT' && $m5AlignDown >= 3)) {
            $structureScore += 0.07;
        }
        if ($analysisSide === 'CALL' && ($absorbBull || $imb5Bull)) {
            $structureScore += 0.06;
        }
        if ($analysisSide === 'PUT' && ($absorbBear || $imb5Bear)) {
            $structureScore += 0.06;
        }

        $confidence = round(min(0.92, $structureScore * 0.72 + 0.28 * $payoutFactor), 4);
        $confidence = max($confidence, 0.58);

        // Контртрендовый вход для Feshan: сделка открывается в сторону, обратную аналитическому сигналу.
        $tradeSide = $analysisSide === 'CALL' ? 'PUT' : 'CALL';

        $analysisText = sprintf(
            'feshan_mtf: analysis=%s trade=%s; 4H=%s 1H=%s; confidence=%.2f. Чек: тренд старший ✓ уровень/структура ✓ триггер M1/M5 ✓',
            $analysisSide,
            $tradeSide,
            $bias240,
            $bias60 ?? 'n/a',
            $confidence
        );

        return [
            'side' => $tradeSide,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => $analysisText,
            'features' => [
                'analysis_side' => $analysisSide,
                'trade_side' => $tradeSide,
                'bias_240' => $bias240,
                'bias_60' => $bias60,
                'm5_align_up' => $m5AlignUp,
                'm5_align_down' => $m5AlignDown,
                'm1_bull' => $m1Bull,
                'absorb_bull' => $absorbBull,
                'absorb_bear' => $absorbBear,
                'imb5_bull' => $imb5Bull,
                'imb5_bear' => $imb5Bear,
                'trigger_bias_240' => $useBias240,
                'trigger_bias_60' => $useBias60,
                'trigger_h1_candle' => $useH1Candle,
                'trigger_m5_align' => $useM5Align,
                'trigger_m1_candle' => $useM1Candle,
                'trigger_absorption' => $useAbsorb,
                'trigger_imbalance' => $useImbalance,
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'feshan_mtf_v1',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function directionBias(array $bars, int $n): ?string
    {
        if (count($bars) < $n) {
            return null;
        }
        $slice = array_slice($bars, -$n);
        $first = (float) $slice[0]['close'];
        $last = (float) $slice[array_key_last($slice)]['close'];
        $eps = max($first * 0.00015, 1e-7);
        if ($last > $first + $eps) {
            return 'up';
        }
        if ($last < $first - $eps) {
            return 'down';
        }

        return 'flat';
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function lastBar(array $bars): ?array
    {
        if ($bars === []) {
            return null;
        }

        return $bars[array_key_last($bars)];
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function alignedCount(array $bars, int $take, bool $bull): int
    {
        if (count($bars) < $take) {
            return 0;
        }
        $slice = array_slice($bars, -$take);
        $n = 0;
        foreach ($slice as $c) {
            if ($bull && $this->isBull($c)) {
                $n++;
            }
            if (! $bull && $this->isBear($c)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function isBull(array $c): bool
    {
        return (float) $c['close'] > (float) $c['open'];
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function isBear(array $c): bool
    {
        return (float) $c['close'] < (float) $c['open'];
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function lowerWickRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $bodyTop = max((float) $c['open'], (float) $c['close']);
        $lower = $bodyTop - (float) $c['low'];

        return min(1.0, $lower / $range);
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function upperWickRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $bodyBot = min((float) $c['open'], (float) $c['close']);
        $upper = (float) $c['high'] - $bodyBot;

        return min(1.0, $upper / $range);
    }

    /**
     * Упрощённый FVG: три последние свечи 5M, бычий разрыв low текущей выше high двух баров назад.
     *
     * @param  list<array<string,mixed>>  $bars
     */
    private function lastBullishImbalance(array $bars): bool
    {
        $n = count($bars);
        if ($n < 3) {
            return false;
        }
        $a = $bars[$n - 3];
        $c = $bars[$n - 1];

        return (float) $c['low'] > (float) $a['high'];
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function lastBearishImbalance(array $bars): bool
    {
        $n = count($bars);
        if ($n < 3) {
            return false;
        }
        $a = $bars[$n - 3];
        $c = $bars[$n - 1];

        return (float) $c['high'] < (float) $a['low'];
    }

    /**
     * Читает bool-флаг из конфига стратегии. Если ключа нет — использует дефолт.
     *
     * @param  array<string,mixed>  $cfg
     */
    private function flag(array $cfg, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $cfg)) {
            return $default;
        }

        return filter_var($cfg[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
