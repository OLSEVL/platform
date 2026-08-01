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

    /** Confidence: база + глубина сетапа (0..1) + payout factor. */
    public static function confidence(float $depth, ?float $payout, float $wDepth = 0.45, float $wBase = 0.25, float $wPay = 0.30): float
    {
        $payoutFactor = $payout !== null ? min(1.0, max(0.0, $payout / 100.0)) : 0.5;
        $depth = min(1.0, max(0.0, $depth));

        return $wDepth * (0.55 + 0.45 * $depth) + $wBase * 0.7 + $wPay * $payoutFactor;
    }
}
