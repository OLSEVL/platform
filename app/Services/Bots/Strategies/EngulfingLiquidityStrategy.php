<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\EngulfingDetector;

/**
 * Rule-based бот «Поглощение (engulfing)» — 3-шаговая цепочка автора.
 *
 * Вход только на close 3-й свечи (контр-поглощение), не на первом engulf.
 * Экспирация по умолчанию 60 секунд. FH/FL не требуются.
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

            return $this->decision($entry, $lastCandle, $cfg, $context, $partialCandle);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $candle
     * @param  array<string,mixed>  $cfg
     * @param  array<string,mixed>  $context
     */
    private function decision(array $entry, array $candle, array $cfg, array $context, ?array $partialCandle): array
    {
        $side = (string) $entry['side'];
        $engulfKind = (string) ($entry['engulf_kind'] ?? 'classic');
        $expirySec = max(60, min(900, (int) ($cfg['expiry_sec'] ?? 60)));
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;
        $payoutBonus = $payout === null ? 0.0 : min(0.06, max(0.0, $payout - 70.0) / 500.0);
        $baseConfidence = $engulfKind === 'classic' ? 0.65 : 0.60;
        $confidence = min(0.90, round($baseConfidence + $payoutBonus, 4));

        $directionRu = $side === 'CALL' ? 'ПОВЫШЕНИЕ (CALL)' : 'ПОНИЖЕНИЕ (PUT)';
        $kindRu = $engulfKind === 'same_color' ? 'same-color' : 'классическое';

        return [
            'side' => $side,
            'confidence' => $confidence,
            'expiry_sec' => $expirySec,
            'reasons_short' => [
                sprintf('3-шаговое поглощение (%s): engulf → реакция → контр-pogloщение.', $kindRu),
                sprintf('Вход на close 3-й свечи → %s.', $directionRu),
                sprintf('Закрытая M1: O=%.8f, C=%.8f.', (float) $candle['open'], (float) $candle['close']),
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
            'features' => [
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
            ],
            'decision_version' => 'engulfing_liquidity_v1',
        ];
    }
}
