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
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => false,
      'three_br_enabled' => false,
      'mxwll_enabled' => false,
    ]);

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
    $this->assertContains('^', $texts, 'Ожидали bullish Three Bar Reversal метку (^).');
    $this->assertContains('¡', $texts, 'Ожидали bearish Three Bar Reversal метку (¡).');
    $this->assertContains('three_bar_reversal', $payload['modules']);
    $this->assertNotEmpty($payload['lines'], 'Ожидали уровни Three Bar Reversal.');
    $this->assertNotEmpty($payload['areas'], 'Ожидали заливку зоны Three Bar Reversal.');
  }

  public function test_mxwll_structure_keeps_line_label_pairs(): void
  {
    $rows = [];
    $t = 1_700_300_000;
    $price = 184.5;
    for ($i = 0; $i < 200; $i++) {
      $open = $price;
      $price += sin($i / 7) * 0.08 + ($i % 17 === 0 ? 0.25 : 0);
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => $open,
        'high' => max($open, $price) + 0.05,
        'low' => min($open, $price) - 0.05,
        'close' => $price,
        'volume' => 1.0,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => false,
      'three_br_enabled' => false,
      'mxwll_enabled' => true,
      'mxwll_max_swing_labels' => 20,
      'mxwll_max_structure_events' => 40,
    ]);

    $structureLabels = array_values(array_filter(
      $payload['labels'],
      static fn (array $lb): bool => ($lb['kind'] ?? '') === 'mxwll_structure'
    ));
    $structureLines = array_values(array_filter(
      $payload['lines'],
      static fn (array $ln): bool => ($ln['kind'] ?? '') === 'mxwll_structure'
        || ((($ln['style'] ?? '') === 'Dashed') && ! isset($ln['kind']))
    ));

    $this->assertSame(count($structureLines), count($structureLabels), 'Число линий BoS/CHoCH должно совпадать с числом их меток.');
    $this->assertNotEmpty($structureLabels, 'Ожидали хотя бы одну метку BoS/CHoCH/I-BoS/I-CHoCH.');
    foreach ($structureLabels as $label) {
      $this->assertSame('mxwll_structure', $label['kind'] ?? null);
      $this->assertNotSame('center', $label['position'] ?? null, 'Метка структуры должна быть над/под уровнем, не по центру линии.');
    }
  }

  public function test_mxwll_rolling_levels_emit_4h_lines(): void
  {
    $rows = [];
    $t = 1_700_400_000;
    $price = 100.0;
    for ($i = 0; $i < 300; $i++) {
      $open = $price;
      $price += sin($i / 11) * 0.05;
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => $open,
        'high' => max($open, $price) + 0.2,
        'low' => min($open, $price) - 0.2,
        'close' => $price,
        'volume' => 1.0,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => false,
      'three_br_enabled' => false,
      'mxwll_enabled' => true,
      'mxwll_show_internals' => false,
      'mxwll_show_externals' => false,
      'mxwll_show_4h_levels' => true,
      'mxwll_show_1d_levels' => false,
      'mxwll_show_fibs' => false,
      'mxwll_show_main_leg' => false,
      'sessions_enabled' => false,
    ]);

    $levelLines = array_filter($payload['lines'], fn (array $l): bool => ($l['kind'] ?? '') === 'mxwll_level');
    $this->assertCount(2, $levelLines, 'Ожидали High и Low линии 4H rolling window.');
  }

  public function test_sessions_emit_background_areas(): void
  {
    $rows = [];
    $t = 1_700_400_000;
    for ($i = 0; $i < 120; $i++) {
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => 100.0,
        'high' => 100.5,
        'low' => 99.5,
        'close' => 100.0,
        'volume' => 1.0,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => false,
      'three_br_enabled' => false,
      'mxwll_enabled' => false,
      'sessions_enabled' => true,
    ]);

    $this->assertContains('sessions', $payload['modules']);
    $this->assertNotEmpty($payload['areas']);
  }

  public function test_hull_band_emits_areas_when_signal_enabled(): void
  {
    $rows = [];
    $t = 1_700_400_000;
    $price = 50.0;
    for ($i = 0; $i < 80; $i++) {
      $open = $price;
      $price += 0.1;
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => $open,
        'high' => $price + 0.2,
        'low' => $open - 0.1,
        'close' => $price,
        'volume' => 1.0,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'three_br_enabled' => false,
      'mxwll_enabled' => false,
      'sessions_enabled' => false,
      'hull_enabled' => true,
      'hull_show_band' => true,
      'hull_show_signal_line' => true,
      'hull_length' => 20,
    ]);

    $bands = array_filter($payload['areas'], fn (array $a): bool => ($a['kind'] ?? '') === 'hull_band');
    $this->assertNotEmpty($bands);
  }

  public function test_lux_fvg_detects_bullish_gap(): void
  {
    $rows = [
      ['time' => 1_700_500_000, 'open' => 99.0, 'high' => 100.0, 'low' => 99.0, 'close' => 99.0, 'volume' => 10.0],
      ['time' => 1_700_500_060, 'open' => 100.0, 'high' => 100.5, 'low' => 100.0, 'close' => 100.2, 'volume' => 10.0],
      ['time' => 1_700_500_120, 'open' => 101.0, 'high' => 103.0, 'low' => 101.0, 'close' => 102.0, 'volume' => 10.0],
    ];

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => false,
      'three_br_enabled' => false,
      'mxwll_enabled' => false,
      'sessions_enabled' => false,
      'lux_fvg_enabled' => true,
      'lux_ob_enabled' => false,
      'lux_fvg_threshold_pct' => 0,
      'lux_fvg_dynamic' => false,
    ]);

    $this->assertContains('lux_fvg', $payload['modules']);
    $bullAreas = array_filter($payload['areas'], fn (array $a): bool => str_starts_with((string) ($a['kind'] ?? ''), 'lux_fvg_bull'));
    $this->assertNotEmpty($bullAreas);
  }

  public function test_lux_ob_module_runs_on_synthetic_series(): void
  {
    $rows = [];
    $t = 1_700_600_000;
    for ($i = 0; $i < 80; $i++) {
      $vol = ($i === 40) ? 500.0 : 10.0 + ($i % 5);
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => 100.0 + $i * 0.01,
        'high' => 100.5 + $i * 0.01,
        'low' => 99.5 + $i * 0.01,
        'close' => 100.0 + $i * 0.01,
        'volume' => $vol,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => false,
      'three_br_enabled' => false,
      'mxwll_enabled' => false,
      'sessions_enabled' => false,
      'lux_fvg_enabled' => false,
      'lux_ob_enabled' => true,
      'lux_ob_length' => 5,
    ]);

    $this->assertContains('lux_ob', $payload['modules']);
  }

  public function test_reversal_signals_emits_momentum_and_sr_lines(): void
  {
    $rows = [];
    $t = 1_700_700_000;
    $price = 100.0;
    for ($i = 0; $i < 30; $i++) {
      $price -= 0.2;
      $rows[] = [
        'time' => $t + $i * 60,
        'open' => $price + 0.05,
        'high' => $price + 0.15,
        'low' => $price - 0.1,
        'close' => $price,
        'volume' => 1.0,
      ];
    }

    $service = new SmcFlowIndicatorService;
    $payload = $service->compute($rows, [
      'ut_enabled' => false,
      'hull_enabled' => false,
      'three_br_enabled' => false,
      'mxwll_enabled' => false,
      'sessions_enabled' => false,
      'lux_fvg_enabled' => false,
      'lux_ob_enabled' => false,
      'rs_enabled' => true,
      'rs_sr_levels' => true,
    ]);

    $this->assertContains('reversal_signals', $payload['modules']);
    $momentum = array_filter(
      $payload['labels'],
      static fn (array $lb): bool => str_starts_with((string) ($lb['kind'] ?? ''), 'rs_momentum_')
    );
    $this->assertNotEmpty($momentum, 'Ожидали метку momentum phase 9.');
    $srLines = array_filter(
      $payload['lines'],
      static fn (array $ln): bool => in_array($ln['kind'] ?? '', ['rs_resistance', 'rs_support'], true)
    );
    $this->assertNotEmpty($srLines, 'Ожидали step S/R линии Reversal Signals.');
  }
}
