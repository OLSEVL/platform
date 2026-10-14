<?php

namespace App\Services\Bots\Strategies;

use App\Support\ImpulseLvl1minConfig;
use App\Support\ImpulseLvl1minLiveWick;
use App\Support\ImpulseLvl1minSignalEvaluator;
use App\Support\OsEngineBinaryDecision;

/**
 * Impulse LVL 1min: live M1, профили + MAX, LVL M1–M30; CALL/PUT — bounce или continuation.
 */
class ImpulseLvl1minStrategy implements BotStrategyInterface
{
    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $timing = (string) ($cfg['signal_timing'] ?? 'live');
        $candles = $this->candlesForTiming($closedCandles, $partialCandle, $timing);
        if (count($candles) < ImpulseLvl1minConfig::historyPeriod($cfg) + 1) {
            return null;
        }

        $eval = $candles[array_key_last($candles)];
        $history = array_slice($candles, 0, -1);
        $mtf = is_array($context['mtf'] ?? null) ? $context['mtf'] : [];

        $evalBodyOverride = null;
        $evalDirOverride = null;
        $wickState = null;
        $wickOn = ImpulseLvl1minConfig::flag($cfg, 'live_candle_wick_signals', false)
            && $timing !== 'closed'
            && $partialCandle !== null;
        $botId = (int) ($context['bot_id'] ?? 0);
        $symbol = (string) ($context['instrument'] ?? '');
        if ($wickOn) {
            $stored = ImpulseLvl1minLiveWick::load($botId, $symbol);
            $wickState = ImpulseLvl1minLiveWick::advanceState($stored, $eval);
            $eval = $wickState['candle'];
            $candles[array_key_last($candles)] = $eval;
            $evalBodyOverride = $wickState['measure'];
            $evalDirOverride = $wickState['direction'];
        }

        $liveHits = ImpulseLvl1minSignalEvaluator::findAllSignals(
            $history,
            $eval,
            $cfg,
            $mtf,
            $evalBodyOverride,
            $evalDirOverride,
        );
        if ($wickOn && $wickState !== null) {
            $filtered = ImpulseLvl1minLiveWick::filterHits($liveHits, (float) $wickState['measure'], $cfg, $wickState);
            $liveHits = $filtered['hits'];
            $wickState = $filtered['state'];
            ImpulseLvl1minLiveWick::save($botId, $symbol, $wickState);
        }
        $prevHits = [];
        // Как 1min: закрытую M1 ещё раз как prev. Выкл. в настройках — только текущий бар.
        $evalPrev = ImpulseLvl1minConfig::flag($cfg, 'eval_prev_candle', true);
        if ($evalPrev && $timing !== 'closed' && $partialCandle !== null && count($history) >= ImpulseLvl1minConfig::historyPeriod($cfg) + 1) {
            $prevEval = $history[array_key_last($history)];
            $prevHistory = array_slice($history, 0, -1);
            foreach (ImpulseLvl1minSignalEvaluator::findAllSignals($prevHistory, $prevEval, $cfg, $mtf) as $hit) {
                $hit['is_prev'] = true;
                $prevHits[] = $hit;
            }
        }

        $paper = $liveHits[0] ?? $prevHits[0] ?? null;
        if ($paper === null) {
            return null;
        }

        $decision = $this->packDecision($paper, $partialCandle, $context);
        $extras = [];
        // send_all_bar_alerts: MAX + каждый профиль отдельным алертом. Выкл. — только первый хит (paper).
        if (ImpulseLvl1minConfig::flag($cfg, 'send_all_bar_alerts', true)) {
            foreach (array_slice($liveHits, $liveHits !== [] ? 1 : 0) as $hit) {
                $extras[] = $this->packDecision($hit, $partialCandle, $context);
            }
            foreach ($prevHits as $hit) {
                if ($liveHits === [] && $hit === $paper) {
                    continue;
                }
                $extras[] = $this->packDecision($hit, $partialCandle, $context);
            }
        }
        $decision['features']['extra_alerts'] = $extras;

