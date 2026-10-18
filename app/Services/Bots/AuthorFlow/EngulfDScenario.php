<?php

namespace App\Services\Bots\AuthorFlow;

use App\Services\Indicators\EngulfingDetector;
use App\Support\AuthorFlowConfig;

/**
 * Сценарий D: 3-шаговое поглощение через EngulfingDetector as-is (бот #45 не меняется).
 */
final class EngulfDScenario implements AuthorFlowScenarioInterface
{
    public function __construct(
        private readonly ?EngulfingDetector $detector = null,
    ) {}

    public function setupId(): string
    {
        return 'engulf_D';
    }

    public function basePriority(): int
    {
        return 20;
    }

    public function collect(array $closed, array $context): array
    {
        /** @var array<string,mixed> $config */
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        if (! AuthorFlowConfig::flag($config, 'af_enable_engulf_d', true)) {
            return [];
        }

        $opts = AuthorFlowConfig::engulfDetectorOptions($config);
        if (! $opts['allow_classic'] && ! $opts['allow_same_color']) {
            return [];
        }

        $detector = $this->detector ?? new EngulfingDetector;
        $entries = $detector->detect($closed, $opts);
        $out = [];

        foreach ($entries as $entry) {
            $side = strtoupper((string) ($entry['side'] ?? ''));
            if ($side !== 'CALL' && $side !== 'PUT') {
                continue;
            }
            $kind = (string) ($entry['engulf_kind'] ?? 'classic');
            $features = [
                'engulf_kind' => $kind,
                'step1_time' => (int) ($entry['step1_time'] ?? 0),
                'step2_time' => (int) ($entry['step2_time'] ?? 0),
                'step3_time' => (int) ($entry['step3_time'] ?? 0),
                'reaction_gap_bars' => (int) ($entry['reaction_gap_bars'] ?? 1),
                'reason_ru' => $kind === 'same_color'
                    ? 'Поглощение одного цвета → контр-вход'
                    : 'Классическое 3-шаговое поглощение',
            ];
            if (isset($entry['level_price']) && (float) $entry['level_price'] > 0) {
                $features['level_price'] = (float) $entry['level_price'];
                $features['level_type'] = (string) ($entry['level_type'] ?? '');
                $features['level_time'] = (int) ($entry['level_time'] ?? 0);
            }

            $candidate = [
                'setup' => $this->setupId(),
                'side' => $side,
                'bar_index' => (int) ($entry['bar_index'] ?? -1),
                'time' => (int) ($entry['time'] ?? 0),
                'entry_price' => (float) ($entry['entry_price'] ?? 0),
                'priority' => $this->basePriority(),
                'features' => $features,
            ];
            $candidate['features'] = AuthorFlowScore::enrichFeatures($candidate, $context);
            $candidate['score'] = AuthorFlowScore::compute($candidate, $context, $config);
            $out[] = $candidate;
        }

        return $out;
    }
}
