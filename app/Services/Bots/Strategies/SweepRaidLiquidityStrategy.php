<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\SweepRaidLiquidityDetector;
use App\Support\BinaryExpiry;
use App\Support\OtcGapFilter;
use App\Support\SweepRaidDemarkerFilter;

/**
 * Стратегия «Свип / рейд ликвидности» из разобранных видео.
 *
 * Уровни — только от подтверждённых фракталов. На закрытой свече бот может:
 * 1) войти на валидном свипе (fade);
 * 2) войти на первой откатной после свипа/рейда;
 * 3) войти на пробое + закреплении (сценарий E, стрим kAyG3wJDPBU).
 *
 * Fade и breakout — разные режимы от одного FH/FL; включаются тумблерами.
 * Опционально: DeMarker и фильтр OTC-гэпов.
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
        $enterBreakout = (bool) ($cfg['srl_enter_breakout'] ?? false);
        $breakoutCloses = max(1, min(5, (int) ($cfg['srl_breakout_closes'] ?? 2)));
        // Dual-mode автор: на одном баре не мешать fade и пробой — при конфликте берём breakout.
        $exclusiveFadeBreakout = (bool) ($cfg['srl_exclusive_fade_breakout'] ?? false);
        // PB только после настоящего свипа (не raid) — меньше шума из replay K83.
        $pullbackSweepsOnly = (bool) ($cfg['srl_pullback_sweeps_only'] ?? false);
        $demarker = SweepRaidDemarkerFilter::parse($cfg);

        $minBars = $pivotLength * 2 + 2;
        if ($demarker['enabled']) {
            $minBars = max($minBars, $demarker['period'] + 2);
        }
        if (count($closedCandles) < $minBars || (! $enterSweep && ! $enterPullback && ! $enterBreakout)) {
            return null;
        }

        // Грязная OTC-пара с частыми гэпами — пропуск всех путей входа.
        if (OtcGapFilter::shouldSkip($closedCandles, $cfg, 'srl')) {
            return null;
        }

        $detector = $this->detector ?? new SweepRaidLiquidityDetector;
        $events = $detector->detect($closedCandles, $pivotLength);
        $lastIndex = count($closedCandles) - 1;
        $lastCandle = $closedCandles[$lastIndex];

        $breakoutDecision = null;
        if ($enterBreakout) {
            $breakouts = $detector->breakoutEntries($closedCandles, $pivotLength, $breakoutCloses);
            foreach (array_reverse($breakouts) as $entry) {
                if ((int) $entry['bar_index'] !== $lastIndex) {
                    continue;
                }
                $side = (string) $entry['side'];
                if (! SweepRaidDemarkerFilter::passes($closedCandles, $side, $cfg)) {
                    continue;
                }
                $breakoutDecision = $this->decision($entry, 'breakout', $lastCandle, $cfg, $context, $partialCandle, $closedCandles);
                break;
            }
        }

        // Exclusive: если на баре есть закреплённый пробой — не берём fade/PB в ту же свечу.
        if ($breakoutDecision !== null && $exclusiveFadeBreakout) {
            return $breakoutDecision;
        }

        // Свип (fade) — возврат после снятия ликвидности.
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

        // Пробой без exclusive (или когда fade не сработал) — продолжение за уровнем.
        if ($breakoutDecision !== null) {
            return $breakoutDecision;
        }

        // Первая откатная после снятия.
        if ($enterPullback) {
            $pbEvents = $events;
            if ($pullbackSweepsOnly) {
                $pbEvents = array_values(array_filter(
                    $events,
                    static fn (array $e): bool => ($e['kind'] ?? null) === 'sweep'
                ));
            }
            $pullbacks = $detector->pullbackEntries($closedCandles, $pbEvents);
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
        // 15с — OTC S15 как у автора; floor 60 убран после replay K83/kAyG.
        $expirySec = BinaryExpiry::clamp((int) ($cfg['expiry_sec'] ?? 60));
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $confidence = match ($entryMode) {
            'breakout' => 0.64,
            'pullback' => 0.65,
            default => 0.67,
        };
        $confidence = min(0.90, round($confidence, 4));

        $locationRu = $location === 'high' ? 'максимума' : 'минимума';
        $directionRu = $side === 'CALL' ? 'ПОВЫШЕНИЕ (CALL)' : 'ПОНИЖЕНИЕ (PUT)';
        $modeRu = match ($entryMode) {
            'breakout' => 'пробой + закрепление',
            'pullback' => 'первая откатная',
            default => 'вход на свипе',
        };

        $reasons = [
            $entryMode === 'breakout'
                ? sprintf('Пробой уровня %s (%.8f) с закреплением close за ним.', $locationRu, (float) $event['level_price'])
                : sprintf('%s ликвидности %s на уровне %.8f.', ucfirst($kind), $locationRu, (float) $event['level_price']),
            sprintf('%s → %s.', $modeRu, $directionRu),
            sprintf('Закрытая свеча: O=%.8f, C=%.8f.', (float) $candle['open'], (float) $candle['close']),
        ];

        $gapStats = OtcGapFilter::measure($closedCandles, $cfg, 'srl');
        $demarkerCross = SweepRaidDemarkerFilter::crossingOnLastBar($closedCandles, $side, $cfg);
        $features = [
            'setup' => $kind.'_'.$location,
            'entry_mode' => $entryMode,
            'level_price' => (float) $event['level_price'],
            'level_time' => (int) $event['level_time'],
            'event_time' => (int) $event['time'],
            'takedown_time' => (int) ($event['takedown_time'] ?? $event['time']),
            'takedown_price' => (float) ($event['takedown_price'] ?? $event['entry_price']),
            'entry_price' => (float) $event['entry_price'],
            'pivot_length' => (int) ($cfg['srl_pivot_length'] ?? 2),
            'payout' => $payout,
            'partial_present' => $partialCandle !== null,
            'srl_demarker_enabled' => (bool) ($cfg['srl_demarker_enabled'] ?? false),
            'srl_gap_filter_enabled' => (bool) ($cfg['srl_gap_filter_enabled'] ?? false),
            'srl_exclusive_fade_breakout' => (bool) ($cfg['srl_exclusive_fade_breakout'] ?? false),
            'srl_pullback_sweeps_only' => (bool) ($cfg['srl_pullback_sweeps_only'] ?? false),
            'skip_martingale_if_session_green' => (bool) ($cfg['srl_skip_martingale_if_session_green'] ?? false),
        ];

        if ($gapStats !== null) {
            $features['gap_ratio'] = $gapStats['gap_ratio'];
            $features['gap_bars'] = $gapStats['bars'];
        }

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
            'decision_version' => 'sweep_raid_liquidity_v2',
        ];
    }
}
