<?php

namespace App\Services\Bots\Strategies;

/**
 * Детектор сильного тренда на M1: N закрытых свечей подряд в одну сторону.
 * Режим исполнения (алерт / сделка) задаётся в config бота (execution_mode).
 *
 * Доп. фильтры качества (по статистике ~50% WR без них):
 * - min/max доля тела в диапазоне свечи (отсекает доджи и «перетянутые» late-entry свечи);
 * - опциональный вход только после отката к EMA/HMA (не на голой N-й свече импульса).
 */
class StrongTrendTelegramStrategy implements BotStrategyInterface
{
    /** Дефолт длины серии, если в config бота не задан streak_len. */
    private const DEFAULT_STREAK = 6;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $streakLen = max(2, min(30, (int) ($cfg['streak_len'] ?? self::DEFAULT_STREAK)));
        // strict: close>open / close<open; with_doji: close>=open / close<=open.
        $strictness = (string) ($cfg['candle_strictness'] ?? 'strict');
        // 0 = фильтр выключен; иначе средняя доля тела в диапазоне свечи должна быть не ниже порога.
        $minBodyRatio = max(0.0, min(1.0, (float) ($cfg['min_body_to_range_ratio'] ?? 0.0)));
        // 0 = выкл.; иначе средняя доля тела не выше порога (отсекает «ракеты» без отката).
        $maxBodyRatio = max(0.0, min(1.0, (float) ($cfg['max_body_to_range_ratio'] ?? 0.0)));
        // Вход после отката к MA: импульс → касание EMA/HMA → продолжение на последней свече.
        $requirePullback = (bool) ($cfg['require_pullback_to_ma'] ?? false);
        $pullbackBars = max(1, min(5, (int) ($cfg['pullback_bars'] ?? 2)));
        $maType = strtolower((string) ($cfg['pullback_ma_type'] ?? 'ema'));
        if (! in_array($maType, ['ema', 'hma'], true)) {
            $maType = 'ema';
        }
        $maLength = max(2, min(100, (int) ($cfg['pullback_ma_length'] ?? 9)));
        $touchAtrMult = max(0.05, min(2.0, (float) ($cfg['pullback_touch_atr_mult'] ?? 0.35)));

        // Импульс + окно отката + входная свеча.
        $needBars = $requirePullback
            ? max($streakLen + 1, $maLength + 5, 20)
            : $streakLen;
        if (count($closedCandles) < $needBars) {
            return null;
        }

        $n = count($closedCandles);
        $direction = null;
        $maAtEntry = null;
        $pullbackTouched = null;
        $extensionAtr = null;

        // Базовый детектор: N последних свечей в одну сторону (как раньше).
        /** @var list<array<string, mixed>> $last */
        $last = array_slice($closedCandles, -$streakLen);
        if ($this->allBull($last, $strictness)) {
            $direction = 'up';
        } elseif ($this->allBear($last, $strictness)) {
            $direction = 'down';
        }
        if ($direction === null) {
            return null;
        }

        $bodyCandles = $last;
        $entryBar = $closedCandles[$n - 1];

