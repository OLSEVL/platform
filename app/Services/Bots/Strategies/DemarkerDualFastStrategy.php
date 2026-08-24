<?php

namespace App\Services\Bots\Strategies;

use App\Support\DemarkerDualFastConfig;
use App\Services\Indicators\DemarkerIndicatorService;

/**
 * Dual DeMarker Fast (стратегия №3):
 * 1) DeM1 выходит из зоны с LEVEL_DELTA;
 * 2) в зоне до выхода было ≥ MIN_CANDLES_IN_ZONE баров;
 * 3) DeM2 (если включён) подтверждает выход в окне confirm_bars;
 * 4) фильтр импульса: |H−L| сигнала / avg(|H−L|) ≤ max_ratio;
 * 5) опционально streak одноцветных свечей до входа DeM1 в зону.
 *
 * Колена (entries_per_signal) обрабатывает движок/settlement, не стратегия.
 */
class DemarkerDualFastStrategy implements BotStrategyInterface
{
    public function __construct(
        private readonly DemarkerIndicatorService $demarker,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $config */
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];

        // --- Параметры основного DeMarker (в форме % → внутри 0..1) ---
        $period = max(1, min(500, (int) ($config['demarker_period'] ?? 10)));
        $oversold = max(0.0, min(1.0, (float) ($config['demarker_oversold'] ?? 15) / 100));
        $overbought = max(0.0, min(1.0, (float) ($config['demarker_overbought'] ?? 85) / 100));
        // Дельта в «пунктах процента» (2.0 → 0.02): мин. возврат за порог зоны.
        $levelDelta = max(0.0, min(50.0, (float) ($config['demarker_level_delta'] ?? 2.0))) / 100;

        // --- Быстрый DeMarker ---
        $secondEnabled = (bool) ($config['second_demarker_enabled'] ?? true);
        $secondPeriod = max(1, min(500, (int) ($config['second_demarker_period'] ?? 5)));
        $secondOversold = max(0.0, min(1.0, (float) ($config['second_demarker_oversold'] ?? 5) / 100));
        $secondOverbought = max(0.0, min(1.0, (float) ($config['second_demarker_overbought'] ?? 95) / 100));
        $secondDelta = max(0.0, min(50.0, (float) ($config['second_demarker_level_delta'] ?? 3.0))) / 100;
        // Сколько баров назад (включая текущий) искать подтверждение DeM2.
        $confirmBars = max(1, min(30, (int) ($config['second_demarker_confirm_bars'] ?? 3)));

        $minInZone = max(1, min(50, (int) ($config['min_candles_in_zone'] ?? 3)));
        $maxExitRatio = max(0.1, min(20.0, (float) ($config['max_exit_candle_ratio'] ?? 2.0)));
        $avgBars = max(1, min(100, (int) ($config['exit_candle_avg_bars'] ?? 10)));
        $entriesPerSignal = max(1, min(5, (int) ($config['entries_per_signal'] ?? 2)));

        // Опциональный streak поверх dual-DeMarker (0 = выкл.).
        $streakLength = max(0, min(30, (int) ($config['streak_len'] ?? 0)));
        $strictness = (string) ($config['candle_strictness'] ?? 'strict');
        if (! in_array($strictness, ['strict', 'with_doji'], true)) {
            $strictness = 'strict';
        }
        $minBodyRatio = max(0.0, min(1.0, (float) ($config['min_body_to_range_ratio'] ?? 0.0)));

        if ($oversold >= $overbought || ($secondEnabled && $secondOversold >= $secondOverbought)) {
            return null;
        }

        // confirm_bars смотрит назад по уже имеющейся истории — отдельный запас не нужен.
        $needBars = max($period, $secondEnabled ? $secondPeriod : 0) + 2
            + $minInZone
            + $avgBars
            + $streakLength;
        if (count($closedCandles) < $needBars) {
            return null;
        }

