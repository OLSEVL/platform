<?php

namespace Tests\Unit;

use App\Services\Indicators\SmcFlowIndicatorService;
use PHPUnit\Framework\TestCase;

class SmcFlowIndicatorServiceTest extends TestCase
{
  public function test_ut_bot_emits_buy_label_on_upward_cross(): void
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
      $high = max($open, $close) + 0.2;
      $low = min($open, $close) - 0.2;
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => $open,
        'high' => $high,
        'low' => $low,
        'close' => $close,
        'volume' => 1.0,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_key_value' => 2,
      'ut_atr_period' => 6,
      'keep_recent_only' => false,
      'show_ut_trailing_stop' => false,
    ]);

    $buy = array_values(array_filter(
      $payload['labels'],
      static fn (array $lb): bool => ($lb['text'] ?? '') === 'Buy'
    ));

    $this->assertNotEmpty($buy, 'Ожидали хотя бы одну метку Buy на восходящем участке.');
    $this->assertContains('ut_bot', $payload['modules']);
  }

  public function test_disabled_module_returns_empty_overlay(): void
  {
    $rows = [
      ['time' => 1, 'open' => 1, 'high' => 2, 'low' => 1, 'close' => 1.5, 'volume' => 1],
      ['time' => 2, 'open' => 1.5, 'high' => 2, 'low' => 1, 'close' => 1.8, 'volume' => 1],
    ];

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, ['ut_enabled' => false]);

    $this->assertSame([], $payload['labels']);
    $this->assertSame([], $payload['lines']);
    $this->assertSame([], $payload['modules']);
  }

  public function test_hull_module_emits_lines_when_enabled_and_ut_disabled(): void
  {
    $rows = [];
    $t = 1_700_100_000;
    $price = 100.0;
    for ($i = 0; $i < 180; $i++) {
      $open = $price;
      $price += ($i % 9 < 5) ? 0.35 : -0.18;
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => $open,
        'high' => max($open, $price) + 0.25,
        'low' => min($open, $price) - 0.25,
        'close' => $price,
        'volume' => 1.0,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => true,
      'hull_length' => 55,
      'hull_mode' => 'Hma',
      'hull_show_signal_line' => true,
    ]);

    $this->assertContains('hull_suite', $payload['modules']);
    $this->assertNotEmpty($payload['lines'], 'Ожидали сегменты Hull-линий.');
  }

  public function test_three_bar_reversal_emits_buy_and_sell_labels(): void
  {
    $rows = [
      // Bullish 3BR setup (bars 0..2)
      ['time' => 1, 'open' => 10.0, 'high' => 10.2, 'low' => 9.4, 'close' => 9.6, 'volume' => 100],
      ['time' => 2, 'open' => 9.6, 'high' => 10.0, 'low' => 9.0, 'close' => 9.2, 'volume' => 110],
      ['time' => 3, 'open' => 9.1, 'high' => 10.4, 'low' => 9.0, 'close' => 10.3, 'volume' => 120],
      // Bearish 3BR setup (bars 3..5)
      ['time' => 4, 'open' => 10.1, 'high' => 10.8, 'low' => 10.0, 'close' => 10.7, 'volume' => 115],
      ['time' => 5, 'open' => 10.7, 'high' => 11.1, 'low' => 10.2, 'close' => 10.9, 'volume' => 130],
      ['time' => 6, 'open' => 10.8, 'high' => 10.9, 'low' => 9.8, 'close' => 9.9, 'volume' => 140],
    ];

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => false,
      'three_br_enabled' => true,
      'three_br_type' => 'All',
      'three_br_trend_type' => 'None',
      'three_br_show_levels' => true,
    ]);

    $texts = array_map(static fn (array $lb): string => (string) ($lb['text'] ?? ''), $payload['labels']);
    $this->assertContains('3BR Buy', $texts, 'Ожидали bullish Three Bar Reversal метку.');
    $this->assertContains('3BR Sell', $texts, 'Ожидали bearish Three Bar Reversal метку.');
    $this->assertContains('three_bar_reversal', $payload['modules']);
    $this->assertNotEmpty($payload['lines'], 'Ожидали уровни Three Bar Reversal.');
    $this->assertNotEmpty($payload['areas'], 'Ожидали заливку зоны Three Bar Reversal.');
  }
}