        if ($requirePullback) {
            // Мягкий фильтр late-entry (не отдельная фаза «импульс→откат→вход», которая почти
            // никогда не срабатывала на M1 OTC):
            // 1) в серии хотя бы одно касание зоны MA (откат внутри импульса), ИЛИ
            // 2) последняя цена не улетела дальше max_extension × ATR от MA.
            $closes = array_map(static fn (array $c): float => (float) $c['close'], $closedCandles);
            $maSeries = $maType === 'hma'
                ? $this->hmaSeries($closes, $maLength)
                : $this->emaSeries($closes, $maLength);
            $maAtEntry = $maSeries[$n - 1] ?? null;
            if ($maAtEntry === null || ! is_finite($maAtEntry)) {
                return null;
            }

            $atr = $this->atr($closedCandles, 14);
            $touchDist = max($atr * $touchAtrMult, abs($maAtEntry) * 1e-6);
            // Насколько далеко от MA ещё допустим вход (по умолчанию 1.8×ATR).
            $maxExtMult = max(0.5, min(5.0, (float) ($cfg['pullback_max_extension_atr'] ?? 1.8)));
            $maxExt = max($atr * $maxExtMult, $touchDist);
            $extensionAtr = $atr > 0 ? abs((float) $entryBar['close'] - $maAtEntry) / $atr : null;

            $pullbackTouched = false;
            $streakStart = $n - $streakLen;
            for ($i = $streakStart; $i < $n; $i++) {
                $ma = $maSeries[$i] ?? null;
                if ($ma === null || ! is_finite($ma)) {
                    continue;
                }
                $bar = $closedCandles[$i];
                if ($direction === 'up') {
                    if ((float) $bar['low'] <= $ma + $touchDist) {
                        $pullbackTouched = true;
                        break;
                    }
                } elseif ((float) $bar['high'] >= $ma - $touchDist) {
                    $pullbackTouched = true;
                    break;
                }
            }

            $close = (float) $entryBar['close'];
            $notOverextended = abs($close - $maAtEntry) <= $maxExt;
            // Нужно: касание MA в серии ИЛИ цена ещё недалеко от MA; плюс close по сторону тренда.
            if (! $pullbackTouched && ! $notOverextended) {
                return null;
            }
            if ($direction === 'up' && $close < $maAtEntry - $touchDist) {
                return null;
            }
            if ($direction === 'down' && $close > $maAtEntry + $touchDist) {
                return null;
            }
        }

        $avgBody = $this->avgBodyToRangeRatio($bodyCandles);
        if ($minBodyRatio > 0.0 && $avgBody < $minBodyRatio) {
            return null;
        }
        if ($maxBodyRatio > 0.0 && $avgBody > $maxBodyRatio) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;
        $confidence = round(0.60 * min(1.0, $avgBody / 0.45) + 0.40 * $payoutFactor, 4);
        $confidence = max($confidence, 0.55);
        // Небольшой бонус, если серия реально касалась MA (не голый late-chase).
        if ($requirePullback && $pullbackTouched) {
            $confidence = min(1.0, round($confidence + 0.03, 4));
        }

        $strictnessLabel = $strictness === 'with_doji' ? 'с доджи' : 'строго';
        $reasons = [
            sprintf(
                '%d свечей подряд %s (%s).',
                $streakLen,
                $direction === 'up' ? 'вверх' : 'вниз',
                $strictnessLabel
            ),
            sprintf('Средняя доля тела в диапазоне: %.0f%%', $avgBody * 100),
        ];
        if ($requirePullback) {
            $reasons[] = sprintf(
                'Фильтр %s(%d): %s, dist=%.2f×ATR.',
                strtoupper($maType),
                $maLength,
                $pullbackTouched ? 'касание MA в серии' : 'цена недалеко от MA',
                $extensionAtr ?? 0.0
            );
        }
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        // side в БД: CALL = восходящий тренд, PUT = нисходящий.
        $side = $direction === 'up' ? 'CALL' : 'PUT';
        $executionMode = (string) ($cfg['execution_mode'] ?? ((bool) ($cfg['notify_only'] ?? true) ? 'alert_only' : 'signal_trade'));