        $dem1 = $this->demarker->computeSeries($closedCandles, ['period' => $period]);
        $last = array_key_last($dem1);
        $prev1 = (float) $dem1[$last - 1];
        $curr1 = (float) $dem1[$last];

        // CALL: был ≤ OS, стал ≥ OS+Δ; PUT: был ≥ OB, стал ≤ OB−Δ.
        $isCall = $this->isExitUp($prev1, $curr1, $oversold, $levelDelta);
        $isPut = $this->isExitDown($prev1, $curr1, $overbought, $levelDelta);
        if (! $isCall && ! $isPut) {
            return null;
        }

        $side = $isCall ? 'CALL' : 'PUT';
        $trend = $isCall ? 'up' : 'down';

        // Бары DeM1 строго в зоне до выхода (бар выхода не входит в счёт).
        $zoneBars = $this->countBarsInZoneBeforeExit($dem1, $last, $side, $oversold, $overbought);
        if ($zoneBars < $minInZone) {
            return null;
        }

        // Подтверждение быстрым DeM2 в окне [last−N+1 … last].
        $secondConfirmIndex = null;
        $prev2 = null;
        $curr2 = null;
        if ($secondEnabled) {
            $dem2 = $this->demarker->computeSeries($closedCandles, ['period' => $secondPeriod]);
            $secondConfirmIndex = $this->findSecondConfirmIndex(
                $dem2,
                $last,
                $confirmBars,
                $side,
                $secondOversold,
                $secondOverbought,
                $secondDelta,
            );
            if ($secondConfirmIndex === null) {
                return null;
            }
            $prev2 = (float) $dem2[$secondConfirmIndex - 1];
            $curr2 = (float) $dem2[$secondConfirmIndex];
        }

        // Фильтр импульса: сигнальная свеча не должна быть «ракетой» относительно среднего диапазона.
        if (! $this->passesExitCandleRatio($closedCandles, $last, $avgBars, $maxExitRatio)) {
            return null;
        }

        // Опциональный streak: N одноцветных ДО входа DeM1 в зону.
        $streak = [];
        $zoneEntryIndex = null;
        if ($streakLength > 0) {
            $zoneEntryIndex = $this->currentZoneEntryIndex($dem1, $last, $side, $oversold, $overbought);
            if ($zoneEntryIndex === null || $zoneEntryIndex < $streakLength) {
                return null;
            }
            $streak = array_slice($closedCandles, $zoneEntryIndex - $streakLength, $streakLength);
            if ($isCall && ! $this->allBull($streak, $strictness)) {
                return null;
            }
            if ($isPut && ! $this->allBear($streak, $strictness)) {
                return null;
            }
            $avgBody = $this->avgBodyToRangeRatio($streak);
            if ($minBodyRatio > 0.0 && $avgBody < $minBodyRatio) {
                return null;
            }
        } else {
            $avgBody = 0.0;
        }

        $zone = $isCall ? 'перепроданности' : 'перекупленности';
        $reasons = [
            sprintf(
                'DeM1(%d) выход из %s с Δ%.1f: %.1f → %.1f (в зоне %d≥%d бар.).',
                $period,
                $zone,
                $levelDelta * 100,
                $prev1 * 100,
                $curr1 * 100,
                $zoneBars,
                $minInZone
            ),
        ];
        if ($secondEnabled && $secondConfirmIndex !== null && $prev2 !== null && $curr2 !== null) {
            $reasons[] = sprintf(
                'DeM2(%d) подтвердил в окне %d бар. (бар −%d): %.1f → %.1f.',
                $secondPeriod,
                $confirmBars,
                $last - $secondConfirmIndex,
                $prev2 * 100,
                $curr2 * 100
            );
        }
        $reasons[] = sprintf('Импульс: |H−L| сигнала / avg(%d) ≤ %.2f.', $avgBars, $maxExitRatio);
        if ($streakLength > 0) {
            $reasons[] = sprintf(
                '%d свечей %s до зоны (доля тела %.0f%%).',
                $streakLength,
                $trend === 'up' ? 'вверх' : 'вниз',
                $avgBody * 100
            );
        }
        if ($entriesPerSignal > 1) {
            $reasons[] = sprintf('Колен на сигнал: %d (2-е только после LOSS).', $entriesPerSignal);
        }

