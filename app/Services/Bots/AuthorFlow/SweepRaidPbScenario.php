<?php

namespace App\Services\Bots\AuthorFlow;

use App\Services\Indicators\SweepRaidLiquidityDetector;
use App\Support\AuthorFlowConfig;

/**
 * Сценарии A/B: чистый sweep fade и raid → первая откатная (pullbackEntries).
 * Детектор SRL вызывается как библиотека — бот sweep_raid_liquidity не меняется.
 */
final class SweepRaidPbScenario implements AuthorFlowScenarioInterface
{
    public function __construct(
        private readonly ?SweepRaidLiquidityDetector $detector = null,
    ) {}

    public function setupId(): string
    {
        // Композитный сценарий отдаёт и sweep, и raid_pb.
        return 'sweep_raid_pb';
    }

    public function basePriority(): int
    {
        return 40;
    }

    public function collect(array $closed, array $context): array
    {
        /** @var array<string,mixed> $config */
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        $enableSweep = AuthorFlowConfig::flag($config, 'af_enable_sweep', true);
        $enableRaidPb = AuthorFlowConfig::flag($config, 'af_enable_raid_pb', true);
        if (! $enableSweep && ! $enableRaidPb) {
            return [];
        }

        $pivot = max(1, min(10, (int) ($config['af_srl_pivot_length'] ?? 2)));
        $detector = $this->detector ?? new SweepRaidLiquidityDetector;
        $events = $detector->detect($closed, $pivot);
        $out = [];

        // Чистый sweep: вход на баре снятия (fade), без ожидания PB.
        if ($enableSweep) {
            foreach ($events as $event) {
                if ((string) ($event['kind'] ?? '') !== 'sweep') {
                    continue;
                }
                $side = strtoupper((string) ($event['side'] ?? ''));
                if ($side !== 'CALL' && $side !== 'PUT') {
                    continue;
                }
                $candidate = [
                    'setup' => 'sweep',
                    'side' => $side,
                    'bar_index' => (int) ($event['bar_index'] ?? -1),
                    'time' => (int) ($event['time'] ?? 0),
                    'entry_price' => (float) ($event['entry_price'] ?? 0),
                    'priority' => 40,
                    'features' => [
                        'takedown_kind' => 'sweep',
                        'level_price' => (float) ($event['level_price'] ?? 0),
                        'level_type' => ((string) ($event['location'] ?? '')) === 'high' ? 'FH' : 'FL',
                        'reason_ru' => 'Свип ликвидности → fade',
                    ],
                ];
                $candidate['features'] = AuthorFlowScore::enrichFeatures($candidate, $context);
                $candidate['score'] = AuthorFlowScore::compute($candidate, $context, $config);
                $out[] = $candidate;
            }
        }

        // Raid → первая откатная свеча (не вход на самом рейде).
        if ($enableRaidPb) {
            $raids = array_values(array_filter(
                $events,
                static fn (array $e): bool => (string) ($e['kind'] ?? '') === 'raid'
            ));
            $pullbacks = $detector->pullbackEntries($closed, $raids);
            foreach ($pullbacks as $pb) {
                $side = strtoupper((string) ($pb['side'] ?? ''));
                if ($side !== 'CALL' && $side !== 'PUT') {
                    continue;
                }
                $candidate = [
                    'setup' => 'raid_pb',
                    'side' => $side,
                    'bar_index' => (int) ($pb['bar_index'] ?? -1),
                    'time' => (int) ($pb['time'] ?? 0),
                    'entry_price' => (float) ($pb['entry_price'] ?? 0),
                    'priority' => 30,
                    'features' => [
                        'takedown_kind' => 'raid',
                        'level_price' => (float) ($pb['level_price'] ?? 0),
                        'level_type' => ((string) ($pb['location'] ?? '')) === 'high' ? 'FH' : 'FL',
                        'step1_time' => (int) ($pb['takedown_time'] ?? 0),
                        'reason_ru' => 'Рейд → первая откатная',
                    ],
                ];
                $candidate['features'] = AuthorFlowScore::enrichFeatures($candidate, $context);
                $candidate['score'] = AuthorFlowScore::compute($candidate, $context, $config);
                $out[] = $candidate;
            }
        }

        return $out;
    }
}
