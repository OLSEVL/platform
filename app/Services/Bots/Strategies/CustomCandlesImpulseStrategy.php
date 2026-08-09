<?php

namespace App\Services\Bots\Strategies;

use App\Support\OsEngineBinaryDecision;

/**
 * CustomCandlesImpulseTrader — порт OsEngine Patterns под CALL/PUT.
 *
 * Вход: N последних закрытых свечей подряд вверх (CALL) или вниз (PUT),
 * и TimeSpan между TimeStart(last) и TimeStart(last−N) ≤ seconds_time_on_candles.
 *
 * Выход OsEngine (через M баров после сигнала) не переносится — expiry_sec.
 * На M1: N=2 и seconds=120 ≈ два минутных бара подряд.
 */
class CustomCandlesImpulseStrategy implements BotStrategyInterface
{
    public const DEFAULT_CANDLES_COUNT_TO_ENTRY = 2;

    /** Секунды на весь импульс (OsEngine default 120). */
    public const DEFAULT_SECONDS_TIME_ON_CANDLES = 120;

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];

        $nEntry = max(1, (int) ($cfg['candles_count_to_entry'] ?? self::DEFAULT_CANDLES_COUNT_TO_ENTRY));
        $maxSec = max(1, (int) ($cfg['seconds_time_on_candles'] ?? self::DEFAULT_SECONDS_TIME_ON_CANDLES));

        // OsEngine: candles.Count < candlesCountToEntry + 2
        if (count($closedCandles) < $nEntry + 2) {
            return null;
        }

        $n = count($closedCandles);
        $last = $closedCandles[$n - 1];
        $lastClose = (float) $last['close'];
        $lastTime = (int) ($last['time'] ?? 0);

        // Референсный бар: index = Count−1−N (как OsEngine TimeSpan).
        $refIdx = $n - 1 - $nEntry;
        if ($refIdx < 0) {
            return null;
        }
        $refTime = (int) ($closedCandles[$refIdx]['time'] ?? 0);
        if ($lastTime <= 0 || $refTime <= 0) {
            return null;
        }
        $spanSec = $lastTime - $refTime;
        if ($spanSec > $maxSec) {
            return null;
        }

        // Проверяем N последних баров: IsUp / IsDown (close ? open).
        $allUp = true;
        $allDown = true;
        $bodySumPct = 0.0;
        for ($i = $n - 1; $i > $n - 1 - $nEntry; $i--) {
            $bar = $closedCandles[$i];
            $o = (float) $bar['open'];
            $c = (float) $bar['close'];
            if ($c == 0.0) {
                return null;
            }
            $bodySumPct += abs($o - $c) / ($c / 100.0);
            if (! ($c > $o)) {
                $allUp = false;
            }
            if (! ($c < $o)) {
                $allDown = false;
            }
        }

        if ($allUp) {
            $side = 'CALL';
            $setup = 'impulse_candles_bull';
        } elseif ($allDown) {
            $side = 'PUT';
            $setup = 'impulse_candles_bear';
        } else {
            return null;
        }

        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        // Глубже «плотность» импульса: больше суммарное тело и короче span → выше depth.
        $avgBody = $bodySumPct / $nEntry;
        $timeTight = 1.0 - min(1.0, $spanSec / max($maxSec, 1));
        $depth = min(1.0, 0.55 * min(1.0, $avgBody / 0.1) + 0.45 * $timeTight);
        $confidence = OsEngineBinaryDecision::confidence($depth, $payout);

        $reasons = [
            $side === 'CALL'
                ? sprintf('Импульс: %d бычьих свечей за %d с (лимит %d с).', $nEntry, $spanSec, $maxSec)
                : sprintf('Импульс: %d медвежьих свечей за %d с (лимит %d с).', $nEntry, $spanSec, $maxSec),
            sprintf('Close=%.8f · avg_body=%.3f%%', $lastClose, $avgBody),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            'custom_candles_impulse_v1',
            $confidence,
            $reasons,
            sprintf(
                'custom_candles_impulse: %s; n=%d span=%ds close=%.8f',
                $setup,
                $nEntry,
                $spanSec,
                $lastClose
            ),
            [
                'close' => round($lastClose, 8),
                'candles_count_to_entry' => $nEntry,
                'span_sec' => $spanSec,
                'seconds_time_on_candles' => $maxSec,
                'avg_body_pct' => round($avgBody, 6),
                'level_price' => round($lastClose, 8),
                'level_kind' => 'pattern',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'entry_regime' => 'impulse',
                'partial_present' => $partialCandle !== null,
            ],
            $payout,
        );
    }
}
