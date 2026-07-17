<?php

namespace App\Services\Bots\Strategies;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Контртренд после 3 свечей в одну сторону (bull→PUT / bear→CALL).
 *
 * Опциональные фильтры качества из config бота (golden-setup по статистике):
 * - min_body_to_range_ratio — отсекает слабые/доджи-серии;
 * - trade_hour_utc_from / trade_hour_utc_to — торговое окно по UTC (включительно).
 */
class ThreeCandlesInRowStrategy implements BotStrategyInterface
{
    /** Сколько подряд закрытых свечей в одну сторону ищем (фиксировано по ТЗ). */
    private const STREAK = 3;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        if (count($closedCandles) < self::STREAK) {
            return null;
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        // Окно торговли по UTC: если задано — вне часов сигнал не генерируем.
        // Пример golden: from=7, to=21 → работаем 07:00–21:59 UTC.
        if (! $this->isWithinTradeHoursUtc($cfg, $context)) {
            return null;
        }

        /** @var list<array<string,mixed>> $last */
        $last = array_slice($closedCandles, -self::STREAK);

        $side = null;
        $direction = null;
        if ($this->allBull($last)) {
            $side = 'PUT';
            $direction = 'bull';
        } elseif ($this->allBear($last)) {
            $side = 'CALL';
            $direction = 'bear';
        }

        if ($side === null) {
            return null;
        }

        $avgBody = $this->avgBodyToRangeRatio($last);

        // 0 = фильтр выключен; иначе средняя доля тела в диапазоне должна быть ≥ порога.
        // По статистике bot#3: body≥0.65 на 30с даёт заметный прирост WR.
        $minBodyRatio = max(0.0, min(1.0, (float) ($cfg['min_body_to_range_ratio'] ?? 0.0)));
        if ($minBodyRatio > 0.0 && $avgBody < $minBodyRatio) {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;

        // Простая оценка для фильтра min_confidence в движке (тела свечей + доля выплаты).
        $confidence = round(0.55 * min(1.0, $avgBody / 0.45) + 0.45 * $payoutFactor, 4);
        $confidence = max($confidence, 0.56);

        $hourFrom = $this->optionalHour($cfg['trade_hour_utc_from'] ?? null);
        $hourTo = $this->optionalHour($cfg['trade_hour_utc_to'] ?? null);

        $reasons = [
            sprintf(
                'Три свечи подряд %s → вход %s на 1 период экспирации (против направления серии).',
                $direction === 'bull' ? 'вверх' : 'вниз',
                $side
            ),
            sprintf('Средняя доля тела в диапазоне: %.0f%%', $avgBody * 100),
        ];
        if ($minBodyRatio > 0.0) {
            $reasons[] = sprintf('Фильтр min body/range: ≥%.0f%%', $minBodyRatio * 100);
        }
        if ($hourFrom !== null && $hourTo !== null) {
            $reasons[] = sprintf('Торговое окно UTC: %02d–%02d', $hourFrom, $hourTo);
        }
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        $analysisText = sprintf(
            'three_same_then_opposite: %d×%s → %s; confidence=%.2f.',
            self::STREAK,
            $direction,
            $side,
            $confidence
        );

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => $analysisText,
            'features' => [
                'streak_len' => self::STREAK,
                'direction' => $direction,
                'avg_body_to_range' => round($avgBody, 4),
                'min_body_to_range_ratio' => $minBodyRatio,
                'trade_hour_utc_from' => $hourFrom,
                'trade_hour_utc_to' => $hourTo,
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'three_same_then_opposite_v2',
        ];
    }

    /**
     * Проверка торгового окна UTC.
     * Если from/to не заданы оба — окно выключено (торгуем 24/7).
     * Для тестов можно передать context.now_utc (Carbon|DateTimeInterface|string).
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $context
     */
    private function isWithinTradeHoursUtc(array $cfg, array $context): bool
    {
        $from = $this->optionalHour($cfg['trade_hour_utc_from'] ?? null);
        $to = $this->optionalHour($cfg['trade_hour_utc_to'] ?? null);
        if ($from === null || $to === null) {
            return true;
        }

        $now = $context['now_utc'] ?? null;
        if ($now instanceof CarbonInterface) {
            $hour = (int) $now->utc()->hour;
        } elseif ($now instanceof \DateTimeInterface) {
            $hour = (int) Carbon::instance(\DateTimeImmutable::createFromInterface($now))->utc()->hour;
        } elseif (is_string($now) && $now !== '') {
            $hour = (int) Carbon::parse($now, 'UTC')->hour;
        } else {
            $hour = (int) now('UTC')->hour;
        }

        // Обычный диапазон (07–21): hour ∈ [from, to].
        // Через полночь (напр. 22–5): hour ≥ from ИЛИ hour ≤ to.
        if ($from <= $to) {
            return $hour >= $from && $hour <= $to;
        }

        return $hour >= $from || $hour <= $to;
    }

    /**
     * Нормализация часа 0..23; пустое/невалидное → null (фильтр выкл.).
     */
    private function optionalHour(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw)) {
            return null;
        }
        $hour = (int) $raw;

        return ($hour >= 0 && $hour <= 23) ? $hour : null;
    }

    /**
     * Все свечи строго бычьи (тело вверх, без доджи).
     *
     * @param  list<array<string,mixed>>  $candles
     */
    private function allBull(array $candles): bool
    {
        foreach ($candles as $c) {
            if (! ((float) $c['close'] > (float) $c['open'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Все свечи строго медвежьи.
     *
     * @param  list<array<string,mixed>>  $candles
     */
    private function allBear(array $candles): bool
    {
        foreach ($candles as $c) {
            if (! ((float) $c['close'] < (float) $c['open'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Средняя доля тела в диапазоне свечи по группе.
     *
     * @param  list<array<string,mixed>>  $candles
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
}