        return $decision;
    }

    /**
     * @param  list<array<string, mixed>>  $closedCandles
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    private function candlesForTiming(array $closedCandles, ?array $partialCandle, string $timing): array
    {
        if ($timing === 'closed') {
            return $this->normalizeCandles($closedCandles);
        }

        if ($partialCandle !== null) {
            $byTime = [];
            foreach ($this->normalizeCandles($closedCandles) as $candle) {
                $byTime[$candle['time']] = $candle;
            }
            $partial = $this->normalizeCandle($partialCandle);
            if ($partial !== null) {
                $byTime[$partial['time']] = $partial;
            }
            ksort($byTime);

            return array_values($byTime);
        }

        // Бэктест без partial: last closed = момент закрытия live-бара.
        return $this->normalizeCandles($closedCandles);
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function packDecision(array $signal, ?array $partialCandle, array $context): array
    {
        $color = (string) ($signal['color'] ?? 'green');
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        // bounce = против импульса; continuation = по импульсу (старое поведение).
        $side = ImpulseLvl1minConfig::sideFromImpulseColor($color, $cfg);
        $entryMode = ImpulseLvl1minConfig::entrySideMode($cfg);
        $kind = (string) ($signal['kind'] ?? 'profile');
        $value = (float) ($signal['value'] ?? 0);
        $strategyId = (string) ($signal['strategy_id'] ?? '');
        $close = (float) ($signal['close'] ?? 0);
        $labels = is_array($signal['lvl_labels'] ?? null) ? $signal['lvl_labels'] : [];
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        $depth = $kind === 'max'
            ? min(1.0, max(0.0, ($value - 100.0) / 80.0))
            : min(1.0, max(0.0, ($value - 3.0) / 3.0));
        $confidence = OsEngineBinaryDecision::confidence($depth, $payout);

        $valueText = $kind === 'max' ? sprintf('%.0f%%', $value) : sprintf('%.2fx', $value);
        $lvlText = $labels !== [] ? implode(' ', $labels) : '—';
        // Как 1min SIGNAL_OUTPUT_LABELS: кружок профиля + id + N- (MAX без кружка).
        $outputLabel = ImpulseLvl1minConfig::outputLabel($signal + ['strategy_id' => $strategyId]);
        $reasons = [
            trim($outputLabel.' '.$valueText.($labels !== [] ? ' '.implode(' ', $labels) : '')),
            sprintf(
                '%s импульс → вход %s (%s) · LVL %s',
                $color === 'green' ? 'быч.' : 'медв.',
                $side,
                $entryMode === ImpulseLvl1minConfig::ENTRY_SIDE_CONTINUATION ? 'продолжение' : 'отбой',
                $lvlText
            ),
            sprintf('Close=%.8f · mean=%.8f', $close, (float) ($signal['average_body'] ?? 0)),
        ];
        if ($payout !== null) {
            $reasons[] = sprintf('Payout: %.0f%%', $payout);
        }

        $setup = $color === 'green' ? 'impulse_lvl_bull' : 'impulse_lvl_bear';

        $version = ImpulseLvl1minConfig::barPeriodSec($cfg) === ImpulseLvl1minConfig::S15_TIMEFRAME_SEC
            ? ImpulseLvl1minConfig::VERSION_S15
            : ImpulseLvl1minConfig::VERSION;

        return OsEngineBinaryDecision::pack(
            $side,
            $setup,
            $version,
            $confidence,
            $reasons,
            sprintf(
                'impulse_lvl_1min: %s %s %s LVL=%s close=%.8f',
                $outputLabel,
                $setup,
                $valueText,
                $lvlText,
                $close
            ),
            [
                'strategy_id' => $strategyId,
                'profile_emoji' => (string) ($signal['emoji'] ?? ''),
                'profile_label' => (string) ($signal['label'] ?? ''),
                'output_label' => $outputLabel,
                'is_prev' => (bool) ($signal['is_prev'] ?? false),
                'candle_count' => (int) ($signal['candle_count'] ?? 0),
                'signal_timestamp' => (int) ($signal['signal_timestamp'] ?? 0),
                'kind' => $kind,
                'value' => round($value, 4),
                'impulse_color' => $color,
                'lvl_labels' => $labels,
                // Зоны фильтра — для PNG и в bounce, и в continuation (не путать с CALL/PUT).
                'lvl_zones' => is_array($signal['lvl_zones'] ?? null) ? $signal['lvl_zones'] : [],
                'close' => round($close, 8),
                'level_price' => round($close, 8),
                'level_kind' => 'impulse_lvl',
                'level_strength' => round(50.0 + 40.0 * $depth, 2),
                'entry_regime' => $entryMode,
                'partial_present' => $partialCandle !== null,
            ],
            $payout,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    private function normalizeCandles(array $candles): array
    {
        $out = [];
        foreach ($candles as $candle) {
            $normalized = $this->normalizeCandle($candle);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $candle
     * @return array{time:int,open:float,high:float,low:float,close:float}|null
     */
    private function normalizeCandle(array $candle): ?array
    {
        $time = (int) ($candle['time'] ?? 0);
        if ($time <= 0) {
            return null;
        }

        return [
            'time' => $time,
            'open' => (float) ($candle['open'] ?? 0),
            'high' => (float) ($candle['high'] ?? 0),
            'low' => (float) ($candle['low'] ?? 0),
            'close' => (float) ($candle['close'] ?? 0),
        ];
    }
}
