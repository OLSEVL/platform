<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\SmcFlowStrategy;
use App\Services\Indicators\SmcFlowBotSignalAnalyzer;
use App\Services\Indicators\SmcFlowIndicatorService;
use PHPUnit\Framework\TestCase;

class SmcFlowStrategyTest extends TestCase
{
  public function test_returns_null_when_filters_not_met(): void
  {
    $strategy = new SmcFlowStrategy(new SmcFlowBotSignalAnalyzer);
    $rows = [];
    $price = 100.0;
    for ($i = 0; $i < 80; $i++) {
      $open = $price;
      $price += 0.01;
      $rows[] = [
        'time' => 1_700_100_000 + $i * 60,
        'open' => $open,
        'high' => $open + 0.1,
        'low' => $open - 0.1,
        'close' => $price,
        'volume' => 5.0,
      ];
    }

    $decision = $strategy->analyze($rows, null, [
      'config' => [
        'expiry_sec' => 180,
        'smc_three_br_confirm_max_bars' => 5,
      ],
      'smc_params' => SmcFlowIndicatorService::defaultParams(),
      'payout' => 85.0,
    ]);

    $this->assertNull($decision);
  }

  public function test_returns_call_when_all_modules_align(): void
  {
    $analyzer = $this->createMock(SmcFlowBotSignalAnalyzer::class);
    $context = [
      'ut' => ['side' => 'CALL', 'fresh' => true, 'bar_index' => 99, 'bar_time' => 999],
      'three_br' => ['bull_confirm_bar' => 98, 'bear_confirm_bar' => null],
      'ob' => [
        'price' => 100.5,
        'bull_zones' => [['top' => 101.0, 'btm' => 100.0, 'left_time' => 500]],
        'bear_zones' => [],
      ],
      'last_bar_index' => 99,
    ];
    $analyzer->method('analyze')->willReturn($context);
    $analyzer->method('diagnoseEntry')->willReturn([
      'matches' => true,
      'ut_ok' => true,
      'three_br_ok' => true,
      'ob_ok' => true,
      'three_br_bars_ago' => 1,
      'three_br_source' => 'confirm',
    ]);

    $strategy = new SmcFlowStrategy($analyzer);
    $rows = array_fill(0, 100, [
      'time' => 1,
      'open' => 1.0,
      'high' => 1.1,
      'low' => 0.9,
      'close' => 1.0,
      'volume' => 1.0,
    ]);

    $decision = $strategy->analyze($rows, null, [
      'config' => [
        'expiry_sec' => 180,
        'smc_three_br_confirm_max_bars' => 5,
        'smc_require_ob' => true,
        'smc_three_br_allow_pattern' => true,
      ],
      'smc_params' => SmcFlowIndicatorService::defaultParams(),
      'payout' => 90.0,
    ]);

    $this->assertIsArray($decision);
    $this->assertSame('CALL', $decision['side'] ?? null);
    $this->assertSame(180, $decision['expiry_sec'] ?? null);
    $this->assertGreaterThanOrEqual(0.56, (float) ($decision['confidence'] ?? 0));
  }
}
