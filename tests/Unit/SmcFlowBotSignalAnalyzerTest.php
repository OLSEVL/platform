<?php

namespace Tests\Unit;

use App\Services\Indicators\SmcFlowBotSignalAnalyzer;
use App\Services\Indicators\SmcFlowIndicatorService;
use PHPUnit\Framework\TestCase;

class SmcFlowBotSignalAnalyzerTest extends TestCase
{
  public function test_matches_entry_requires_ut_three_br_and_ob(): void
  {
    $analyzer = new SmcFlowBotSignalAnalyzer;
    $context = [
      'ut' => ['side' => 'CALL', 'fresh' => true, 'bar_index' => 10, 'bar_time' => 1000],
      'three_br' => ['bull_confirm_bar' => 9, 'bear_confirm_bar' => null],
      'ob' => [
        'price' => 100.5,
        'bull_zones' => [['top' => 101.0, 'btm' => 100.0, 'left_time' => 500]],
        'bear_zones' => [],
      ],
      'last_bar_index' => 10,
    ];

    $this->assertTrue($analyzer->matchesEntry($context, 'CALL', 5));
    $this->assertFalse($analyzer->matchesEntry($context, 'PUT', 5));
    $this->assertFalse($analyzer->matchesEntry($context, 'CALL', 0));
  }

  public function test_price_in_ob_zone_checks_close_against_active_zones(): void
  {
    $analyzer = new SmcFlowBotSignalAnalyzer;
    $context = [
      'ob' => [
        'price' => 100.5,
        'bull_zones' => [['top' => 101.0, 'btm' => 100.0, 'left_time' => 500]],
        'bear_zones' => [['top' => 99.5, 'btm' => 98.5, 'left_time' => 600]],
      ],
    ];

    $this->assertTrue($analyzer->priceInObZone($context, 'CALL'));
    $this->assertFalse($analyzer->priceInObZone($context, 'PUT'));
  }

  public function test_ut_flip_matches_indicator_crossover_logic(): void
  {
    $rows = [];
    $t = 1_700_000_000;
    $close = 100.0;
    for ($i = 0; $i < 40; $i++) {
      $open = $close;
      if ($i < 20) {
        $close -= 0.4;
      } elseif ($i < 28) {
        $close += 0.05;
      } else {
        $close += 1.2;
      }
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => $open,
        'high' => max($open, $close) + 0.2,
        'low' => min($open, $close) - 0.2,
        'close' => $close,
        'volume' => 1.0,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $overlay = $service->compute($rows, [
      'ut_key_value' => 2,
      'ut_atr_period' => 6,
      'keep_recent_only' => false,
      'show_ut_trailing_stop' => false,
    ]);
    $lastBuy = null;
    foreach ($overlay['labels'] as $label) {
      if (($label['text'] ?? '') === 'Buy') {
        $lastBuy = (int) $label['time'];
      }
    }

    $analyzer = new SmcFlowBotSignalAnalyzer;
    $ctx = $analyzer->analyze($rows, [
      'ut_enabled' => true,
      'ut_key_value' => 2,
      'ut_atr_period' => 6,
      'three_br_enabled' => false,
      'lux_ob_enabled' => false,
    ]);

    $this->assertNotNull($lastBuy);
    $lastBarTime = (int) $rows[array_key_last($rows)]['time'];
    if ($lastBuy === $lastBarTime) {
      $this->assertTrue($ctx['ut']['fresh'] ?? false);
      $this->assertSame('CALL', $ctx['ut']['side'] ?? null);
      $this->assertSame($lastBuy, $ctx['ut']['bar_time'] ?? null);
    } else {
      // Бот смотрит только последний бар: если Buy был раньше — fresh=false, это ожидаемо.
      $this->assertFalse($ctx['ut']['fresh'] ?? true);
    }
  }

  public function test_analyze_returns_structured_context_on_enough_bars(): void
  {
    $rows = [];
    for ($i = 0; $i < 30; $i++) {
      $rows[] = [
        'time' => 1_700_000_000 + $i * 60,
        'open' => 100.0,
        'high' => 100.5,
        'low' => 99.5,
        'close' => 100.0 + ($i * 0.01),
        'volume' => 10.0,
      ];
    }

    $analyzer = new SmcFlowBotSignalAnalyzer;
    $ctx = $analyzer->analyze($rows, SmcFlowIndicatorService::defaultParams());

    $this->assertArrayHasKey('ut', $ctx);
    $this->assertArrayHasKey('three_br', $ctx);
    $this->assertArrayHasKey('ob', $ctx);
    $this->assertSame(29, $ctx['last_bar_index']);
  }
}
