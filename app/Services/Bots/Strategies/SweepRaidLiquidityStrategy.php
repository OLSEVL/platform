<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\SweepRaidLiquidityDetector;
use App\Support\SweepRaidDemarkerFilter;

/**
 * Стратегия «Свип / рейд ликвидности» из разобранного видео.
 *
 * Уровни берутся только от подтверждённых фракталов. На закрытой M1 бот может:
 * 1) войти немедленно после валидного свипа;
 * 2) войти на первой откатной после свипа или рейда.
 * Каждый путь можно независимо выключить в настройках бота.
 *
 * Опционально DeMarker: при включении сделка только если на баре входа
 * осциллятор пересёк зону 0.1 / 0.9 в сторону сигнала.
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
        $demarker = SweepRaidDemarkerFilter::parse($cfg);

        // Пивот требует бары слева/справа, затем нужен хотя бы один бар для сигнала.
        $minBars = $pivotLength * 2 + 2;
        if ($demarker['enabled']) {
            $minBars = max($minBars, $demarker['period'] + 2);
        }
        if (count($closedCandles) < $minBars || (! $enterSweep && ! $enterPullback)) {
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

                $side = (string) $event['side'];
                if (! SweepRaidDemarkerFilter::passes($closedCandles, $side, $cfg)) {
                    continue;
                }

                return $this->decision($event, 'sweep', $lastCandle, $cfg, $context, $partialCandle, $closedCandles);
            }
        }

        if ($enterPullback) {
            $pullbacks = $detector->pullbackEntries($closedCandles, $events);
            foreach (array_reverse($pullbacks) as $entry) {
                if ((int) $entry['bar_index'] !== $lastIndex) {
                    continue;
                }

                $side = (string) $entry['side'];
                if (! SweepRaidDemarkerFilter::passes($closedCandles, $side, $cfg)) {
                    continue;
                }

                return $this->decision($entry, 'pullback', $lastCandle, $cfg, $context, $partialCandle, $closedCandles);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,mixed>  $candle
     * @param  array<string,mixed>  $cfg
     * @param  array<string,mixed>  $context
     * @param  list<array<string,mixed>>  $closedCandles
     */
    private function decision(
        array $event,
        string $entryMode,
        array $candle,
        array $cfg,
        array $context,
        ?array $partialCandle,
        array $closedCandles,
    ): array {
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

        $reasons = [
            sprintf('%s ликвидности %s на уровне %.8f.', ucfirst($kind), $locationRu, (float) $event['level_price']),
            sprintf('%s → %s.', $modeRu, $directionRu),
            sprintf('Закрытая M1: O=%.8f, C=%.8f.', (float) $candle['open'], (float) $candle['close']),
        ];

        $demarkerCross = SweepRaidDemarkerFilter::crossingOnLastBar($closedCandles, $side, $cfg);
        $features = [
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
            'srl_demarker_enabled' => (bool) ($cfg['srl_demarker_enabled'] ?? false),
        ];

        if ($demarkerCross !== null) {
            $zone = $side === 'CALL' ? 'перепроданности' : 'перекупленности';
            $reasons[] = sprintf(
                'DeMarker(%d) вышел из зоны %s: %.1f%% → %.1f%%.',
                $demarkerCross['period'],
                $zone,
                $demarkerCross['previous'] * 100,
                $demarkerCross['current'] * 100,
            );
            $features['srl_demarker_period'] = $demarkerCross['period'];
            $features['srl_demarker_oversold'] = $demarkerCross['oversold'];
            $features['srl_demarker_overbought'] = $demarkerCross['overbought'];
            $features['demarker_previous'] = round($demarkerCross['previous'], 6);
            $features['demarker_current'] = round($demarkerCross['current'], 6);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'expiry_sec' => $expirySec,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'sweep_raid_liquidity: %s_%s; entry=%s; side=%s; level=%.8f; expiry=%ds',
                $kind,
                $location,
                $entryMode,
                $side,
                (float) $event['level_price'],
                $expirySec,
            ),
            'features' => $features,
            'decision_version' => 'sweep_raid_liquidity_v1',
        ];
    }
}
