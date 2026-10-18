<?php

namespace App\Services\Bots\AuthorFlow;

use App\Support\AuthorFlowConfig;

/**
 * Скоп-зона (v1): не entry, только лёгкий boost score соседнему D/E у swing-уровня.
 */
final class SkopFeatures
{
    /**
     * Пометить кандидатов рядом с последним swing (скоп) флагом skop_boost.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $config
     * @return list<array<string,mixed>>
     */
    public function apply(array $candidates, array $context, array $config): array
    {
        if (! AuthorFlowConfig::flag($config, 'af_enable_skop_score', false)) {
            return $candidates;
        }

        $sh = $context['last_swing_high'] ?? null;
        $sl = $context['last_swing_low'] ?? null;
        if ($sh === null && $sl === null) {
            return $candidates;
        }

        foreach ($candidates as &$c) {
            $setup = (string) ($c['setup'] ?? '');
            if (! in_array($setup, ['engulf_D', 'breakout_E'], true)) {
                continue;
            }
            $price = (float) ($c['entry_price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $near = false;
            if ($sh !== null && abs($price - (float) $sh) / max((float) $sh, 1e-12) <= 0.002) {
                $near = true;
            }
            if ($sl !== null && abs($price - (float) $sl) / max((float) $sl, 1e-12) <= 0.002) {
                $near = true;
            }
            if (! $near) {
                continue;
            }
            $features = is_array($c['features'] ?? null) ? $c['features'] : [];
            $features['skop_boost'] = true;
            $c['features'] = $features;
            $c['score'] = AuthorFlowScore::compute($c, $context, $config);
        }
        unset($c);

        return $candidates;
    }
}
