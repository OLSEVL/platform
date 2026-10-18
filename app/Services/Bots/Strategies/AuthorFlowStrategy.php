<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\AuthorFlow\AuthorFlowContextBuilder;
use App\Services\Bots\AuthorFlow\AuthorFlowContextBuilderInterface;
use App\Services\Bots\AuthorFlow\AuthorFlowRanker;
use App\Services\Bots\AuthorFlow\AuthorFlowRankerInterface;
use App\Services\Bots\AuthorFlow\AuthorFlowScenarioInterface;
use App\Services\Bots\AuthorFlow\BreakoutEScenario;
use App\Services\Bots\AuthorFlow\EngulfDScenario;
use App\Services\Bots\AuthorFlow\SkopFeatures;
use App\Services\Bots\AuthorFlow\SweepRaidPbScenario;
use App\Support\AuthorFlowConfig;
use App\Support\BinaryExpiry;
use App\Support\OtcGapFilter;

/**
 * AuthorFlow: один сигнал на бар из пула D/E/sweep/raid_pb с приоритетами автора.
 * Детекторы #45 и SRL вызываются как библиотеки — их боты не затрагиваются.
 */
class AuthorFlowStrategy implements BotStrategyInterface
{
    /** @param  list<AuthorFlowScenarioInterface>|null  $scenarios */
    public function __construct(
        private readonly ?AuthorFlowContextBuilderInterface $contextBuilder = null,
        private readonly ?AuthorFlowRankerInterface $ranker = null,
        private readonly ?array $scenarios = null,
        private readonly ?SkopFeatures $skop = null,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string,mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        if (count($closedCandles) < 10) {
            return null;
        }

        // Гэп-фильтр OTC: кандидат не создаётся (штраф −0.15 в плане = skip).
        if (OtcGapFilter::shouldSkip($closedCandles, $cfg, 'af')) {
            return null;
        }

        $builder = $this->contextBuilder ?? new AuthorFlowContextBuilder;
        $afContext = $builder->build($closedCandles, $cfg);
        $afContext['config'] = $cfg;
        $afContext['last_bar_index'] = count($closedCandles) - 1;

        $scenarios = $this->scenarios ?? [
            new BreakoutEScenario,
            new EngulfDScenario,
            new SweepRaidPbScenario,
        ];

        $candidates = [];
        foreach ($scenarios as $scenario) {
            if (! $scenario instanceof AuthorFlowScenarioInterface) {
                continue;
            }
            foreach ($scenario->collect($closedCandles, $afContext) as $c) {
                $candidates[] = $c;
            }
        }

        $skop = $this->skop ?? new SkopFeatures;
        $candidates = $skop->apply($candidates, $afContext, $cfg);

        $ranker = $this->ranker ?? new AuthorFlowRanker;
        $picked = $ranker->pick($candidates, $afContext, $cfg);
        if ($picked === null) {
            return null;
        }

        $score = (float) ($picked['score'] ?? 0);
        $minConf = (float) ($cfg['min_confidence'] ?? 0.55);
        if ($score < $minConf) {
            return null;
        }

        return $this->decision($picked, $closedCandles, $cfg, $context, $partialCandle, $afContext);
    }

    /**
     * @param  array<string,mixed>  $picked
     * @param  list<array<string,mixed>>  $closedCandles
     * @param  array<string,mixed>  $cfg
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $afContext
     */
    private function decision(
        array $picked,
        array $closedCandles,
        array $cfg,
        array $context,
        ?array $partialCandle,
        array $afContext,
    ): array {
        $side = strtoupper((string) ($picked['side'] ?? ''));
        $setup = (string) ($picked['setup'] ?? '');
        $expirySec = BinaryExpiry::clamp((int) ($cfg['expiry_sec'] ?? 15));
        $score = (float) ($picked['score'] ?? 0);
        $features = is_array($picked['features'] ?? null) ? $picked['features'] : [];
        $features['setup'] = $setup;
        $features['priority'] = (int) ($picked['priority'] ?? 0);
        $features['score'] = $score;
        $features['event_time'] = (int) ($picked['time'] ?? 0);
        $features['entry_price'] = (float) ($picked['entry_price'] ?? 0);
        $features['structure_bias'] = (string) ($afContext['structure_bias'] ?? 'range');
        // Флаги для Telegram PNG (если сценарий не проставил — считаем здесь).
        if (! array_key_exists('structure_aligned', $features)) {
            $features['structure_aligned'] = ($features['structure_bias'] === 'up' && $side === 'CALL')
                || ($features['structure_bias'] === 'down' && $side === 'PUT');
        }
        $features['open_liquidity_call'] = ! empty($afContext['open_liquidity_call']);
        $features['open_liquidity_put'] = ! empty($afContext['open_liquidity_put']);
        if (! array_key_exists('open_liquidity', $features)) {
            $features['open_liquidity'] = ($side === 'CALL' && $features['open_liquidity_call'])
                || ($side === 'PUT' && $features['open_liquidity_put']);
        }
        // Цены пулов ликвидности (последние swing) — линии на Telegram PNG.
        if (isset($afContext['last_swing_high']) && (float) $afContext['last_swing_high'] > 0) {
            $features['liq_high'] = (float) $afContext['last_swing_high'];
        }
        if (isset($afContext['last_swing_low']) && (float) $afContext['last_swing_low'] > 0) {
            $features['liq_low'] = (float) $afContext['last_swing_low'];
        }
        $features['partial_present'] = $partialCandle !== null;
        if (isset($context['payout'])) {
            $features['payout'] = (float) $context['payout'];
        }

        $reasonRu = (string) ($features['reason_ru'] ?? $setup);
        $directionRu = $side === 'CALL' ? 'ПОВЫШЕНИЕ' : 'ПОНИЖЕНИЕ';
        $last = $closedCandles[count($closedCandles) - 1];
        $biasRu = match ($features['structure_bias']) {
            'up' => 'рост',
            'down' => 'падение',
            default => 'флэт',
        };

        return [
            'side' => $side,
            'confidence' => $score,
            'expiry_sec' => $expirySec,
            'reasons_short' => [
                sprintf('%s: %s', $setup, $reasonRu),
                sprintf('Вход на close → %s (оценка=%.2f, приоритет=%d).', $directionRu, $score, (int) $features['priority']),
                sprintf('Структура: %s · закрытая: O=%.8f C=%.8f.', $biasRu, (float) $last['open'], (float) $last['close']),
            ],
            'analysis_text' => sprintf(
                'author_flow_v1: setup=%s side=%s score=%.3f expiry=%ds bias=%s',
                $setup,
                $side,
                $score,
                $expirySec,
                $features['structure_bias'],
            ),
            'features' => $features,
            'decision_version' => AuthorFlowConfig::VERSION,
        ];
    }
}
