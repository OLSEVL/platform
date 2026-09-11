<?php

namespace App\Support;

/**
 * Общая упаковка решения OsEngine→CALL/PUT (confidence + features для Telegram/cards).
 */
final class OsEngineBinaryDecision
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $features
     * @return array<string, mixed>
     */
    public static function pack(
        string $side,
        string $setup,
        string $version,
        float $confidence,
        array $reasons,
        string $analysisText,
        array $features,
        ?float $payout = null,
    ): array {
        $confidence = max(0.56, min(0.95, round($confidence, 4)));
        if ($payout !== null) {
            $features['payout'] = $payout;
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => $analysisText,
            'features' => array_merge(['setup' => $setup, 'entry_regime' => 'break'], $features),
            'decision_version' => $version,
        ];
    }

    /**
     * Confidence: база + глубина сетапа (0..1).
     * Payout в формулу не входит — пары с низкой выплатой отсекаются отдельно через min_payout.
     *
     * @param  ?float  $payout  Оставлен для совместимости вызовов; не используется в расчёте.
     */
    public static function confidence(float $depth, ?float $payout = null, float $wDepth = 0.45, float $wBase = 0.25): float
    {
        $depth = min(1.0, max(0.0, $depth));
        // Бывший вес payout (0.30) перераспределён пропорционально между depth и base.
        $setupScore = $wDepth * (0.55 + 0.45 * $depth) + $wBase * 0.7;

        return $setupScore / ($wDepth + $wBase);
    }
}