        $signalCandle = $closedCandles[$last];
        $signalRange = max(0.0, (float) $signalCandle['high'] - (float) $signalCandle['low']);

        return [
            'trend' => $trend,
            'side' => $side,
            'confidence' => 0.70,
            'notify_only' => false,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'demarker_dual_fast: DeM1(%d) %.3f→%.3f zone=%d; DeM2=%s; ratio_ok; streak=%d; %s; knees=%d.',
                $period,
                $prev1,
                $curr1,
                $zoneBars,
                $secondEnabled ? 'yes' : 'off',
                $streakLength,
                $side,
                $entriesPerSignal
            ),
            'features' => [
                'streak_len' => $streakLength,
                'streak_before_zone' => $streakLength > 0,
                'streak_from_time' => ($streak !== [] && isset($streak[0]['time'])) ? (int) $streak[0]['time'] : null,
                'streak_to_time' => ($streak !== [] && isset($streak[array_key_last($streak)]['time']))
                    ? (int) $streak[array_key_last($streak)]['time']
                    : null,
                'zone_entry_time' => ($zoneEntryIndex !== null && isset($closedCandles[$zoneEntryIndex]['time']))
                    ? (int) $closedCandles[$zoneEntryIndex]['time']
                    : null,
                'zone_bars' => $zoneBars,
                'min_candles_in_zone' => $minInZone,
                'trend' => $trend,
                'candle_strictness' => $strictness,
                'min_body_to_range_ratio' => $minBodyRatio,
                'avg_body_to_range' => round($avgBody, 4),
                'demarker_period' => $period,
                'demarker_previous' => round($prev1, 6),
                'demarker_current' => round($curr1, 6),
                'demarker_oversold' => $oversold,
                'demarker_overbought' => $overbought,
                'demarker_level_delta' => $levelDelta,
                'second_demarker_enabled' => $secondEnabled,
                'second_demarker_period' => $secondPeriod,
                'second_demarker_previous' => $prev2 !== null ? round($prev2, 6) : null,
                'second_demarker_current' => $curr2 !== null ? round($curr2, 6) : null,
                'second_demarker_confirm_bars' => $confirmBars,
                'second_demarker_confirm_offset' => $secondConfirmIndex !== null ? ($last - $secondConfirmIndex) : null,
                'max_exit_candle_ratio' => $maxExitRatio,
                'exit_candle_avg_bars' => $avgBars,
                'signal_range' => round($signalRange, 8),
                'entries_per_signal' => $entriesPerSignal,
                // Колено 1 открывает движок; follow-up читает это поле из features/meta.
                'knee' => 1,
            ],
            'decision_version' => DemarkerDualFastConfig::VERSION,
        ];
    }

    /** CALL-выход: prev ≤ OS и curr ≥ OS+Δ. */
    private function isExitUp(float $prev, float $curr, float $oversold, float $delta): bool
    {
        return $prev <= $oversold && $curr >= ($oversold + $delta);
    }

    /** PUT-выход: prev ≥ OB и curr ≤ OB−Δ. */
    private function isExitDown(float $prev, float $curr, float $overbought, float $delta): bool
    {
        return $prev >= $overbought && $curr <= ($overbought - $delta);
    }

    /**
     * Число подряд идущих баров в зоне непосредственно перед баром выхода.
     *
     * @param  list<float>  $demarker
     */
    private function countBarsInZoneBeforeExit(
        array $demarker,
        int $exitIndex,
        string $side,
        float $oversold,
        float $overbought,
    ): int {
        if ($exitIndex < 1) {
            return 0;
        }
        $inZone = $side === 'CALL'
            ? static fn (float $v): bool => $v <= $oversold
            : static fn (float $v): bool => $v >= $overbought;

        $count = 0;
        for ($i = $exitIndex - 1; $i >= 0; $i--) {
            if (! $inZone((float) $demarker[$i])) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * Индекс бара, на котором DeM2 дал выход в ту же сторону в окне confirmBars.
     *
     * @param  list<float>  $demarker
     */
    private function findSecondConfirmIndex(
        array $demarker,
        int $lastIndex,
        int $confirmBars,
        string $side,
        float $oversold,
        float $overbought,
        float $delta,
    ): ?int {
        $from = max(1, $lastIndex - $confirmBars + 1);
        for ($i = $lastIndex; $i >= $from; $i--) {
            $prev = (float) $demarker[$i - 1];
            $curr = (float) $demarker[$i];
            $ok = $side === 'CALL'
                ? $this->isExitUp($prev, $curr, $oversold, $delta)
                : $this->isExitDown($prev, $curr, $overbought, $delta);
            if ($ok) {
                return $i;
            }
        }

        return null;
    }

    /**
     * true, если |H−L| сигнала / среднее |H−L| за avgBars предыдущих ≤ maxRatio.
     *
     * @param  list<array<string, mixed>>  $candles
     */
    private function passesExitCandleRatio(array $candles, int $lastIndex, int $avgBars, float $maxRatio): bool
    {
        if ($lastIndex < $avgBars) {
            return false;
        }
        $signal = $candles[$lastIndex];
        $signalRange = max(0.0, (float) $signal['high'] - (float) $signal['low']);
        $sum = 0.0;
        for ($i = $lastIndex - $avgBars; $i < $lastIndex; $i++) {
            $sum += max(0.0, (float) $candles[$i]['high'] - (float) $candles[$i]['low']);
        }
        $avg = $sum / $avgBars;
        if ($avg <= 1e-12) {
            // Нет диапазона у «тихих» баров — не считаем ракету, пропускаем фильтр.
            return true;
        }

        return ($signalRange / $avg) <= $maxRatio;
    }

    /**
     * Индекс первого бара текущего пребывания в зоне (вход осциллятора).
     *
     * @param  list<float>  $demarker
     */
    private function currentZoneEntryIndex(
        array $demarker,
        int $lastIndex,
        string $side,
        float $oversold,
        float $overbought,
    ): ?int {
        if ($lastIndex < 1) {
            return null;
        }
        $inZone = $side === 'CALL'
            ? static fn (float $v): bool => $v <= $oversold
            : static fn (float $v): bool => $v >= $overbought;
        if (! $inZone((float) $demarker[$lastIndex - 1])) {
            return null;
        }

        $i = $lastIndex - 1;
        while ($i >= 0 && $inZone((float) $demarker[$i])) {
            $i--;
        }
        $entry = $i + 1;
        if ($entry < 0 || $entry >= $lastIndex) {
            return null;
        }

        return $entry;
    }

    /** @param  list<array<string, mixed>>  $candles */
    private function allBull(array $candles, string $strictness): bool
    {
        foreach ($candles as $candle) {
            $open = (float) $candle['open'];
            $close = (float) $candle['close'];
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

    /** @param  list<array<string, mixed>>  $candles */
    private function allBear(array $candles, string $strictness): bool
    {
        foreach ($candles as $candle) {
            $open = (float) $candle['open'];
            $close = (float) $candle['close'];
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

    /** @param  list<array<string, mixed>>  $candles */
    private function avgBodyToRangeRatio(array $candles): float
    {
        if ($candles === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($candles as $candle) {
            $range = max(1e-8, (float) $candle['high'] - (float) $candle['low']);
            $body = abs((float) $candle['close'] - (float) $candle['open']);
            $sum += min(1.0, $body / $range);
        }

        return $sum / count($candles);
    }
}