        return [
            'trend' => $direction,
            'side' => $side,
            'confidence' => $confidence,
            'notify_only' => $executionMode === 'alert_only',
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'strong_trend_telegram: %d×%s; strictness=%s; pullback=%s; confidence=%.2f.',
                $streakLen,
                $direction,
                $strictness,
                $requirePullback ? 'soft' : 'off',
                $confidence
            ),
            'features' => [
                'streak_len' => $streakLen,
                'trend' => $direction,
                'candle_strictness' => $strictness,
                'min_body_to_range_ratio' => $minBodyRatio,
                'max_body_to_range_ratio' => $maxBodyRatio,
                'avg_body_to_range' => round($avgBody, 4),
                'require_pullback_to_ma' => $requirePullback,
                'pullback_mode' => $requirePullback ? 'soft_near_ma' : null,
                'pullback_bars' => $requirePullback ? $pullbackBars : null,
                'pullback_ma_type' => $requirePullback ? $maType : null,
                'pullback_ma_length' => $requirePullback ? $maLength : null,
                'pullback_ma_at_entry' => $maAtEntry !== null ? round((float) $maAtEntry, 8) : null,
                'pullback_touched' => $pullbackTouched,
                'pullback_extension_atr' => $extensionAtr !== null ? round($extensionAtr, 4) : null,
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'strong_trend_telegram_v2',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function allBull(array $candles, string $strictness): bool
    {
        foreach ($candles as $c) {
            $open = (float) $c['open'];
            $close = (float) $c['close'];
            if ($strictness === 'with_doji') {
                if ($close < $open) {
                    return false;
                }
            } elseif ($close <= $open) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function allBear(array $candles, string $strictness): bool
    {
        foreach ($candles as $c) {
            $open = (float) $c['open'];
            $close = (float) $c['close'];
            if ($strictness === 'with_doji') {
                if ($close > $open) {
                    return false;
                }
            } elseif ($close >= $open) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     */
    private function avgBodyToRangeRatio(array $candles): float
    {
        if ($candles === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($candles as $c) {
            $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
            $body = abs((float) $c['close'] - (float) $c['open']);
            $sum += min(1.0, $body / $range);
        }

        return $sum / count($candles);
    }

    /**
     * Классический EMA; первые length-1 значений — null (прогрев).
     *
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    private function emaSeries(array $closes, int $length): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n < $length) {
            return $out;
        }
        $k = 2.0 / ($length + 1);
        $sum = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $sum += $closes[$i];
        }
        $ema = $sum / $length;
        $out[$length - 1] = $ema;
        for ($i = $length; $i < $n; $i++) {
            $ema = ($closes[$i] * $k) + ($ema * (1.0 - $k));
            $out[$i] = $ema;
        }

        return $out;
    }

    /**
     * Hull Moving Average (HMA): WMA(2·WMA(n/2) − WMA(n), round(sqrt(n))).
     *
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    private function hmaSeries(array $closes, int $length): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        $half = max(1, (int) floor($length / 2));
        $sqrtLen = max(1, (int) round(sqrt($length)));
        $wmaHalf = $this->wmaSeries($closes, $half);
        $wmaFull = $this->wmaSeries($closes, $length);
        $raw = [];
        for ($i = 0; $i < $n; $i++) {
            if ($wmaHalf[$i] === null || $wmaFull[$i] === null) {
                $raw[$i] = null;
            } else {
                $raw[$i] = (2.0 * $wmaHalf[$i]) - $wmaFull[$i];
            }
        }
        // WMA по «сырому» ряду; null в начале сохраняем.
        $filled = [];
        $firstValid = null;
        for ($i = 0; $i < $n; $i++) {
            if ($raw[$i] !== null) {
                $firstValid = $i;
                break;
            }
        }
        if ($firstValid === null) {
            return $out;
        }
        for ($i = 0; $i < $n; $i++) {
            $filled[$i] = $raw[$i] ?? $raw[$firstValid];
        }
        $wmaSqrt = $this->wmaSeries($filled, $sqrtLen);
        // HMA валидна только когда оба WMA исходного ряда уже посчитаны.
        $hmaReadyFrom = $length - 1 + $sqrtLen - 1;
        for ($i = 0; $i < $n; $i++) {
            if ($i >= $hmaReadyFrom && $wmaSqrt[$i] !== null && $raw[$i] !== null) {
                $out[$i] = $wmaSqrt[$i];
            }
        }

        return $out;
    }

    /**
     * @param  list<float>  $values
     * @return list<float|null>
     */
    private function wmaSeries(array $values, int $length): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($length < 1 || $n < $length) {
            return $out;
        }
        $denom = $length * ($length + 1) / 2.0;
        for ($i = $length - 1; $i < $n; $i++) {
            $sum = 0.0;
            for ($j = 0; $j < $length; $j++) {
                $sum += $values[$i - $length + 1 + $j] * ($j + 1);
            }
            $out[$i] = $sum / $denom;
        }

        return $out;
    }

    /**
     * Простой ATR(period) по последним барам (для зоны касания MA).
     *
     * @param  list<array<string, mixed>>  $candles
     */
    private function atr(array $candles, int $period): float
    {
        $n = count($candles);
        if ($n < 2) {
            return 0.0;
        }
        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $high = (float) $candles[$i]['high'];
            $low = (float) $candles[$i]['low'];
            $prevClose = (float) $candles[$i - 1]['close'];
            $trs[] = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
        }
        if ($trs === []) {
            return 0.0;
        }
        $slice = array_slice($trs, -max(1, $period));

        return array_sum($slice) / count($slice);
    }
}
