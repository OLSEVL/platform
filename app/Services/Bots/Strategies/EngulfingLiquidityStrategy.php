<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\EngulfingDetector;
use App\Support\BinaryExpiry;
use App\Support\OtcGapFilter;

/**
 * Rule-based бот «Поглощение (engulfing)» — 3-шаговая цепочка автора.
 *
 * Вход только на close 3-й свечи (контр-поглощение), не на первом engulf.
 * Опционально: фильтр OTC-гэпов (стрим kAyG3wJDPBU).
 */
class EngulfingLiquidityStrategy implements BotStrategyInterface
{
    public function __construct(
        private readonly ?EngulfingDetector $detector = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string,mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $allowClassic = (bool) ($cfg['eng_allow_classic'] ?? true);
        $allowSameColor = (bool) ($cfg['eng_allow_same_color'] ?? true);

        if (count($closedCandles) < 4 || (! $allowClassic && ! $allowSameColor)) {
            return null;
        }

        if (OtcGapFilter::shouldSkip($closedCandles, $cfg, 'eng')) {
            return null;
        }

        $detector = $this->detector ?? new EngulfingDetector;
        $entries = $detector->detect($closedCandles, [
            'allow_classic' => $allowClassic,
            'allow_same_color' => $allowSameColor,
        ]);
        $lastIndex = count($closedCandles) - 1;
        $lastCandle = $closedCandles[$lastIndex];

        foreach (array_reverse($entries) as $entry) {
            if ((int) ($entry['bar_index'] ?? -1) !== $lastIndex) {
                continue;
            }

            return $this->decision($entry, $lastCandle, $cfg, $context, $partialCandle, $closedCandles);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $candle
     * @param  array<string,mixed>  $cfg
     * @param  array<string,mixed>  $context
     * @param  list<array<string,mixed>>  $closedCandles
     */
    private function decision(array $entry, array $candle, array $cfg, array $context, ?array $partialCandle, array $closedCandles): array
    {
        $side = (string) $entry['side'];
        $engulfKind = (string) ($entry['engulf_kind'] ?? 'classic');
        // Как у SRL: OTC S15 → expiry 15с допустим (см. BinaryExpiry / replay стримов).
        $expirySec = BinaryExpiry::clamp((int) ($cfg['expiry_sec'] ?? 60));
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $baseConfidence = $engulfKind === 'classic' ? 0.65 : 0.60;
        $confidence = min(0.90, round($baseConfidence, 4));

        $directionRu = $side === 'CALL' ? 'ПОВЫШЕНИЕ (CALL)' : 'ПОНИЖЕНИЕ (PUT)';
        $kindRu = $engulfKind === 'same_color' ? 'same-color' : 'классическое';
        $gapStats = OtcGapFilter::measure($closedCandles, $cfg, 'eng');

        $features = [
            'setup' => 'engulf_'.$side,
            'entry_mode' => 'counter_engulf',
            'engulf_kind' => $engulfKind,
            'event_time' => (int) ($entry['time'] ?? 0),
            'entry_price' => (float) ($entry['entry_price'] ?? 0),
            'step1_time' => (int) ($entry['step1_time'] ?? 0),
            'step2_time' => (int) ($entry['step2_time'] ?? 0),
            'step3_time' => (int) ($entry['step3_time'] ?? 0),
            'step1_bar_index' => (int) ($entry['step1_bar_index'] ?? 0),
            'step2_bar_index' => (int) ($entry['step2_bar_index'] ?? 0),
            'step3_bar_index' => (int) ($entry['step3_bar_index'] ?? 0),
            'payout' => $payout,
            'partial_present' => $partialCandle !== null,
            'eng_gap_filter_enabled' => (bool) ($cfg['eng_gap_filter_enabled'] ?? false),
            'skip_martingale_if_session_green' => (bool) ($cfg['eng_skip_martingale_if_session_green'] ?? false),
        ];
        if ($gapStats !== null) {
            $features['gap_ratio'] = $gapStats['gap_ratio'];
            $features['gap_bars'] = $gapStats['bars'];
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'expiry_sec' => $expirySec,
            'reasons_short' => [
                sprintf('3-шаговое поглощение (%s): engulf → реакция → контр-поглощение.', $kindRu),
                sprintf('Вход на close 3-й свечи → %s.', $directionRu),
                sprintf('Закрытая свеча: O=%.8f, C=%.8f.', (float) $candle['open'], (float) $candle['close']),
            ],
            'analysis_text' => sprintf(
                'engulfing_liquidity: %s; side=%s; expiry=%ds; step1=%d step2=%d step3=%d',
                $engulfKind,
                $side,
                $expirySec,
                (int) ($entry['step1_time'] ?? 0),
                (int) ($entry['step2_time'] ?? 0),
                (int) ($entry['step3_time'] ?? 0),
            ),
            'features' => $features,
            'decision_version' => 'engulfing_liquidity_v2',
        ];
    }
}
