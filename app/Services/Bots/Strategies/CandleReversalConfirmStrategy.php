<?php

namespace App\Services\Bots\Strategies;

/**
 * Стратегия M1: вход после локального разворотного бара (pin/hammer или аналог) с подтверждением следующей свечой.
 *
 * Логика в два шага по последним ЗАКРЫТЫМ свечам:
 * 1) Предпоследняя свеча (reversal) — отскок от импульса (длинная тень «в сторону импульса», умеренное тело).
 * 2) Последняя свеча (confirm) — направление сделки и пробой экстремума разворотной свечи (по умолчанию).
 *
 * Импульс до разворота оценивается по окну из N баров строго перед разворотной свечой
 * (серия баров в сторону тренда — минимальное количество одноцветных тел).
 */
class CandleReversalConfirmStrategy implements BotStrategyInterface
{
    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        // Сколько закрытых баров смотрим назад от разворотной свечи, чтобы увидеть «импульс».
        $impulseLookback = max(3, min(40, (int) ($cfg['impulse_lookback'] ?? 10)));
        // Минимум медвежьих тел в окне импульса для сценария CALL (разворот вверх) и наоборот для PUT.
        $minImpulseBars = max(1, min($impulseLookback, (int) ($cfg['min_impulse_bars'] ?? 3)));
        // Доля тени с противоположной стороны импульса на разворотной свече (0..1).
        $minWick = (float) ($cfg['reversal_min_wick_ratio'] ?? 0.30);
        $minWick = max(0.12, min(0.75, $minWick));
        // Верхняя граница доли тела от полного диапазона — отсекаем «перегруженные» бары без чёткой тени.
        $maxBody = (float) ($cfg['reversal_max_body_ratio'] ?? 0.55);
        $maxBody = max(0.15, min(0.85, $maxBody));
        // Требовать, чтобы подтверждающая свеча закрылась за high/low разворотной (классическое подтверждение).
        $requireBreak = (bool) ($cfg['require_break_rev_extreme'] ?? true);
        // Доп. фильтр: в окне импульса цена должна пройти вниз (для CALL) / вверх (для PUT) по сравнению с началом окна.
        $requireNetMove = (bool) ($cfg['require_net_impulse_move'] ?? true);

        if (count($closedCandles) < $impulseLookback + 3) {
            return null;
        }

        $n = count($closedCandles);
        /** @var array<string,mixed> $confirm */
        $confirm = $closedCandles[$n - 1];
        /** @var array<string,mixed> $rev */
        $rev = $closedCandles[$n - 2];

        // Индекс первого бара окна импульса (включительно): ровно impulseLookback баров до разворотной, не считая саму rev.
        $impulseStart = $n - 2 - $impulseLookback;
        if ($impulseStart < 0) {
            return null;
        }
        /** @var list<array<string,mixed>> $impulseSlice */
        $impulseSlice = array_slice($closedCandles, $impulseStart, $impulseLookback);

        $callOk = $this->matchesCallSetup(
            $impulseSlice,
            $rev,
            $confirm,
            $minImpulseBars,
            $minWick,
            $maxBody,
            $requireBreak,
            $requireNetMove
        );
        $putOk = $this->matchesPutSetup(
            $impulseSlice,
            $rev,
            $confirm,
            $minImpulseBars,
            $minWick,
            $maxBody,
            $requireBreak,
            $requireNetMove
        );

        // Если оба паттерна формально выполнились (редко, но на шумных данных возможно) — не торгуем.
        if ($callOk && $putOk) {
            return null;
        }
        if (! $callOk && ! $putOk) {
            return null;
        }

        $side = $callOk ? 'CALL' : 'PUT';
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;

        $wickKey = $side === 'CALL' ? $this->lowerWickRatio($rev) : $this->upperWickRatio($rev);
        $wickScore = min(1.0, $wickKey / 0.55);
        $impulseScore = min(1.0, $this->impulseStrengthRatio($impulseSlice, $side === 'CALL') / max(1, $minImpulseBars));
        $confidence = round(0.40 * $wickScore + 0.35 * $impulseScore + 0.25 * $payoutFactor, 4);
        $confidence = max($confidence, 0.56);

