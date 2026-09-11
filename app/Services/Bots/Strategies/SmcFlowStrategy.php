<?php

namespace App\Services\Bots\Strategies;

use App\Services\Indicators\SmcFlowBotSignalAnalyzer;
use App\Services\Indicators\SmcFlowIndicatorService;

/**
 * SMC Flow bot: UT flip (триггер) + 3BR confirm + LuxAlgo OB filter.
 * Экспирация по умолчанию 3 минуты (180 с) из config бота.
 */
class SmcFlowStrategy implements BotStrategyInterface
{
  public function __construct(
    private readonly SmcFlowBotSignalAnalyzer $signalAnalyzer,
  ) {}

  public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
  {
    if (count($closedCandles) < 60) {
      return null;
    }

    /** @var array<string, mixed> $cfg */
    $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
    $confirmMaxBars = max(1, min(120, (int) ($cfg['smc_three_br_confirm_max_bars'] ?? 30)));
    $expirySec = max(120, min(600, (int) ($cfg['expiry_sec'] ?? 180)));
    $entryOptions = [
      'smc_require_ob' => (bool) ($cfg['smc_require_ob'] ?? true),
      'smc_three_br_allow_pattern' => (bool) ($cfg['smc_three_br_allow_pattern'] ?? true),
      'smc_ob_touch_max_bars' => max(0, min(12, (int) ($cfg['smc_ob_touch_max_bars'] ?? 3))),
    ];

    $smcParams = is_array($context['smc_params'] ?? null)
      ? $context['smc_params']
      : SmcFlowIndicatorService::defaultParams();

    // Для бота используем только нужные модули (остальное можно выключить в настройках индикатора).
    $smcParams['ut_enabled'] = true;
    $smcParams['three_br_enabled'] = true;
    $smcParams['lux_ob_enabled'] = true;

    $signalContext = $this->signalAnalyzer->analyze($closedCandles, $smcParams);
    $utSide = $signalContext['ut']['side'] ?? null;
    if (! is_string($utSide) || ! in_array($utSide, ['CALL', 'PUT'], true)) {
      return null;
    }

    $diagnosis = $this->signalAnalyzer->diagnoseEntry($signalContext, $utSide, $confirmMaxBars, $entryOptions);
    if (! $diagnosis['matches']) {
      return null;
    }

    $payout = isset($context['payout']) ? (float) $context['payout'] : null;
    $confidence = $this->scoreConfidence($signalContext, $utSide, $confirmMaxBars, $diagnosis);

    $reasons = $this->buildReasons($signalContext, $utSide, $confirmMaxBars, $diagnosis);

    return [
      'side' => $utSide,
      'confidence' => $confidence,
      'expiry_sec' => $expirySec,
      'reasons_short' => $reasons,
      'analysis_text' => sprintf(
        'smc_flow: ut=%s; 3br_confirm=%s; ob_zone=%s; confidence=%.2f; expiry=%ds',
        strtolower($utSide),
        $utSide === 'CALL'
          ? (string) ($signalContext['three_br']['bull_confirm_time'] ?? '')
          : (string) ($signalContext['three_br']['bear_confirm_time'] ?? ''),
        'yes',
        $confidence,
        $expirySec
      ),
      'features' => [
        'signal_context' => $signalContext,
        'confirm_max_bars' => $confirmMaxBars,
        'entry_options' => $entryOptions,
        'entry_diagnosis' => $diagnosis,
        'payout' => $payout,
        'partial_present' => $partialCandle !== null,
      ],
      'decision_version' => 'smc_flow_ut_3br_ob_v1',
    ];
  }

  /**
   * @param  array<string, mixed>  $signalContext
   * @return list<string>
   */
  private function buildReasons(array $signalContext, string $side, int $confirmMaxBars, array $diagnosis): array
  {
    $reasons = [
      $side === 'CALL'
        ? 'UT Bot: свежий Buy на последней закрытой свече.'
        : 'UT Bot: свежий Sell на последней закрытой свече.',
    ];

    $barsAgo = $diagnosis['three_br_bars_ago'] ?? null;
    $source = $diagnosis['three_br_source'] ?? 'confirm';
    $reasons[] = sprintf(
      '3BR: %s %s %d бар(ов) назад (окно %d).',
      $side === 'CALL' ? 'bullish' : 'bearish',
      $source === 'pattern' ? 'pattern' : 'confirm',
      $barsAgo ?? -1,
      $confirmMaxBars
    );

    if ($diagnosis['ob_ok'] ?? false) {
      $reasons[] = 'LuxAlgo OB: цена в активной зоне '.($side === 'CALL' ? 'bull' : 'bear').'.';
    } else {
      $reasons[] = 'LuxAlgo OB: фильтр отключён.';
    }

    return $reasons;
  }

  /**
   * @param  array<string, mixed>  $signalContext
   */
  private function scoreConfidence(array $signalContext, string $side, int $confirmMaxBars, array $diagnosis): float
  {
    $confidence = 0.58;
    $barsAgo = $diagnosis['three_br_bars_ago'] ?? null;
    $source = $diagnosis['three_br_source'] ?? 'confirm';

    if ($barsAgo !== null) {
      if ($barsAgo === 0) {
        $confidence += $source === 'confirm' ? 0.08 : 0.05;
      } elseif ($barsAgo <= 2) {
        $confidence += $source === 'confirm' ? 0.05 : 0.03;
      } elseif ($barsAgo <= $confirmMaxBars) {
        $confidence += 0.02;
      }
    }

    return round(min(0.88, max(0.56, $confidence)), 4);
  }
}
