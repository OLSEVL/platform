<?php

namespace App\Services\Bots\AuthorFlow;

use App\Services\Indicators\SweepRaidLiquidityDetector;
use App\Support\AuthorFlowConfig;

/**
 * Сценарий E: пробой + закреп close за уровнем → продолжение (не fade).
 * Логика в SweepRaidLiquidityDetector::breakoutEntries; код E живёт в AuthorFlow как обёртка.
 */
final class BreakoutEScenario implements AuthorFlowScenarioInterface
{
    public function __construct(
        private readonly ?SweepRaidLiquidityDetector $detector = null,
    ) {}

    public function setupId(): string
    {
        return 'breakout_E';
    }

    public function basePriority(): int
    {
        return 10;
    }

    public function collect(array $closed, array $context): array
    {
        /** @var array<string,mixed> $config */
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        if (! AuthorFlowConfig::flag($config, 'af_enable_breakout_e', true)) {
            return [];
        }

        $pivot = max(1, min(10, (int) ($config['af_srl_pivot_length'] ?? 2)));
        $holds = max(1, min(4, (int) ($config['af_breakout_hold_closes'] ?? 2)));
        $detector = $this->detector ?? new SweepRaidLiquidityDetector;
        $entries = $detector->breakoutEntries($closed, $pivot, $holds);
        $out = [];

        foreach ($entries as $entry) {
            $side = strtoupper((string) ($entry['side'] ?? ''));
            if ($side !== 'CALL' && $side !== 'PUT') {
                continue;
            }
            $loc = (string) ($entry['location'] ?? '');
            $candidate = [
                'setup' => $this->setupId(),
                'side' => $side,
                'bar_index' => (int) ($entry['bar_index'] ?? -1),
                'time' => (int) ($entry['time'] ?? 0),
                'entry_price' => (float) ($entry['entry_price'] ?? 0),
                'priority' => $this->basePriority(),
                'features' => [
                    'level_price' => (float) ($entry['level_price'] ?? 0),
                    'level_type' => $loc === 'high' ? 'FH' : 'FL',
                    'level_time' => (int) ($entry['level_time'] ?? 0),
                    'reason_ru' => 'Пробой + закреп → продолжение',
                ],
            ];
            $candidate['features'] = AuthorFlowScore::enrichFeatures($candidate, $context);
            $candidate['score'] = AuthorFlowScore::compute($candidate, $context, $config);
            $out[] = $candidate;
        }

        return $out;
    }
}
