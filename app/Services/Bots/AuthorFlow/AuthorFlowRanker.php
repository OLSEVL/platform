<?php

namespace App\Services\Bots\AuthorFlow;

use App\Support\AuthorFlowConfig;

/**
 * Один победитель на last closed bar: priority ASC, score DESC; tie CALL/PUT → skip.
 */
final class AuthorFlowRanker implements AuthorFlowRankerInterface
{
    public function pick(array $candidates, array $context, array $config): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $lastIndex = (int) ($context['last_bar_index'] ?? -1);
        if ($lastIndex < 0) {
            return null;
        }

        $requireStructure = AuthorFlowConfig::flag($config, 'af_require_structure', true);
        $allowRange = AuthorFlowConfig::flag($config, 'af_allow_range_entries', false);
        $requireLiq = AuthorFlowConfig::flag($config, 'af_require_open_liquidity', false);
        $bias = (string) ($context['structure_bias'] ?? 'range');

        $pool = [];
        foreach ($candidates as $c) {
            if (! is_array($c)) {
                continue;
            }
            // skop_zone не входит в v1.
            if ((string) ($c['setup'] ?? '') === 'skop_zone') {
                continue;
            }
            if ((int) ($c['bar_index'] ?? -1) !== $lastIndex) {
                continue;
            }
            $side = strtoupper((string) ($c['side'] ?? ''));
            if ($side !== 'CALL' && $side !== 'PUT') {
                continue;
            }

            if ($requireStructure) {
                if ($bias === 'range' && ! $allowRange) {
                    continue;
                }
                if ($bias === 'up' && $side === 'PUT') {
                    continue;
                }
                if ($bias === 'down' && $side === 'CALL') {
                    continue;
                }
            }

            if ($requireLiq) {
                $ok = ($side === 'CALL' && ! empty($context['open_liquidity_call']))
                    || ($side === 'PUT' && ! empty($context['open_liquidity_put']));
                if (! $ok) {
                    continue;
                }
            }

            $pool[] = $c;
        }

        if ($pool === []) {
            return null;
        }

        usort($pool, static function (array $a, array $b): int {
            $pa = (int) ($a['priority'] ?? 99);
            $pb = (int) ($b['priority'] ?? 99);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $sa = (float) ($a['score'] ?? 0);
            $sb = (float) ($b['score'] ?? 0);

            return $sb <=> $sa; // score DESC
        });

        $best = $pool[0];
        $bestPri = (int) ($best['priority'] ?? 99);
        $bestScore = (float) ($best['score'] ?? 0);
        $bestSide = strtoupper((string) ($best['side'] ?? ''));

        // Конфликт CALL vs PUT на одном priority и равном score → не гадать.
        foreach ($pool as $i => $c) {
            if ($i === 0) {
                continue;
            }
            if ((int) ($c['priority'] ?? 99) !== $bestPri) {
                break;
            }
            $side = strtoupper((string) ($c['side'] ?? ''));
            $score = (float) ($c['score'] ?? 0);
            if ($side !== $bestSide && abs($score - $bestScore) < 1e-9) {
                return null;
            }
        }

        $suppressed = [];
        foreach (array_slice($pool, 1) as $c) {
            $suppressed[] = [
                'setup' => (string) ($c['setup'] ?? ''),
                'side' => (string) ($c['side'] ?? ''),
                'priority' => (int) ($c['priority'] ?? 0),
                'score' => (float) ($c['score'] ?? 0),
            ];
        }
        $features = is_array($best['features'] ?? null) ? $best['features'] : [];
        $features['suppressed'] = $suppressed;
        $best['features'] = $features;

        return $best;
    }
}
