<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\SweepRaidLiquidityDetector;

/**
 * Стратегия «Свип / рейд ликвидности» из разобранного видео.
 *
 * Уровни берутся только от подтверждённых фракталов. На закрытой M1 бот может:
 * 1) войти немедленно после валидного свипа;
 * 2) войти на первой откатной после свипа или рейда.
 * Каждый путь можно независимо выключить в настройках бота.
 */
class SweepRaidLiquidityStrategy implements BotStrategyInterface
{
    public function __construct(
        private readonly ?SweepRaidLiquidityDetector $detector = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string,mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $pivotLength = max(1, min(100, (int) ($cfg['srl_pivot_length'] ?? 2)));
        $enterSweep = (bool) ($cfg['srl_enter_sweep'] ?? true);
        $enterPullback = (bool) ($cfg['srl_enter_pullback'] ?? true);

        // Пивот требует бары слева/справа, затем нужен хотя бы один бар для сигнала.
        if (count($closedCandles) < $pivotLength * 2 + 2 || (! $enterSweep && ! $enterPullback)) {
            return null;
        }

        $detector = $this->detector ?? new SweepRaidLiquidityDetector;
        $events = $detector->detect($closedCandles, $pivotLength);
        $lastIndex = count($closedCandles) - 1;
        $lastCandle = $closedCandles[$lastIndex];

        // Вход на свипе имеет приоритет: это прямое правило автора и самый свежий контекст.
        if ($enterSweep) {
            foreach (array_reverse($events) as $event) {
                if ((int) $event['bar_index'] !== $lastIndex || ($event['kind'] ?? null) !== 'sweep') {
                    continue;
                }

                return $this->decision($event, 'sweep', $lastCandle, $cfg, $context, $partialCandle);
            }
        }

        if ($enterPullback) {
            $pullbacks = $detector->pullbackEntries($closedCandles, $events);
            foreach (array_reverse($pullbacks) as $entry) {
                if ((int) $entry['bar_index'] !== $lastIndex) {
                    continue;
                }

                return $this->decision($entry, 'pullback', $lastCandle, $cfg, $context, $partialCandle);
            }
        }

        return null;
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $candle @param array<string,mixed> $cfg @param array<string,mixed> $context */
    private function decision(array $event, string $entryMode, array $candle, array $cfg, array $context, ?array $partialCandle): array
    {
        $side = (string) $event['side'];
        $kind = (string) $event['kind'];
        $location = (string) $event['location'];
        $expirySec = max(60, min(900, (int) ($cfg['expiry_sec'] ?? 60)));
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutBonus = $payout === null ? 0.0 : min(0.06, max(0.0, $payout - 70.0) / 500.0);
        $confidence = min(0.90, round(($kind === 'sweep' ? 0.67 : 0.62) + ($entryMode === 'pullback' ? 0.03 : 0.0) + $payoutBonus, 4));

        $locationRu = $location === 'high' ? 'максимума' : 'минимума';
        $directionRu = $side === 'CALL' ? 'ПОВЫШЕНИЕ (CALL)' : 'ПОНИЖЕНИЕ (PUT)';
        $modeRu = $entryMode === 'sweep' ? 'вход на свипе' : 'первая откатная';

        return [
            'side' => $side,
            'confidence' => $confidence,
            'expiry_sec' => $expirySec,
            'reasons_short' => [
                sprintf('%s ликвидности %s на уровне %.8f.', ucfirst($kind), $locationRu, (float) $event['level_price']),
                sprintf('%s → %s.', $modeRu, $directionRu),
                sprintf('Закрытая M1: O=%.8f, C=%.8f.', (float) $candle['open'], (float) $candle['close']),
            ],
            'analysis_text' => sprintf(
                'sweep_raid_liquidity: %s_%s; entry=%s; side=%s; level=%.8f; expiry=%ds',
                $kind,
                $location,
                $entryMode,
                $side,
                (float) $event['level_price'],
                $expirySec,
            ),
            'features' => [
                'setup' => $kind.'_'.$location,
                'entry_mode' => $entryMode,
                'level_price' => (float) $event['level_price'],
                'level_time' => (int) $event['level_time'],
                'event_time' => (int) $event['time'],
                // Для входа на откате event_time — свеча PB; takedown_time — сам свип/рейд.
                'takedown_time' => (int) ($event['takedown_time'] ?? $event['time']),
                'takedown_price' => (float) ($event['takedown_price'] ?? $event['entry_price']),
                'entry_price' => (float) $event['entry_price'],
                'pivot_length' => (int) ($cfg['srl_pivot_length'] ?? 2),
                'payout' => $payout,
                'partial_present' => $partialCandle !== null,
            ],
            'decision_version' => 'sweep_raid_liquidity_v1',
        ];
    }
}
