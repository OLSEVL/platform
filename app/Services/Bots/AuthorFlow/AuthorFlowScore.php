<?php

namespace App\Services\Bots\AuthorFlow;

use App\Support\AuthorFlowConfig;

/**
 * Score v1: база по setup + бонусы/штрафы контекста, clamp 0..1.
 */
final class AuthorFlowScore
{
    /**
     * @param  array<string,mixed>  $candidate  partial (setup, side, features…)
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $config
     */
    public static function compute(array $candidate, array $context, array $config): float
    {
        $setup = (string) ($candidate['setup'] ?? '');
        $kind = (string) (($candidate['features']['engulf_kind'] ?? '') ?: '');
        $base = match ($setup) {
            'breakout_E' => 0.70,
            'engulf_D' => $kind === 'same_color' ? 0.60 : 0.65,
            'raid_pb' => 0.64,
            'sweep' => 0.62,
            'skop_zone' => 0.50,
            default => 0.55,
        };

        $side = strtoupper((string) ($candidate['side'] ?? ''));
        $bias = (string) ($context['structure_bias'] ?? 'range');
        $structureAligned = ($bias === 'up' && $side === 'CALL')
            || ($bias === 'down' && $side === 'PUT');
        $openLiq = ($side === 'CALL' && ! empty($context['open_liquidity_call']))
            || ($side === 'PUT' && ! empty($context['open_liquidity_put']));

        $score = $base;
        if ($structureAligned) {
            $score += 0.08;
        }
        if ($openLiq) {
            $score += 0.06;
        }
        if ($bias === 'range' && AuthorFlowConfig::flag($config, 'af_require_structure', true)) {
            $score -= 0.10;
        }

        // Опциональный skop boost (v1 обычно выкл.).
        if (AuthorFlowConfig::flag($config, 'af_enable_skop_score', false)
            && ! empty($candidate['features']['skop_boost'])) {
            $score += 0.05;
        }

        return max(0.0, min(1.0, round($score, 4)));
    }

    /**
     * Дополнить features флагами alignment / liquidity.
     *
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function enrichFeatures(array $candidate, array $context): array
    {
        $side = strtoupper((string) ($candidate['side'] ?? ''));
        $bias = (string) ($context['structure_bias'] ?? 'range');
        $features = is_array($candidate['features'] ?? null) ? $candidate['features'] : [];
        $features['structure_aligned'] = ($bias === 'up' && $side === 'CALL')
            || ($bias === 'down' && $side === 'PUT');
        $features['open_liquidity'] = ($side === 'CALL' && ! empty($context['open_liquidity_call']))
            || ($side === 'PUT' && ! empty($context['open_liquidity_put']));

        return $features;
    }
}