        $reasons = [
            sprintf(
                '%s: импульс (%d баров) → разворотная свеча с тенью → подтверждение следующей.',
                $side === 'CALL' ? 'Разворот вверх' : 'Разворот вниз',
                $impulseLookback
            ),
            sprintf(
                'Тени разворота: lower=%.0f%%, upper=%.0f%%; тело/диапазон=%.0f%%.',
                $this->lowerWickRatio($rev) * 100,
                $this->upperWickRatio($rev) * 100,
                $this->bodyToRangeRatio($rev) * 100
            ),
            $requireBreak
                ? sprintf(
                    'Подтверждение: close confirm=%.5f vs экстремум rev (high=%.5f low=%.5f).',
                    (float) $confirm['close'],
                    (float) $rev['high'],
                    (float) $rev['low']
                )
                : 'Подтверждение: направление закрытия без обязательного пробоя экстремума rev.',
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'reversal_confirm: side=%s; impulse=%d; wick=%.2f; conf=%.2f',
                $side,
                $impulseLookback,
                $wickKey,
                $confidence
            ),
            'features' => [
                'impulse_lookback' => $impulseLookback,
                'min_impulse_bars' => $minImpulseBars,
                'reversal_lower_wick' => round($this->lowerWickRatio($rev), 4),
                'reversal_upper_wick' => round($this->upperWickRatio($rev), 4),
                'reversal_body_ratio' => round($this->bodyToRangeRatio($rev), 4),
                'confirm_bull' => $this->isBull($confirm),
                'confirm_bear' => $this->isBear($confirm),
                'require_break' => $requireBreak,
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'candle_reversal_confirm_v1',
        ];
    }

    /**
     * CALL: перед разворотом был медвежий импульс, разворот — бычий бар с выраженной нижней тенью,
     * подтверждение — бычий бар, закрытие выше high разворота (если включено).
     *
     * @param  list<array<string,mixed>>  $impulseSlice
     */
    private function matchesCallSetup(
        array $impulseSlice,
        array $rev,
        array $confirm,
        int $minImpulseBars,
        float $minWick,
        float $maxBody,
        bool $requireBreak,
        bool $requireNetMove
    ): bool {
        if ($this->countBearish($impulseSlice) < $minImpulseBars) {
            return false;
        }
        if ($requireNetMove && ! $this->netImpulseDown($impulseSlice)) {
            return false;
        }
        if (! $this->isBull($rev)) {
            return false;
        }
        if ($this->bodyToRangeRatio($rev) > $maxBody) {
            return false;
        }
        if ($this->lowerWickRatio($rev) < $minWick) {
            return false;
        }
        if (! $this->isBull($confirm)) {
            return false;
        }
        if ($requireBreak && ! ((float) $confirm['close'] > (float) $rev['high'])) {
            return false;
        }

        return true;
    }

    /**
     * PUT: бычий импульс, разворот — медвежий бар с верхней тенью, подтверждение — медвежий бар ниже low rev.
     *
     * @param  list<array<string,mixed>>  $impulseSlice
     */
    private function matchesPutSetup(
        array $impulseSlice,
        array $rev,
        array $confirm,
        int $minImpulseBars,
        float $minWick,
        float $maxBody,
        bool $requireBreak,
        bool $requireNetMove
    ): bool {
        if ($this->countBullish($impulseSlice) < $minImpulseBars) {
            return false;
        }
        if ($requireNetMove && ! $this->netImpulseUp($impulseSlice)) {
            return false;
        }
        if (! $this->isBear($rev)) {
            return false;
        }
        if ($this->bodyToRangeRatio($rev) > $maxBody) {
            return false;
        }
        if ($this->upperWickRatio($rev) < $minWick) {
            return false;
        }
        if (! $this->isBear($confirm)) {
            return false;
        }
        if ($requireBreak && ! ((float) $confirm['close'] < (float) $rev['low'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function netImpulseDown(array $bars): bool
    {
        if ($bars === []) {
            return false;
        }
        $first = (float) $bars[0]['close'];
        $last = (float) $bars[array_key_last($bars)]['close'];
        $eps = max(abs($first) * 1e-6, 1e-8);

        return $last < $first - $eps;
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function netImpulseUp(array $bars): bool
    {
        if ($bars === []) {
            return false;
        }
        $first = (float) $bars[0]['close'];
        $last = (float) $bars[array_key_last($bars)]['close'];
        $eps = max(abs($first) * 1e-6, 1e-8);

        return $last > $first + $eps;
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function countBearish(array $bars): int
    {
        $n = 0;
        foreach ($bars as $c) {
            if ($this->isBear($c)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function countBullish(array $bars): int
    {
        $n = 0;
        foreach ($bars as $c) {
            if ($this->isBull($c)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Нормированная «сила» импульса: число баров нужного цвета (для скоринга confidence).
     *
     * @param  list<array<string,mixed>>  $bars
     */
    private function impulseStrengthRatio(array $bars, bool $bearishImpulse): float
    {
        $c = $bearishImpulse ? $this->countBearish($bars) : $this->countBullish($bars);

        return (float) $c;
    }

    /** @param  array<string,mixed>  $c */
    private function isBull(array $c): bool
    {
        return (float) $c['close'] > (float) $c['open'];
    }

    /** @param  array<string,mixed>  $c */
    private function isBear(array $c): bool
    {
        return (float) $c['close'] < (float) $c['open'];
    }

    /** @param  array<string,mixed>  $c */
    private function bodyToRangeRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $body = abs((float) $c['close'] - (float) $c['open']);

        return min(1.0, $body / $range);
    }

    /** @param  array<string,mixed>  $c */
    private function lowerWickRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $bodyTop = max((float) $c['open'], (float) $c['close']);

        return min(1.0, ($bodyTop - (float) $c['low']) / $range);
    }

    /** @param  array<string,mixed>  $c */
    private function upperWickRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $bodyBot = min((float) $c['open'], (float) $c['close']);

        return min(1.0, ((float) $c['high'] - $bodyBot) / $range);
    }
}
