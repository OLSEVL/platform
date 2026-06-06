<?php

namespace App\Services\Indicators;

/**
 * Серверный порт композитного Pine-индикатора SMC Flow (3.txt / AETHER FLOW).
 *
 * Сейчас реализован первый модуль — UT Bot (ATR-трейлинг и метки Buy/Sell).
 * Остальные блоки (структура Mxwll, Hull, FVG LuxAlgo и т.д.) подключаются отдельными
 * методами в том же сервисе по мере переноса.
 */
class SmcFlowIndicatorService
{
  public const ID = 'tv.smc_flow';

  /** @var array<string, mixed> */
  public const DEFAULT_PARAMS = [
    'ut_enabled' => true,
    'ut_key_value' => 2,
    'ut_atr_period' => 6,
    /** Heikin Ashi в Pine; на сервере пока не считаем — флаг зарезервирован под будущий модуль. */
    'ut_use_heikin_ashi' => false,
    'show_ut_labels' => true,
    'keep_recent_only' => true,
    'max_ut_labels' => 3,
    'color_ut_buy' => '#22c55e',
    'color_ut_sell' => '#ef4444',
    'show_ut_trailing_stop' => true,
    'color_ut_trail_bull' => '#22c55e',
    'color_ut_trail_bear' => '#ef4444',
    // Ограничение длины трейла для снижения нагрузки на DOM-оверлей при зуме/скролле.
    'ut_max_trail_segments' => 1200,
    // Hull Suite (этап 2 переноса): цветной трендовый контур + опциональная сигнальная линия.
    'hull_enabled' => true,
    'hull_mode' => 'Hma', // Hma | Thma | Ehma
    'hull_length' => 55,
    'hull_length_multiplier' => 1.0,
    'hull_switch_color' => true,
    'hull_show_signal_line' => true,
    'hull_line_width' => 2,
    'hull_signal_line_width' => 1,
    'hull_max_segments' => 1200,
    'color_hull_up' => '#00ff00',
    'color_hull_down' => '#ff0000',
    'color_hull_neutral' => '#ff9800',
    // Three Bar Reversal (этап 3): разворотные паттерны с опциональной фильтрацией тренда.
    'three_br_enabled' => true,
    'three_br_type' => 'All', // Normal | Enhanced | All
    'three_br_trend_type' => 'None', // Moving Average Cloud | None
    'three_br_trend_filter' => 'Aligned', // Aligned | Opposite
    'three_br_ma_type' => 'HMA', // SMA | EMA | HMA | RMA | WMA | VWMA
    'three_br_ma_fast_length' => 50,
    'three_br_ma_slow_length' => 200,
    'three_br_show_levels' => true,
    /** Level | Zone | None — brpSR в Pine: зона поддержки/сопротивления после подтверждения. */
    'three_br_sr_mode' => 'Level',
    'three_br_st_atr_period' => 10,
    'three_br_st_factor' => 3.0,
    'three_br_dc_length' => 13,
    'three_br_max_patterns' => 120,
    'color_three_br_bull' => '#2962ff',
    'color_three_br_bear' => '#ff9800',
    // Reversal Signals (упрощённый блок из 3.txt): momentum 9 + S/R step lines.
    'rs_enabled' => true,
    'rs_sr_levels' => true,
    'rs_max_momentum_markers' => 80,
    'rs_max_sr_segments' => 200,
    'color_rs_bull' => '#089981',
    'color_rs_bear' => '#f23645',
    // Mxwll Suite (этап 4): swing labels + internal/external BoS/CHoCH.
    'mxwll_enabled' => true,
    'mxwll_show_internals' => true,
    'mxwll_int_sensitivity' => 5, // 3 | 5 | 8
    'mxwll_int_structure' => 'All', // All | BoS | CHoCH
    'mxwll_show_externals' => true,
    'mxwll_ext_sensitivity' => 25, // 10 | 25 | 50
    'mxwll_ext_structure' => 'All',
    'mxwll_show_hh_lh' => true,
    'mxwll_show_hl_ll' => true,
    'mxwll_max_events' => 120,
    /** Лимиты раздельно: swing-метки и пары line+label BoS/CHoCH (не смешивать при обрезке). */
    'mxwll_max_swing_labels' => 80,
    'mxwll_max_structure_events' => 80,
    'color_mxwll_bull' => '#14D990',
    'color_mxwll_bear' => '#F24968',
    // Mxwll rolling High/Low (4H=240 баров M1, 1D=1440 баров M1 — как tfDraw в Pine).
    'mxwll_show_4h_levels' => true,
    'mxwll_show_4h_labels' => true,
    'mxwll_show_1d_levels' => true,
    'mxwll_show_1d_labels' => true,
    'color_mxwll_level' => '#00ffff',
    // Auto Fibs + main zigzag leg (updateMain/drawFibs в Pine).
    'mxwll_show_fibs' => true,
    'mxwll_show_main_leg' => true,
    'mxwll_fib_extend_bars' => 5,
    // Sessions — bgcolor по America/New_York (NY / Asia / London).
    'sessions_enabled' => true,
    'sessions_transparency' => 98,
    'color_session_ny' => '#f24968',
    'color_session_asia' => '#14D990',
    'color_session_london' => '#F2B807',
    // Hull band fill между MHULL и SHULL.
    'hull_show_band' => true,
    'hull_band_transparency' => 40,
    // LuxAlgo FVG (detectFVG / mitigation — порт из 3.txt, без логики платформы).
    'lux_fvg_enabled' => true,
    'lux_fvg_threshold_pct' => 0.0,
    'lux_fvg_threshold_auto' => false,
    'lux_fvg_show_last' => 0,
    'lux_fvg_mitigation_levels' => false,
    'lux_fvg_extend_bars' => 20,
    'lux_fvg_dynamic' => false,
    'color_lux_fvg_bull' => '#089981',
    'color_lux_fvg_bull_alpha' => 70,
    'color_lux_fvg_bear' => '#f23645',
    'color_lux_fvg_bear_alpha' => 70,
    // LuxAlgo Order Blocks (volume pivot + mitigation — порт из 3.txt).
    'lux_ob_enabled' => true,
    'lux_ob_length' => 5,
    'lux_ob_bull_count' => 3,
    'lux_ob_bear_count' => 3,
    'lux_ob_mitigation' => 'Wick', // Wick | Close
    'lux_ob_avg_line_style' => 'dotted', // solid | dashed | dotted
    'lux_ob_avg_line_width' => 1,
    'color_lux_ob_bull_bg' => '#089981',
    'color_lux_ob_bull_bg_alpha' => 80,
    'color_lux_ob_bull_border' => '#089981',
    'color_lux_ob_bull_avg' => '#9598a1',
    'color_lux_ob_bull_avg_alpha' => 37,
    'color_lux_ob_bear_bg' => '#f23645',
    'color_lux_ob_bear_bg_alpha' => 80,
    'color_lux_ob_bear_border' => '#f23645',
    'color_lux_ob_bear_avg' => '#9598a1',
    'color_lux_ob_bear_avg_alpha' => 37,
    'label_size' => 'tiny',
  ];

  /** @return list<array{ratio:float,color:string,show:bool}> */
  public static function defaultMxwllFibLevels(): array
  {
    return [
      ['ratio' => 0.236, 'color' => '#a0a5b9', 'show' => true],
      ['ratio' => 0.382, 'color' => '#00ff00', 'show' => true],
      ['ratio' => 0.5, 'color' => '#ffff00', 'show' => true],
      ['ratio' => 0.618, 'color' => '#ff9800', 'show' => true],
      ['ratio' => 0.786, 'color' => '#ff0000', 'show' => true],
      ['ratio' => 0.886, 'color' => '#800080', 'show' => true],
      ['ratio' => 1.13, 'color' => '#0000ff', 'show' => true],
      ['ratio' => 1.27, 'color' => '#008080', 'show' => true],
      ['ratio' => 1.41, 'color' => '#800000', 'show' => true],
      ['ratio' => 1.618, 'color' => '#000080', 'show' => true],
    ];
  }

  /** @return array<string, mixed> */
  public static function defaultParams(): array
  {
    return self::DEFAULT_PARAMS;
  }

  /**
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @param  array<string, mixed>|null  $params
   * @return array{
   *   lines: list<array{time_start:int,time_end:int,price:float,color:string,style:string,width:int}>,
  *   areas: list<array{time_start:int,time_end:int,price_high:float,price_low:float,fill:string,border:string}>,
   *   labels: list<array{time:int,price:float,text:string,color:string,background:string,position:string}>,
   *   label_size: string,
   *   modules: list<string>
   * }
   */
  public function compute(array $rows, ?array $params = null): array
  {
    $p = array_replace(self::DEFAULT_PARAMS, $params ?? []);
    $labelSize = $this->normalizeLabelSize((string) ($p['label_size'] ?? 'tiny'));

    if (count($rows) < 2) {
      return [
        'lines' => [],
        'areas' => [],
        'labels' => [],
        'label_size' => $labelSize,
        'modules' => [],
      ];
    }

    $allLines = [];
    $allAreas = [];
    $allLabels = [];
    $modules = [];

    if ((bool) ($p['ut_enabled'] ?? true)) {
      $ut = $this->computeUtBot($rows, $p);
      $allLines = array_merge($allLines, $ut['lines']);
      $allLabels = array_merge($allLabels, $ut['labels']);
      if ($ut['lines'] !== [] || $ut['labels'] !== []) {
        $modules[] = 'ut_bot';
      }
    }

    if ((bool) ($p['hull_enabled'] ?? true)) {
      $hull = $this->computeHullSuite($rows, $p);
      $allLines = array_merge($allLines, $hull['lines']);
      $allAreas = array_merge($allAreas, $hull['areas'] ?? []);
      if ($hull['lines'] !== [] || ($hull['areas'] ?? []) !== []) {
        $modules[] = 'hull_suite';
      }
    }

    if ((bool) ($p['three_br_enabled'] ?? true)) {
      $threeBr = $this->computeThreeBarReversal($rows, $p);
      $allLines = array_merge($allLines, $threeBr['lines']);
      $allAreas = array_merge($allAreas, $threeBr['areas']);
      $allLabels = array_merge($allLabels, $threeBr['labels']);
      if ($threeBr['lines'] !== [] || $threeBr['areas'] !== [] || $threeBr['labels'] !== []) {
        $modules[] = 'three_bar_reversal';
      }
    }

    if ((bool) ($p['lux_fvg_enabled'] ?? true)) {
      $fvg = $this->computeLuxFvg($rows, $p);
      $allLines = array_merge($allLines, $fvg['lines']);
      $allAreas = array_merge($allAreas, $fvg['areas']);
      if ($fvg['lines'] !== [] || $fvg['areas'] !== []) {
        $modules[] = 'lux_fvg';
      }
    }

    if ((bool) ($p['lux_ob_enabled'] ?? true)) {
      $ob = $this->computeLuxOrderBlocks($rows, $p);
      $allLines = array_merge($allLines, $ob['lines']);
      $allAreas = array_merge($allAreas, $ob['areas']);
      if ($ob['lines'] !== [] || $ob['areas'] !== []) {
        $modules[] = 'lux_ob';
      }
    }

    if ((bool) ($p['rs_enabled'] ?? true)) {
      $rs = $this->computeReversalSignals($rows, $p);
      $allLines = array_merge($allLines, $rs['lines']);
      $allLabels = array_merge($allLabels, $rs['labels']);
      if ($rs['lines'] !== [] || $rs['labels'] !== []) {
        $modules[] = 'reversal_signals';
      }
    }

    if ((bool) ($p['mxwll_enabled'] ?? true)) {
      $mxwll = $this->computeMxwllSuite($rows, $p);
      $allLines = array_merge($allLines, $mxwll['lines']);
      $allLabels = array_merge($allLabels, $mxwll['labels']);
      if ($mxwll['lines'] !== [] || $mxwll['labels'] !== []) {
        $modules[] = 'mxwll_structure';
      }
    }

    if ((bool) ($p['sessions_enabled'] ?? true)) {
      $sessions = $this->computeSessions($rows, $p);
      $allAreas = array_merge($allAreas, $sessions['areas']);
      if ($sessions['areas'] !== []) {
        $modules[] = 'sessions';
      }
    }

    usort($allLabels, static fn (array $a, array $b): int => ((int) $a['time']) <=> ((int) $b['time']));

    return [
      'lines' => $allLines,
      'areas' => $allAreas,
      'labels' => $allLabels,
      'label_size' => $labelSize,
      'modules' => $modules,
    ];
  }

  /**
   * UT Bot: ATR-трейлинг и сигналы Buy/Sell по пересечению цены и стопа (как в 3.txt).
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @param  array<string, mixed>  $p
   * @return array{
   *   lines: list<array{time_start:int,time_end:int,price:float,color:string,style:string,width:int}>,
   *   labels: list<array{time:int,price:float,text:string,color:string,background:string,position:string}>
   * }
   */
  private function computeUtBot(array $rows, array $p): array
  {
    $key = max(0.0001, (float) $p['ut_key_value']);
    $period = max(1, (int) $p['ut_atr_period']);
    $showLabels = (bool) $p['show_ut_labels'];
    $keepRecent = (bool) $p['keep_recent_only'];
    $maxLabels = max(1, min(10, (int) $p['max_ut_labels']));
    $buyBg = (string) $p['color_ut_buy'];
    $sellBg = (string) $p['color_ut_sell'];
    $showTrail = (bool) $p['show_ut_trailing_stop'];
    $trailBull = (string) $p['color_ut_trail_bull'];
    $trailBear = (string) $p['color_ut_trail_bear'];
    $maxTrailSegments = max(50, min(5000, (int) ($p['ut_max_trail_segments'] ?? 1200)));

    $n = count($rows);
    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $close = array_map('floatval', array_column($rows, 'close'));

    $atr = $this->atrWilder($high, $low, $close, $period);

    $stops = array_fill(0, $n, 0.0);
    $pos = array_fill(0, $n, 0);

    for ($i = 0; $i < $n; $i++) {
      $src = $close[$i];
      $prevSrc = $i > 0 ? $close[$i - 1] : $src;
      $prevStop = $i > 0 ? $stops[$i - 1] : 0.0;
      $nLoss = $key * (float) ($atr[$i] ?? 0.0);

      // Логика xATRTrailingStop из Pine (3.txt): стоп подтягивается за ценой в сторону тренда.
      if ($src > $prevStop && $prevSrc > $prevStop) {
        $stops[$i] = max($prevStop, $src - $nLoss);
      } elseif ($src < $prevStop && $prevSrc < $prevStop) {
        $stops[$i] = min($prevStop, $src + $nLoss);
      } elseif ($src > $prevStop) {
        $stops[$i] = $src - $nLoss;
      } else {
        $stops[$i] = $src + $nLoss;
      }

      if ($prevSrc < $prevStop && $src > $prevStop) {
        $pos[$i] = 1;
      } elseif ($prevSrc > $prevStop && $src < $prevStop) {
        $pos[$i] = -1;
      } else {
        $pos[$i] = $i > 0 ? $pos[$i - 1] : 0;
      }
    }

    $linesOut = [];
    if ($showTrail) {
      // Ломаная ATR-трейла: на каждом баре соединяем (t[i-1], stop[i-1]) → (t[i], stop[i]),
      // как plot(xATRTrailingStop) в Pine, без тысяч коротких горизонталей на каждое микродвижение стопа.
      $start = max(1, $n - $maxTrailSegments);
      for ($i = $start; $i < $n; $i++) {
        $linesOut[] = [
          'time_start' => (int) $time[$i - 1],
          'time_end' => (int) $time[$i],
          'price_start' => (float) $stops[$i - 1],
          'price_end' => (float) $stops[$i],
          'color' => $pos[$i] >= 0 ? $trailBull : $trailBear,
          'style' => 'Solid',
          'width' => 2,
        ];
      }
    }

    $labelsOut = [];
    if ($showLabels) {
      $buyLabels = [];
      $sellLabels = [];
      for ($i = 1; $i < $n; $i++) {
        $src = $close[$i];
        $prevSrc = $close[$i - 1];
        $stop = $stops[$i];
        $prevStop = $stops[$i - 1];

        // EMA(close, 1) в Pine совпадает с close; crossover — как ta.crossover в 3.txt.
        $above = $src > $stop && $prevSrc <= $prevStop;
        $below = $src < $stop && $prevSrc >= $prevStop;
        $buy = $src > $stop && $above;
        $sell = $src < $stop && $below;

        if ($buy) {
          $buyLabels[] = [
            'time' => (int) $time[$i],
            'price' => (float) $low[$i],
            'text' => 'Buy',
            'color' => '#ffffff',
            'background' => $buyBg,
            'position' => 'below',
          ];
        }
        if ($sell) {
          $sellLabels[] = [
            'time' => (int) $time[$i],
            'price' => (float) $high[$i],
            'text' => 'Sell',
            'color' => '#ffffff',
            'background' => $sellBg,
            'position' => 'above',
          ];
        }
      }

      if ($keepRecent) {
        $buyLabels = array_slice($buyLabels, -$maxLabels);
        $sellLabels = array_slice($sellLabels, -$maxLabels);
      }

      $labelsOut = array_merge($buyLabels, $sellLabels);
      usort($labelsOut, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
    }

    return [
      'lines' => $linesOut,
      'labels' => $labelsOut,
    ];
  }

  /**
   * ATR по Wilder (аналог ta.atr в Pine).
   *
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @param  list<float>  $close
   * @return list<float|null>
   */
  private function atrWilder(array $high, array $low, array $close, int $len): array
  {
    $n = count($high);
    $out = array_fill(0, $n, null);
    if ($len <= 0 || $n === 0) {
      return $out;
    }

    $tr = [];
    for ($i = 0; $i < $n; $i++) {
      if ($i === 0) {
        $tr[$i] = $high[$i] - $low[$i];
      } else {
        $tr[$i] = max(
          $high[$i] - $low[$i],
          abs($high[$i] - $close[$i - 1]),
          abs($low[$i] - $close[$i - 1])
        );
      }
    }

    if ($n < $len) {
      return $out;
    }

    $seed = 0.0;
    for ($i = 0; $i < $len; $i++) {
      $seed += $tr[$i];
    }
    $seed /= $len;
    $out[$len - 1] = $seed;
    for ($i = $len; $i < $n; $i++) {
      $prev = (float) $out[$i - 1];
      $out[$i] = (($prev * ($len - 1)) + $tr[$i]) / $len;
    }

    return $out;
  }

  /**
   * Hull Suite (Hma / Thma / Ehma) из Pine: основной контур MHULL и сигнальная линия SHULL=HULL[2].
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @param  array<string, mixed>  $p
   * @return array{
   *   lines: list<array{
   *     time_start:int,time_end:int,price_start:float,price_end:float,color:string,style:string,width:int
   *   }>
   * }
   */
  private function computeHullSuite(array $rows, array $p): array
  {
    $time = array_column($rows, 'time');
    $close = array_map('floatval', array_column($rows, 'close'));
    $n = count($close);
    $mode = (string) ($p['hull_mode'] ?? 'Hma');
    $length = max(2, (int) ($p['hull_length'] ?? 55));
    $lengthMult = max(0.1, min(5.0, (float) ($p['hull_length_multiplier'] ?? 1.0)));
    $len = max(2, (int) round($length * $lengthMult));
    $showSignal = (bool) ($p['hull_show_signal_line'] ?? true);
    $switchColor = (bool) ($p['hull_switch_color'] ?? true);
    $lineWidth = max(1, min(6, (int) ($p['hull_line_width'] ?? 2)));
    $signalLineWidth = max(1, min(6, (int) ($p['hull_signal_line_width'] ?? 1)));
    $maxSegments = max(50, min(5000, (int) ($p['hull_max_segments'] ?? 1200)));
    $cUp = (string) ($p['color_hull_up'] ?? '#00ff00');
    $cDown = (string) ($p['color_hull_down'] ?? '#ff0000');
    $cNeutral = (string) ($p['color_hull_neutral'] ?? '#ff9800');

    $hull = match ($mode) {
      'Ehma' => $this->ehmaSeries($close, $len),
      'Thma' => $this->thmaSeries($close, max(2, (int) floor($len / 2))),
      default => $this->hmaSeries($close, $len),
    };

    $signal = array_fill(0, $n, null);
    for ($i = 2; $i < $n; $i++) {
      $signal[$i] = $hull[$i - 2];
    }

    $lines = [];
    $start = max(1, $n - $maxSegments);
    for ($i = $start; $i < $n; $i++) {
      $prev = $hull[$i - 1];
      $cur = $hull[$i];
      if ($prev === null || $cur === null) {
        continue;
      }

      $color = $switchColor
        ? (($i >= 2 && $hull[$i - 2] !== null && $cur > $hull[$i - 2]) ? $cUp : $cDown)
        : $cNeutral;

      $lines[] = [
        'time_start' => (int) $time[$i - 1],
        'time_end' => (int) $time[$i],
        'price_start' => (float) $prev,
        'price_end' => (float) $cur,
        'color' => $color,
        'style' => 'Solid',
        'width' => $lineWidth,
      ];
    }

    if ($showSignal) {
      for ($i = $start; $i < $n; $i++) {
        $prev = $signal[$i - 1] ?? null;
        $cur = $signal[$i] ?? null;
        if ($prev === null || $cur === null) {
          continue;
        }
        $lines[] = [
          'time_start' => (int) $time[$i - 1],
          'time_end' => (int) $time[$i],
          'price_start' => (float) $prev,
          'price_end' => (float) $cur,
          'color' => $switchColor ? (($i >= 2 && $hull[$i - 2] !== null && $hull[$i] !== null && $hull[$i] > $hull[$i - 2]) ? $cUp : $cDown) : $cNeutral,
          'style' => 'Solid',
          'width' => $signalLineWidth,
        ];
      }
    }

    $areas = [];
    if ($showSignal && (bool) ($p['hull_show_band'] ?? true)) {
      $bandTrans = max(0, min(100, (int) ($p['hull_band_transparency'] ?? 40)));
      $bandAlpha = round((100 - $bandTrans) / 100, 2);
      for ($i = $start; $i < $n; $i++) {
        $h0 = $hull[$i - 1] ?? null;
        $h1 = $hull[$i] ?? null;
        $s0 = $signal[$i - 1] ?? null;
        $s1 = $signal[$i] ?? null;
        if ($h0 === null || $h1 === null || $s0 === null || $s1 === null) {
          continue;
        }
        $trendUp = $switchColor ? ($h1 > ($hull[$i - 2] ?? $h1)) : true;
        $base = $switchColor ? ($trendUp ? $cUp : $cDown) : $cNeutral;
        $fill = $this->colorWithAlpha($base, $bandAlpha);
        $areas[] = [
          'time_start' => (int) $time[$i - 1],
          'time_end' => (int) $time[$i],
          'price_high' => max($h0, $h1, $s0, $s1),
          'price_low' => min($h0, $h1, $s0, $s1),
          'fill' => $fill,
          'border' => 'none',
          'kind' => 'hull_band',
        ];
      }
    }

    return ['lines' => $lines, 'areas' => $areas];
  }

  /** @param list<float> $src @return list<float|null> */
  private function hmaSeries(array $src, int $len): array
  {
    $half = max(1, (int) floor($len / 2));
    $sqrtLen = max(1, (int) round(sqrt($len)));
    $w1 = $this->wmaSeries($src, $half);
    $w2 = $this->wmaSeries($src, $len);
    $diff = [];
    $n = count($src);
    for ($i = 0; $i < $n; $i++) {
      $diff[] = ($w1[$i] !== null && $w2[$i] !== null) ? (2.0 * $w1[$i] - $w2[$i]) : null;
    }

    return $this->wmaSeries($diff, $sqrtLen);
  }

  /** @param list<float> $src @return list<float|null> */
  private function ehmaSeries(array $src, int $len): array
  {
    $half = max(1, (int) floor($len / 2));
    $sqrtLen = max(1, (int) round(sqrt($len)));
    $e1 = $this->emaSeries($src, $half);
    $e2 = $this->emaSeries($src, $len);
    $diff = [];
    $n = count($src);
    for ($i = 0; $i < $n; $i++) {
      $diff[] = ($e1[$i] !== null && $e2[$i] !== null) ? (2.0 * $e1[$i] - $e2[$i]) : null;
    }

    return $this->emaSeries($diff, $sqrtLen);
  }

  /** @param list<float> $src @return list<float|null> */
  private function thmaSeries(array $src, int $len): array
  {
    $l3 = max(1, (int) floor($len / 3));
    $l2 = max(1, (int) floor($len / 2));
    $w3 = $this->wmaSeries($src, $l3);
    $w2 = $this->wmaSeries($src, $l2);
    $w1 = $this->wmaSeries($src, $len);
    $mix = [];
    $n = count($src);
    for ($i = 0; $i < $n; $i++) {
      $mix[] = ($w3[$i] !== null && $w2[$i] !== null && $w1[$i] !== null)
        ? (3.0 * $w3[$i] - $w2[$i] - $w1[$i])
        : null;
    }

    return $this->wmaSeries($mix, $len);
  }

  /**
   * @param  list<float|int|null>  $src
   * @return list<float|null>
   */
  private function wmaSeries(array $src, int $len): array
  {
    $n = count($src);
    $out = array_fill(0, $n, null);
    if ($len <= 0) {
      return $out;
    }
    $den = ($len * ($len + 1)) / 2.0;
    for ($i = $len - 1; $i < $n; $i++) {
      $ok = true;
      $num = 0.0;
      $w = 1;
      for ($j = $i - $len + 1; $j <= $i; $j++, $w++) {
        $v = $src[$j] ?? null;
        if ($v === null) {
          $ok = false;
          break;
        }
        $num += ((float) $v) * $w;
      }
      if ($ok) {
        $out[$i] = $num / $den;
      }
    }

    return $out;
  }

  /**
   * @param  list<float|int|null>  $src
   * @return list<float|null>
   */
  private function emaSeries(array $src, int $len): array
  {
    $n = count($src);
    $out = array_fill(0, $n, null);
    if ($len <= 0 || $n === 0) {
      return $out;
    }
    $alpha = 2.0 / ($len + 1.0);
    $ema = null;
    for ($i = 0; $i < $n; $i++) {
      $v = $src[$i] ?? null;
      if ($v === null) {
        continue;
      }
      $fv = (float) $v;
      $ema = $ema === null ? $fv : ($alpha * $fv + (1.0 - $alpha) * $ema);
      $out[$i] = $ema;
    }

    return $out;
  }

  private function normalizeLabelSize(string $size): string
  {
    $s = strtolower(trim($size));

    return in_array($s, ['tiny', 'small', 'normal'], true) ? $s : 'tiny';
  }

  /**
   * Преобразует HEX-цвет в rgba для полупрозрачной заливки зон 3BR.
   */
  private function hexToRgba(string $hex, float $alpha): string
  {
    $h = ltrim(trim($hex), '#');
    if (strlen($h) === 3) {
      $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
    } elseif (strlen($h) === 8) {
      $h = substr($h, 0, 6);
    }
    if (strlen($h) !== 6 || !ctype_xdigit($h)) {
      return 'rgba(148,163,184,0.22)';
    }

    $r = hexdec(substr($h, 0, 2));
    $g = hexdec(substr($h, 2, 2));
    $b = hexdec(substr($h, 4, 2));
    $a = max(0.0, min(1.0, $alpha));

    return sprintf('rgba(%d,%d,%d,%.3F)', $r, $g, $b, $a);
  }

  /**
   * Three Bar Reversal из Pine: базовые bullish/bearish паттерны и опциональный MA-тренд фильтр.
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @param  array<string, mixed>  $p
   * @return array{
   *   lines: list<array{
   *     time_start:int,time_end:int,price_start:float,price_end:float,color:string,style:string,width:int
   *   }>,
  *   areas: list<array{time_start:int,time_end:int,price_high:float,price_low:float,fill:string,border:string}>,
   *   labels: list<array{time:int,price:float,text:string,color:string,background:string,position:string}>
   * }
   */
  private function computeThreeBarReversal(array $rows, array $p): array
  {
    $n = count($rows);
    if ($n < 3) {
      return ['lines' => [], 'areas' => [], 'labels' => []];
    }

    $time = array_column($rows, 'time');
    $open = array_map('floatval', array_column($rows, 'open'));
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $close = array_map('floatval', array_column($rows, 'close'));
    $volume = array_map(static fn (array $r): float => (float) ($r['volume'] ?? 0.0), $rows);

    $patternType = (string) ($p['three_br_type'] ?? 'All');
    $trendType = (string) ($p['three_br_trend_type'] ?? 'None');
    $trendFilter = (string) ($p['three_br_trend_filter'] ?? 'Aligned');
    $maType = (string) ($p['three_br_ma_type'] ?? 'HMA');
    $fastLen = max(2, min(500, (int) ($p['three_br_ma_fast_length'] ?? 50)));
    $slowLen = max(3, min(1000, (int) ($p['three_br_ma_slow_length'] ?? 200)));
    $stAtr = max(1, (int) ($p['three_br_st_atr_period'] ?? 10));
    $stFactor = max(0.1, (float) ($p['three_br_st_factor'] ?? 3.0));
    $dcLen = max(1, (int) ($p['three_br_dc_length'] ?? 13));
    $srMode = (string) ($p['three_br_sr_mode'] ?? 'Level');
    $showLevels = (bool) ($p['three_br_show_levels'] ?? true);
    $maxPatterns = max(10, min(500, (int) ($p['three_br_max_patterns'] ?? 120)));
    $bullColor = (string) ($p['color_three_br_bull'] ?? '#2962ff');
    $bearColor = (string) ($p['color_three_br_bear'] ?? '#ff9800');
    $bullFill = $this->hexToRgba($bullColor, 0.27);
    $bearFill = $this->hexToRgba($bearColor, 0.27);
    $bullSrFill = $this->hexToRgba($bullColor, $srMode === 'Zone' ? 0.27 : 0.47);
    $bearSrFill = $this->hexToRgba($bearColor, $srMode === 'Zone' ? 0.27 : 0.47);

    [$trendUp, $trendDown] = $this->threeBarReversalTrendFilters(
      $close,
      $high,
      $low,
      $volume,
      $trendType,
      $trendFilter,
      $maType,
      $fastLen,
      $slowLen,
      $stAtr,
      $stFactor,
      $dcLen
    );

    $lines = [];
    $areas = [];
    $labels = [];

    // Состояние конечного автомата Pine: bullProcess / bullProcess2 и зеркало для bear.
    $bullProcess = false;
    $bullProcess2 = false;
    $bearProcess = false;
    $bearProcess2 = false;
    $bullProcessLag1 = false;
    $bearProcessLag1 = false;

    $bullTop = 0.0;
    $bullBtm = 0.0;
    $bullSrTop = 0.0;
    $bearBot = 0.0;
    $bearTop = 0.0;
    $bearSrBot = 0.0;

    $bullLineTopIdx = null;
    $bullLineBtmIdx = null;
    $bullAreaIdx = null;
    $bullSrAreaIdx = null;
    $bearLineBotIdx = null;
    $bearLineTopIdx = null;
    $bearAreaIdx = null;
    $bearSrAreaIdx = null;

    for ($i = 0; $i < $n; $i++) {
      $bullAtBarStart = $bullProcess;
      $bearAtBarStart = $bearProcess;

      $bullishRev = $i >= 2
        && $this->isBullishReversal3Bar($open, $high, $low, $close, $i)
        && $trendUp[$i];
      $bearishRev = $i >= 2
        && $this->isBearishReversal3Bar($open, $high, $low, $close, $i)
        && $trendDown[$i];

      if ($i >= 2 && $bullishRev && $this->threeBarPatternAllowed($patternType, true, $close[$i], $high[$i - 2], $low[$i - 2])) {
        $bullProcess = true;
        $bullTop = (float) $high[$i - 2];
        $bullBtm = (float) min($low[$i - 1], $low[$i]);
        $bullSrTop = $srMode === 'Zone'
          ? (float) max($low[$i - 1], $low[$i])
          : (float) min($low[$i - 1], $low[$i]);

        $labels[] = [
          'time' => (int) $time[$i],
          'price' => (float) $low[$i],
          'text' => '^',
          'color' => $bullColor,
          'background' => 'transparent',
          'position' => 'below',
          'kind' => 'three_br_bull',
        ];

        if ($showLevels) {
          $lines[] = [
            'time_start' => (int) $time[$i - 2],
            'time_end' => (int) $time[$i],
            'price_start' => $bullTop,
            'price_end' => $bullTop,
            'color' => $this->hexToRgba($bullColor, 0.47),
            'style' => 'Solid',
            'width' => 1,
            'kind' => 'three_br_pending',
          ];
          $bullLineTopIdx = count($lines) - 1;
          $lines[] = [
            'time_start' => (int) $time[$i - 1],
            'time_end' => (int) $time[$i],
            'price_start' => $bullBtm,
            'price_end' => $bullBtm,
            'color' => $this->hexToRgba($bullColor, 0.47),
            'style' => 'Solid',
            'width' => 1,
            'kind' => 'three_br_pending',
          ];
          $bullLineBtmIdx = count($lines) - 1;
          $areas[] = [
            'time_start' => (int) $time[$i - 2],
            'time_end' => (int) $time[$i],
            'price_high' => $bullTop,
            'price_low' => $bullBtm,
            'fill' => $bullFill,
            'border' => 'none',
            'kind' => 'three_br_pending',
          ];
          $bullAreaIdx = count($areas) - 1;
        }
      }

      if ($i >= 2 && $bearishRev && $this->threeBarPatternAllowed($patternType, false, $close[$i], $high[$i - 2], $low[$i - 2])) {
        $bearProcess = true;
        $bearBot = (float) $low[$i - 2];
        $bearTop = (float) max($high[$i - 1], $high[$i]);
        $bearSrBot = $srMode === 'Zone'
          ? (float) min($high[$i - 1], $high[$i])
          : (float) max($high[$i - 1], $high[$i]);

        $labels[] = [
          'time' => (int) $time[$i],
          'price' => (float) $high[$i],
          'text' => '¡',
          'color' => $bearColor,
          'background' => 'transparent',
          'position' => 'above',
          'kind' => 'three_br_bear',
        ];

        if ($showLevels) {
          $lines[] = [
            'time_start' => (int) $time[$i - 2],
            'time_end' => (int) $time[$i],
            'price_start' => $bearBot,
            'price_end' => $bearBot,
            'color' => $this->hexToRgba($bearColor, 0.47),
            'style' => 'Solid',
            'width' => 1,
            'kind' => 'three_br_pending',
          ];
          $bearLineBotIdx = count($lines) - 1;
          $lines[] = [
            'time_start' => (int) $time[$i - 1],
            'time_end' => (int) $time[$i],
            'price_start' => $bearTop,
            'price_end' => $bearTop,
            'color' => $this->hexToRgba($bearColor, 0.47),
            'style' => 'Solid',
            'width' => 1,
            'kind' => 'three_br_pending',
          ];
          $bearLineTopIdx = count($lines) - 1;
          $areas[] = [
            'time_start' => (int) $time[$i - 2],
            'time_end' => (int) $time[$i],
            'price_high' => $bearTop,
            'price_low' => $bearBot,
            'fill' => $bearFill,
            'border' => 'none',
            'kind' => 'three_br_pending',
          ];
          $bearAreaIdx = count($areas) - 1;
        }
      }

      if ($bullProcess && $i >= 1) {
        $confirmed = $close[$i - 1] > $bullTop;
        $failed = $close[$i - 1] < $bullBtm || $bearishRev;

        if ($confirmed) {
          // Pine: пропускаем «?», если подтверждение на следующем баре после детекции (enhanced).
          if (! ($bullAtBarStart && ! $bullProcessLag1)) {
            $labels[] = [
              'time' => (int) $time[$i - 1],
              'price' => (float) $low[$i - 1],
              'text' => '?',
              'color' => $bullColor,
              'background' => 'transparent',
              'position' => 'below',
              'kind' => 'three_br_confirm',
            ];
          }
          $bullProcess = false;
          if ($srMode !== 'None') {
            $bullProcess2 = true;
            $areas[] = [
              'time_start' => (int) $time[$i],
              'time_end' => (int) $time[$i],
              'price_high' => $bullSrTop,
              'price_low' => $bullBtm,
              'fill' => $bullSrFill,
              'border' => $this->hexToRgba($bullColor, 0.47),
              'kind' => 'three_br_sr_bull',
            ];
            $bullSrAreaIdx = count($areas) - 1;
          }
        } elseif ($failed) {
          $bullProcess = false;
          $endT = (int) $time[$i - 1];
          if ($bullLineTopIdx !== null) {
            $lines[$bullLineTopIdx]['time_end'] = $endT;
          }
          if ($bullLineBtmIdx !== null) {
            $lines[$bullLineBtmIdx]['time_end'] = $endT;
          }
          if ($bullAreaIdx !== null) {
            $areas[$bullAreaIdx]['time_end'] = $endT;
          }
          $bullLineTopIdx = null;
          $bullLineBtmIdx = null;
          $bullAreaIdx = null;
        } else {
          $endT = (int) $time[$i];
          if ($bullLineTopIdx !== null) {
            $lines[$bullLineTopIdx]['time_end'] = $endT;
          }
          if ($bullLineBtmIdx !== null) {
            $lines[$bullLineBtmIdx]['time_end'] = $endT;
          }
          if ($bullAreaIdx !== null) {
            $areas[$bullAreaIdx]['time_end'] = $endT;
          }
        }
      }

      if ($bearProcess && $i >= 1) {
        $failed = $close[$i - 1] > $bearTop || $bullishRev;
        $confirmed = $close[$i - 1] < $bearBot;

        if ($failed) {
          $bearProcess = false;
          $endT = (int) $time[$i - 1];
          if ($bearLineBotIdx !== null) {
            $lines[$bearLineBotIdx]['time_end'] = $endT;
          }
          if ($bearLineTopIdx !== null) {
            $lines[$bearLineTopIdx]['time_end'] = $endT;
          }
          if ($bearAreaIdx !== null) {
            $areas[$bearAreaIdx]['time_end'] = $endT;
          }
          $bearLineBotIdx = null;
          $bearLineTopIdx = null;
          $bearAreaIdx = null;
        } elseif ($confirmed) {
          if (! ($bearAtBarStart && ! $bearProcessLag1)) {
            $labels[] = [
              'time' => (int) $time[$i - 1],
              'price' => (float) $high[$i - 1],
              'text' => '?',
              'color' => $bearColor,
              'background' => 'transparent',
              'position' => 'above',
              'kind' => 'three_br_confirm',
            ];
          }
          $bearProcess = false;
          if ($srMode !== 'None') {
            $bearProcess2 = true;
            $areas[] = [
              'time_start' => (int) $time[$i],
              'time_end' => (int) $time[$i],
              'price_high' => $bearTop,
              'price_low' => $bearSrBot,
              'fill' => $bearSrFill,
              'border' => $this->hexToRgba($bearColor, 0.47),
              'kind' => 'three_br_sr_bear',
            ];
            $bearSrAreaIdx = count($areas) - 1;
          }
        } else {
          $endT = (int) $time[$i];
          if ($bearLineBotIdx !== null) {
            $lines[$bearLineBotIdx]['time_end'] = $endT;
          }
          if ($bearLineTopIdx !== null) {
            $lines[$bearLineTopIdx]['time_end'] = $endT;
          }
          if ($bearAreaIdx !== null) {
            $areas[$bearAreaIdx]['time_end'] = $endT;
          }
        }
      }

      if ($bullProcess2 && $bullSrAreaIdx !== null && $srMode !== 'None') {
        $areas[$bullSrAreaIdx]['time_end'] = (int) $time[$i];
        if ($close[$i] <= $areas[$bullSrAreaIdx]['price_low']) {
          $bullProcess2 = false;
          $bullSrAreaIdx = null;
        }
      }

      if ($bearProcess2 && $bearSrAreaIdx !== null && $srMode !== 'None') {
        $areas[$bearSrAreaIdx]['time_end'] = (int) $time[$i];
        if ($close[$i] >= $areas[$bearSrAreaIdx]['price_high']) {
          $bearProcess2 = false;
          $bearSrAreaIdx = null;
        }
      }

      $bullProcessLag1 = $bullAtBarStart;
      $bearProcessLag1 = $bearAtBarStart;
    }

    if (count($labels) > $maxPatterns) {
      $labels = array_slice($labels, -$maxPatterns);
    }
    if (count($lines) > $maxPatterns * 4) {
      $lines = array_slice($lines, -$maxPatterns * 4);
    }
    if (count($areas) > $maxPatterns * 2) {
      $areas = array_slice($areas, -$maxPatterns * 2);
    }

    return ['lines' => $lines, 'areas' => $areas, 'labels' => $labels];
  }

  /**
   * Reversal Signals (упрощённый блок 3.txt): счётчик momentum 9 + step S/R.
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return array{lines:list<array<string,mixed>>,labels:list<array<string,mixed>>}
   */
  private function computeReversalSignals(array $rows, array $p): array
  {
    $n = count($rows);
    if ($n < 5) {
      return ['lines' => [], 'labels' => []];
    }

    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $close = array_map('floatval', array_column($rows, 'close'));

    $showSr = (bool) ($p['rs_sr_levels'] ?? true);
    $maxMarkers = max(10, min(500, (int) ($p['rs_max_momentum_markers'] ?? 80)));
    $maxSegments = max(20, min(2000, (int) ($p['rs_max_sr_segments'] ?? 200)));
    $bullColor = (string) ($p['color_rs_bull'] ?? '#089981');
    $bearColor = (string) ($p['color_rs_bear'] ?? '#f23645');

    $bScore = 0;
    $sScore = 0;
    $bResistance = null;
    $sSupport = null;
    $labels = [];
    $lines = [];

    $resActive = false;
    $resStart = 0;
    $resLevel = null;
    $supActive = false;
    $supStart = 0;
    $supLevel = null;

    for ($i = 0; $i < $n; $i++) {
      if ($i >= 4) {
        $consecutiveDown = $close[$i] < $close[$i - 4];
        if ($consecutiveDown) {
          $bScore = $bScore === 9 ? 1 : $bScore + 1;
          $sScore = 0;
        } else {
          $sScore = $sScore === 9 ? 1 : $sScore + 1;
          $bScore = 0;
        }

        $h9 = max(array_slice($high, max(0, $i - 8), min(9, $i + 1)));
        $l9 = min(array_slice($low, max(0, $i - 8), min(9, $i + 1)));

        if ($bScore === 9) {
          $bResistance = $h9;
        } elseif ($bResistance !== null && $close[$i] > $bResistance) {
          $bResistance = null;
        }

        if ($sScore === 9) {
          $sSupport = $l9;
        } elseif ($sSupport !== null && $close[$i] < $sSupport) {
          $sSupport = null;
        }

        if ($bScore === 9) {
          $labels[] = [
            'time' => (int) $time[$i],
            'price' => (float) $low[$i],
            'text' => '●',
            'color' => '#ffffff',
            'background' => $this->hexToRgba($bullColor, 0.75),
            'position' => 'below',
            'kind' => 'rs_momentum_bull',
          ];
        }
        if ($sScore === 9) {
          $labels[] = [
            'time' => (int) $time[$i],
            'price' => (float) $high[$i],
            'text' => '●',
            'color' => '#ffffff',
            'background' => $this->hexToRgba($bearColor, 0.75),
            'position' => 'above',
            'kind' => 'rs_momentum_bear',
          ];
        }
      }

      if ($showSr) {
        if ($bResistance === null) {
          if ($resActive) {
            $lines[] = [
              'time_start' => $resStart,
              'time_end' => (int) $time[$i],
              'price_start' => (float) $resLevel,
              'price_end' => (float) $resLevel,
              'color' => $this->hexToRgba($bearColor, 0.5),
              'style' => 'Solid',
              'width' => 2,
              'kind' => 'rs_resistance',
            ];
            $resActive = false;
          }
        } elseif (! $resActive || $bResistance !== $resLevel) {
          if ($resActive) {
            $lines[] = [
              'time_start' => $resStart,
              'time_end' => (int) $time[$i],
              'price_start' => (float) $resLevel,
              'price_end' => (float) $resLevel,
              'color' => $this->hexToRgba($bearColor, 0.5),
              'style' => 'Solid',
              'width' => 2,
              'kind' => 'rs_resistance',
            ];
          }
          $resActive = true;
          $resStart = (int) $time[$i];
          $resLevel = $bResistance;
        }

        if ($sSupport === null) {
          if ($supActive) {
            $lines[] = [
              'time_start' => $supStart,
              'time_end' => (int) $time[$i],
              'price_start' => (float) $supLevel,
              'price_end' => (float) $supLevel,
              'color' => $this->hexToRgba($bullColor, 0.5),
              'style' => 'Solid',
              'width' => 2,
              'kind' => 'rs_support',
            ];
            $supActive = false;
          }
        } elseif (! $supActive || $sSupport !== $supLevel) {
          if ($supActive) {
            $lines[] = [
              'time_start' => $supStart,
              'time_end' => (int) $time[$i],
              'price_start' => (float) $supLevel,
              'price_end' => (float) $supLevel,
              'color' => $this->hexToRgba($bullColor, 0.5),
              'style' => 'Solid',
              'width' => 2,
              'kind' => 'rs_support',
            ];
          }
          $supActive = true;
          $supStart = (int) $time[$i];
          $supLevel = $sSupport;
        }
      }
    }

    if ($showSr) {
      $lastT = (int) $time[$n - 1];
      if ($resActive && $resLevel !== null) {
        $lines[] = [
          'time_start' => $resStart,
          'time_end' => $lastT,
          'price_start' => (float) $resLevel,
          'price_end' => (float) $resLevel,
          'color' => $this->hexToRgba($bearColor, 0.5),
          'style' => 'Solid',
          'width' => 2,
          'kind' => 'rs_resistance',
        ];
      }
      if ($supActive && $supLevel !== null) {
        $lines[] = [
          'time_start' => $supStart,
          'time_end' => $lastT,
          'price_start' => (float) $supLevel,
          'price_end' => (float) $supLevel,
          'color' => $this->hexToRgba($bullColor, 0.5),
          'style' => 'Solid',
          'width' => 2,
          'kind' => 'rs_support',
        ];
      }
    }

    if (count($labels) > $maxMarkers) {
      $labels = array_slice($labels, -$maxMarkers);
    }
    if (count($lines) > $maxSegments) {
      $lines = array_slice($lines, -$maxSegments);
    }

    return ['lines' => $lines, 'labels' => $labels];
  }

  /**
   * Фильтры тренда Three Bar Reversal: MA cloud / Supertrend / Donchian.
   *
   * @param  list<float>  $close
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @param  list<float>  $volume
   * @return array{0:list<bool>,1:list<bool>}
   */
  private function threeBarReversalTrendFilters(
    array $close,
    array $high,
    array $low,
    array $volume,
    string $trendType,
    string $trendFilter,
    string $maType,
    int $fastLen,
    int $slowLen,
    int $stAtr,
    float $stFactor,
    int $dcLen,
  ): array {
    $n = count($close);
    $trendUp = array_fill(0, $n, true);
    $trendDown = array_fill(0, $n, true);

    if ($trendType === 'Moving Average Cloud') {
      $maFast = $this->movingAverageByType($close, $volume, $maType, $fastLen);
      $maSlow = $this->movingAverageByType($close, $volume, $maType, $slowLen);
      for ($i = 0; $i < $n; $i++) {
        if ($maFast[$i] === null || $maSlow[$i] === null) {
          $trendUp[$i] = false;
          $trendDown[$i] = false;
          continue;
        }
        $alignedDown = $close[$i] < $maFast[$i] && $maFast[$i] < $maSlow[$i];
        $alignedUp = $close[$i] > $maFast[$i] && $maFast[$i] > $maSlow[$i];
        if ($trendFilter === 'Opposite') {
          $trendDown[$i] = $alignedUp;
          $trendUp[$i] = $alignedDown;
        } else {
          $trendDown[$i] = $alignedDown;
          $trendUp[$i] = $alignedUp;
        }
      }
    } elseif ($trendType === 'Supertrend') {
      $st = $this->superTrendSeries($high, $low, $close, $stAtr, $stFactor);
      for ($i = 0; $i < $n; $i++) {
        $dir = $st['direction'][$i];
        if ($trendFilter === 'Opposite') {
          $trendDown[$i] = $dir < 0;
          $trendUp[$i] = $dir > 0;
        } else {
          $trendDown[$i] = $dir > 0;
          $trendUp[$i] = $dir < 0;
        }
      }
    } elseif ($trendType === 'Donchian Channels') {
      $upper = $this->rollingHighest($close, $dcLen);
      $lower = $this->rollingLowest($close, $dcLen);
      $os = 0;
      for ($i = 0; $i < $n; $i++) {
        if ($upper[$i] !== null && $i > 0 && $upper[$i - 1] !== null && $upper[$i] > $upper[$i - 1]) {
          $os = 1;
        } elseif ($lower[$i] !== null && $i > 0 && $lower[$i - 1] !== null && $lower[$i] < $lower[$i - 1]) {
          $os = 0;
        }
        if ($trendFilter === 'Opposite') {
          $trendDown[$i] = $os === 1;
          $trendUp[$i] = $os === 0;
        } else {
          $trendDown[$i] = $os === 0;
          $trendUp[$i] = $os === 1;
        }
      }
    }

    return [$trendUp, $trendDown];
  }

  private function threeBarPatternAllowed(string $patternType, bool $bull, float $close, float $refHigh, float $refLow): bool
  {
    if ($patternType === 'All') {
      return true;
    }
    if ($bull) {
      $enhanced = $close > $refHigh;

      return $patternType === 'Enhanced' ? $enhanced : ! $enhanced;
    }
    $enhanced = $close < $refLow;

    return $patternType === 'Enhanced' ? $enhanced : ! $enhanced;
  }

  /**
   * @param  list<float>  $open
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @param  list<float>  $close
   */
  private function isBullishReversal3Bar(array $open, array $high, array $low, array $close, int $i): bool
  {
    return ($close[$i - 2] < $open[$i - 2])
      && ($low[$i - 1] < $low[$i - 2])
      && ($high[$i - 1] < $high[$i - 2])
      && ($close[$i - 1] < $open[$i - 1])
      && ($close[$i] > $open[$i])
      && ($high[$i] > $high[$i - 2]);
  }

  /**
   * @param  list<float>  $open
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @param  list<float>  $close
   */
  private function isBearishReversal3Bar(array $open, array $high, array $low, array $close, int $i): bool
  {
    return ($close[$i - 2] > $open[$i - 2])
      && ($high[$i - 1] > $high[$i - 2])
      && ($low[$i - 1] > $low[$i - 2])
      && ($close[$i - 1] > $open[$i - 1])
      && ($close[$i] < $open[$i])
      && ($low[$i] < $low[$i - 2]);
  }

  /**
   * SuperTrend (ta.supertrend в Pine): direction < 0 — uptrend, > 0 — downtrend.
   *
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @param  list<float>  $close
   * @return array{direction:list<int>,value:list<float|null>}
   */
  private function superTrendSeries(array $high, array $low, array $close, int $atrPeriod, float $factor): array
  {
    $n = count($close);
    $atr = $this->atrWilder($high, $low, $close, $atrPeriod);
    $upperBand = array_fill(0, $n, null);
    $lowerBand = array_fill(0, $n, null);
    $direction = array_fill(0, $n, 1);
    $supertrend = array_fill(0, $n, null);

    for ($i = 0; $i < $n; $i++) {
      if ($atr[$i] === null) {
        continue;
      }
      $hl2 = ($high[$i] + $low[$i]) / 2.0;
      $basicUpper = $hl2 + $factor * $atr[$i];
      $basicLower = $hl2 - $factor * $atr[$i];

      if ($i === 0 || $lowerBand[$i - 1] === null) {
        $lowerBand[$i] = $basicLower;
        $upperBand[$i] = $basicUpper;
      } else {
        $prevLower = (float) $lowerBand[$i - 1];
        $prevUpper = (float) $upperBand[$i - 1];
        $lowerBand[$i] = ($basicLower > $prevLower || $close[$i - 1] < $prevLower) ? $basicLower : $prevLower;
        $upperBand[$i] = ($basicUpper < $prevUpper || $close[$i - 1] > $prevUpper) ? $basicUpper : $prevUpper;
      }

      if ($i === 0 || $supertrend[$i - 1] === null) {
        $direction[$i] = 1;
      } elseif (abs((float) $supertrend[$i - 1] - (float) $upperBand[$i - 1]) < 1e-9) {
        $direction[$i] = $close[$i] > (float) $upperBand[$i] ? -1 : 1;
      } else {
        $direction[$i] = $close[$i] < (float) $lowerBand[$i] ? 1 : -1;
      }

      $supertrend[$i] = $direction[$i] === -1 ? (float) $lowerBand[$i] : (float) $upperBand[$i];
    }

    return ['direction' => $direction, 'value' => $supertrend];
  }

  /**
   * @param  list<float>  $values
   * @return list<float|null>
   */
  private function rollingHighest(array $values, int $len): array
  {
    $n = count($values);
    $out = array_fill(0, $n, null);
    if ($len <= 0) {
      return $out;
    }
    for ($i = $len - 1; $i < $n; $i++) {
      $out[$i] = max(array_slice($values, $i - $len + 1, $len));
    }

    return $out;
  }

  /**
   * @param  list<float>  $values
   * @return list<float|null>
   */
  private function rollingLowest(array $values, int $len): array
  {
    $n = count($values);
    $out = array_fill(0, $n, null);
    if ($len <= 0) {
      return $out;
    }
    for ($i = $len - 1; $i < $n; $i++) {
      $out[$i] = min(array_slice($values, $i - $len + 1, $len));
    }

    return $out;
  }

  /**
   * MA для фильтра тренда Three Bar Reversal.
   *
   * @param  list<float>  $close
   * @param  list<float>  $volume
   * @return list<float|null>
   */
  private function movingAverageByType(array $close, array $volume, string $type, int $len): array
  {
    return match ($type) {
      'SMA' => $this->smaSeries($close, $len),
      'EMA' => $this->emaSeries($close, $len),
      'RMA' => $this->rmaSeries($close, $len),
      'WMA' => $this->wmaSeries($close, $len),
      'VWMA' => $this->vwmaSeries($close, $volume, $len),
      default => $this->hmaSeries($close, $len),
    };
  }

  /** @param list<float> $src @return list<float|null> */
  private function smaSeries(array $src, int $len): array
  {
    $n = count($src);
    $out = array_fill(0, $n, null);
    if ($len <= 0 || $n === 0) {
      return $out;
    }
    $sum = 0.0;
    for ($i = 0; $i < $n; $i++) {
      $sum += $src[$i];
      if ($i >= $len) {
        $sum -= $src[$i - $len];
      }
      if ($i >= $len - 1) {
        $out[$i] = $sum / $len;
      }
    }

    return $out;
  }

  /** @param list<float> $src @return list<float|null> */
  private function rmaSeries(array $src, int $len): array
  {
    $n = count($src);
    $out = array_fill(0, $n, null);
    if ($len <= 0 || $n === 0) {
      return $out;
    }
    if ($n < $len) {
      return $out;
    }
    $seed = 0.0;
    for ($i = 0; $i < $len; $i++) {
      $seed += $src[$i];
    }
    $seed /= $len;
    $out[$len - 1] = $seed;
    for ($i = $len; $i < $n; $i++) {
      $prev = (float) $out[$i - 1];
      $out[$i] = (($prev * ($len - 1)) + $src[$i]) / $len;
    }

    return $out;
  }

  /**
   * @param list<float> $close
   * @param list<float> $volume
   * @return list<float|null>
   */
  private function vwmaSeries(array $close, array $volume, int $len): array
  {
    $n = count($close);
    $out = array_fill(0, $n, null);
    if ($len <= 0 || $n === 0) {
      return $out;
    }
    $sumPv = 0.0;
    $sumV = 0.0;
    for ($i = 0; $i < $n; $i++) {
      $sumPv += $close[$i] * $volume[$i];
      $sumV += $volume[$i];
      if ($i >= $len) {
        $sumPv -= $close[$i - $len] * $volume[$i - $len];
        $sumV -= $volume[$i - $len];
      }
      if ($i >= $len - 1 && $sumV > 0.0) {
        $out[$i] = $sumPv / $sumV;
      }
    }

    return $out;
  }

  /**
   * Mxwll Suite: structure + rolling 4H/1D levels + Auto Fibs/main leg.
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return array{lines:list<mixed>, labels:list<mixed>}
   */
  private function computeMxwllSuite(array $rows, array $p): array
  {
    $extState = [];
    $structure = $this->computeMxwllStructure($rows, $p, $extState);
    $levels = $this->computeMxwllRollingLevels($rows, $p);
    $fibs = $this->computeMxwllAutoFibs($rows, $p, $extState);

    return [
      'lines' => array_merge($structure['lines'], $levels['lines'], $fibs['lines']),
      'labels' => array_merge($structure['labels'], $levels['labels'], $fibs['labels']),
    ];
  }

  /**
   * Mxwll Suite: swing labels (HH/LH/HL/LL) и события структуры BoS/CHoCH (external + internal).
   * Порт логики calculatePivots / drawStructureExt / drawStructureInternals из 3.txt.
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @param  array<string, mixed>  $p
   * @return array{
   *   lines: list<array{time_start:int,time_end:int,price_start:float,price_end:float,color:string,style:string,width:int}>,
   *   labels: list<array{time:int,price:float,text:string,color:string,background:string,position:string}>
   * }
   */
  private function computeMxwllStructure(array $rows, array $p, ?array &$externalStateOut = null): array
  {
    $n = count($rows);
    if ($n < 30) {
      return ['lines' => [], 'labels' => []];
    }

    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $close = array_map('floatval', array_column($rows, 'close'));

    $bullColor = (string) ($p['color_mxwll_bull'] ?? '#14D990');
    $bearColor = (string) ($p['color_mxwll_bear'] ?? '#F24968');
    $maxSwingLabels = max(10, min(300, (int) ($p['mxwll_max_swing_labels'] ?? 80)));
    $maxStructureEvents = max(20, min(300, (int) ($p['mxwll_max_structure_events'] ?? (int) ($p['mxwll_max_events'] ?? 80))));

    $swingLabels = [];
    $structureEvents = [];

    if ((bool) ($p['mxwll_show_externals'] ?? true)) {
      $extSens = $this->normalizeMxwllSensitivity((int) ($p['mxwll_ext_sensitivity'] ?? 25), [10, 25, 50], 25);
      $this->runMxwllStructurePass(
        $time,
        $high,
        $low,
        $close,
        $extSens,
        (string) ($p['mxwll_ext_structure'] ?? 'All'),
        '',
        false,
        (bool) ($p['mxwll_show_hh_lh'] ?? true),
        (bool) ($p['mxwll_show_hl_ll'] ?? true),
        $bullColor,
        $bearColor,
        $swingLabels,
        $structureEvents,
        $externalStateOut
      );
    }

    if ((bool) ($p['mxwll_show_internals'] ?? true)) {
      $intSens = $this->normalizeMxwllSensitivity((int) ($p['mxwll_int_sensitivity'] ?? 5), [3, 5, 8], 5);
      $this->runMxwllStructurePass(
        $time,
        $high,
        $low,
        $close,
        $intSens,
        (string) ($p['mxwll_int_structure'] ?? 'All'),
        'I-',
        true,
        false,
        false,
        $bullColor,
        $bearColor,
        $swingLabels,
        $structureEvents
      );
    }

    if (count($swingLabels) > $maxSwingLabels) {
      $swingLabels = array_slice($swingLabels, -$maxSwingLabels);
    }
    if (count($structureEvents) > $maxStructureEvents) {
      $structureEvents = array_slice($structureEvents, -$maxStructureEvents);
    }

    $lines = [];
    $labels = $swingLabels;
    foreach ($structureEvents as $event) {
      $lines[] = $event['line'];
      $labels[] = $event['label'];
    }

    return ['lines' => $lines, 'labels' => $labels];
  }

  /**
   * Один проход структуры (external или internal) по всем барам.
   *
   * @param  list<int>  $time
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @param  list<float>  $close
   * @param  list<array{time:int,price:float,text:string,color:string,background:string,position:string}>  $swingLabelsOut
   * @param  list<array{line: array<string, mixed>, label: array<string, mixed>}>  $structureEventsOut
   */
  private function runMxwllStructurePass(
    array $time,
    array $high,
    array $low,
    array $close,
    int $sensitivity,
    string $structureFilter,
    string $labelPrefix,
    bool $internalPass,
    bool $showSwingHighLabels,
    bool $showSwingLowLabels,
    string $bullColor,
    string $bearColor,
    array &$swingLabelsOut,
    array &$structureEventsOut,
    ?array &$externalStateOut = null
  ): void {
    $n = count($time);
    $intraCalc = 0;
    $moving = 0;
    $upAxis = 0.0;
    $upAxisTime = 0;
    $dnAxis = 0.0;
    $dnAxisTime = 0;
    $upside = 1;
    $downside = 1;

    for ($i = 0; $i < $n; $i++) {
      [$topSwing, $botSwing] = $this->mxwllPivotsAt($high, $low, $i, $sensitivity, $intraCalc);

      if ($topSwing > 0.0) {
        $upside = 1;
        $swingTime = (int) ($time[max(0, $i - $sensitivity)] ?? $time[$i]);
        if ($showSwingHighLabels && $labelPrefix === '') {
          $swingText = $topSwing > $upAxis ? 'HH' : 'LH';
          $swingLabelsOut[] = [
            'time' => $swingTime,
            'price' => (float) $topSwing,
            'text' => $swingText,
            'color' => $bearColor,
            'background' => 'transparent',
            'position' => 'above',
            'kind' => 'mxwll_swing',
          ];
        }
        $upAxis = (float) $topSwing;
        $upAxisTime = $swingTime;
      }

      if ($botSwing > 0.0) {
        $downside = 1;
        $swingTime = (int) ($time[max(0, $i - $sensitivity)] ?? $time[$i]);
        if ($showSwingLowLabels && $labelPrefix === '') {
          $swingText = ($dnAxis > 0.0 && $botSwing < $dnAxis) ? 'LL' : 'HL';
          $swingLabelsOut[] = [
            'time' => $swingTime,
            'price' => (float) $botSwing,
            'text' => $swingText,
            'color' => $bullColor,
            'background' => 'transparent',
            'position' => 'below',
            'kind' => 'mxwll_swing',
          ];
        }
        $dnAxis = (float) $botSwing;
        $dnAxisTime = $swingTime;
      }

      if ($i === 0) {
        continue;
      }

      // crossover(close, upAxis) — пробой swing high.
      if ($upAxis > 0.0 && $close[$i - 1] <= $upAxis && $close[$i] > $upAxis && $upside !== 0) {
        $event = $moving < 0
          ? ($structureFilter !== 'BoS' ? $labelPrefix.'CHoCH' : '')
          : ($structureFilter !== 'CHoCH' ? $labelPrefix.'BoS' : '');

        if ($this->mxwllStructureEventAllowed($structureFilter, $event, $internalPass)) {
          $this->mxwllAppendStructureEvent(
            $structureEventsOut,
            $upAxisTime,
            (int) $time[$i],
            $upAxis,
            $event,
            $bullColor,
            true
          );
        }
        $upside = 0;
        $moving = 1;
      }

      // crossunder(close, dnAxis) — пробой swing low.
      if ($dnAxis > 0.0 && $close[$i - 1] >= $dnAxis && $close[$i] < $dnAxis && $downside !== 0) {
        $event = $moving > 0
          ? ($structureFilter !== 'BoS' ? $labelPrefix.'CHoCH' : '')
          : ($structureFilter !== 'CHoCH' ? $labelPrefix.'BoS' : '');

        if ($this->mxwllStructureEventAllowed($structureFilter, $event, $internalPass)) {
          $this->mxwllAppendStructureEvent(
            $structureEventsOut,
            $dnAxisTime,
            (int) $time[$i],
            $dnAxis,
            $event,
            $bearColor,
            false
          );
        }
        $downside = 0;
        $moving = -1;
      }

      // Состояние external bigData для Auto Fibs (updateMain в Pine).
      if ($labelPrefix === '' && $externalStateOut !== null) {
        $externalStateOut['up_axis'] = $upAxis;
        $externalStateOut['up_axis_time'] = $upAxisTime;
        $externalStateOut['dn_axis'] = $dnAxis;
        $externalStateOut['dn_axis_time'] = $dnAxisTime;
        $externalStateOut['moving'] = $moving;
      }
    }
  }

  /**
   * Pivot-логика calculatePivots() из Pine (highArr/lowArr с unshift → хронологический индекс i).
   *
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @return array{0: float, 1: float} [topSwing, botSwing]
   */
  private function mxwllPivotsAt(array $high, array $low, int $i, int $lengthCalc, int &$intraCalc): array
  {
    if ($i < $lengthCalc + 1) {
      return [0.0, 0.0];
    }

    $from = $i - $lengthCalc + 1;
    $segmentHigh = array_slice($high, $from, $lengthCalc);
    $segmentLow = array_slice($low, $from, $lengthCalc);
    $up = max($segmentHigh);
    $dn = min($segmentLow);
    $cHi = $high[$i - $lengthCalc];
    $cLo = $low[$i - $lengthCalc];
    $prevIntra = $intraCalc;

    if ($cHi > $up) {
      $intraCalc = 0;
    } elseif ($cLo < $dn) {
      $intraCalc = 1;
    }

    $topSwing = ($intraCalc === 0 && $prevIntra !== 0) ? (float) $cHi : 0.0;
    $botSwing = ($intraCalc === 1 && $prevIntra !== 1) ? (float) $cLo : 0.0;

    return [$topSwing, $botSwing];
  }

  /** Фильтр extStru/intStru — в Pine порядок аргументов str.contains разный для external/internal. */
  private function mxwllStructureEventAllowed(string $filter, string $eventText, bool $internalPass): bool
  {
    if ($eventText === '') {
      return false;
    }
    if ($filter === 'All') {
      return true;
    }

    // External: str.contains(extStru, str). Internal: str.contains(str, intStru).
    return $internalPass
      ? str_contains($eventText, $filter)
      : str_contains($filter, $eventText);
  }

  /**
   * drawChar() из Pine: горизонтальная пунктирная линия + подпись в середине (атомарная пара).
   *
   * @param  list<array{line: array<string, mixed>, label: array<string, mixed>}>  $structureEventsOut
   */
  private function mxwllAppendStructureEvent(
    array &$structureEventsOut,
    int $axisTime,
    int $breakTime,
    float $price,
    string $text,
    string $color,
    bool $bullishBreak
  ): void {
    $line = [
      'time_start' => $axisTime,
      'time_end' => $breakTime,
      'price_start' => $price,
      'price_end' => $price,
      'color' => $color,
      'style' => 'Dashed',
      'width' => 1,
      'kind' => 'mxwll_structure',
    ];

    // Подпись на свече пробоя (bar_index в Pine), а не в mid — иначе при зуме вправо
    // метка уходит за левый краь viewport, а пунктир остаётся видимым.
    $label = [
      'time' => $breakTime,
      'time_end' => $breakTime,
      'price' => $price,
      'text' => $text,
      'color' => $color,
      'background' => 'transparent',
      'position' => $bullishBreak ? 'below' : 'above',
      'kind' => 'mxwll_structure',
    ];

    $structureEventsOut[] = ['line' => $line, 'label' => $label];
  }

  /**
   * Rolling High/Low уровни 240/1440 баров (tfDraw на M1 в Pine).
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return array{lines:list<mixed>, labels:list<mixed>}
   */
  private function computeMxwllRollingLevels(array $rows, array $p): array
  {
    $n = count($rows);
    if ($n < 2) {
      return ['lines' => [], 'labels' => []];
    }

    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $lastTime = (int) $time[$n - 1];
    $levelColor = (string) ($p['color_mxwll_level'] ?? '#00ffff');
    $lines = [];
    $labels = [];

    $windows = [];
    if ((bool) ($p['mxwll_show_4h_levels'] ?? true)) {
      $windows[] = ['bars' => 240, 'tf' => '240', 'show_labels' => (bool) ($p['mxwll_show_4h_labels'] ?? true)];
    }
    if ((bool) ($p['mxwll_show_1d_levels'] ?? true)) {
      $windows[] = ['bars' => 1440, 'tf' => '1D', 'show_labels' => (bool) ($p['mxwll_show_1d_labels'] ?? true)];
    }

    foreach ($windows as $window) {
      $startIdx = max(0, $n - (int) $window['bars']);
      $hi = -INF;
      $lo = INF;
      $hiTime = $lastTime;
      $loTime = $lastTime;
      for ($i = $startIdx; $i < $n; $i++) {
        if ($high[$i] >= $hi) {
          $hi = $high[$i];
          $hiTime = (int) $time[$i];
        }
        if ($low[$i] <= $lo) {
          $lo = $low[$i];
          $loTime = (int) $time[$i];
        }
      }
      if (! is_finite($hi) || ! is_finite($lo)) {
        continue;
      }

      $tf = (string) $window['tf'];
      $lines[] = [
        'time_start' => $hiTime,
        'time_end' => $lastTime,
        'price_start' => $hi,
        'price_end' => $hi,
        'color' => $levelColor,
        'style' => 'Solid',
        'width' => 1,
        'kind' => 'mxwll_level',
      ];
      $lines[] = [
        'time_start' => $loTime,
        'time_end' => $lastTime,
        'price_start' => $lo,
        'price_end' => $lo,
        'color' => $levelColor,
        'style' => 'Solid',
        'width' => 1,
        'kind' => 'mxwll_level',
      ];

      if ($window['show_labels']) {
        $labels[] = [
          'time' => $lastTime,
          'price' => $hi,
          'text' => $tf.'H',
          'color' => $levelColor,
          'background' => 'transparent',
          'position' => 'left',
          'kind' => 'mxwll_level',
        ];
        $labels[] = [
          'time' => $lastTime,
          'price' => $lo,
          'text' => $tf.'L',
          'color' => $levelColor,
          'background' => 'transparent',
          'position' => 'left',
          'kind' => 'mxwll_level',
        ];
      }
    }

    return ['lines' => $lines, 'labels' => $labels];
  }

  /**
   * Auto Fibs + main zigzag leg (updateMain/drawFibs в Pine, только на последнем баре).
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @param  array<string, mixed>  $extState
   * @return array{lines:list<mixed>, labels:list<mixed>}
   */
  private function computeMxwllAutoFibs(array $rows, array $p, array $extState): array
  {
    if (! ((bool) ($p['mxwll_show_fibs'] ?? true) || (bool) ($p['mxwll_show_main_leg'] ?? true))) {
      return ['lines' => [], 'labels' => []];
    }

    $n = count($rows);
    if ($n < 30 || $extState === []) {
      return ['lines' => [], 'labels' => []];
    }

    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $lastTime = (int) $time[$n - 1];
    $periodSec = max(60, (int) $time[$n - 1] - (int) $time[$n - 2]);
    $extendSec = max(0, (int) ($p['mxwll_fib_extend_bars'] ?? 5)) * $periodSec;
    $fibEndTime = $lastTime + $extendSec;
    $bullColor = (string) ($p['color_mxwll_bull'] ?? '#14D990');
    $bearColor = (string) ($p['color_mxwll_bear'] ?? '#F24968');

    $upAxis = (float) ($extState['up_axis'] ?? 0.0);
    $upAxisTime = (int) ($extState['up_axis_time'] ?? 0);
    $dnAxis = (float) ($extState['dn_axis'] ?? 0.0);
    $dnAxisTime = (int) ($extState['dn_axis_time'] ?? 0);
    if ($upAxis <= 0.0 || $dnAxis <= 0.0) {
      return ['lines' => [], 'labels' => []];
    }

    $fibSens = 25;
    $counterFibs = 0;
    $intraFib = 0;
    for ($i = max($fibSens + 1, $n - 120); $i < $n; $i++) {
      [$topSwing, $botSwing] = $this->mxwllPivotsAt($high, $low, $i, $fibSens, $intraFib);
      if ($topSwing > 0.0) {
        $counterFibs = 1;
      }
      if ($botSwing > 0.0) {
        $counterFibs = -1;
      }
    }

    $x1 = 0;
    $y1 = 0.0;
    $x2 = 0;
    $y2 = 0.0;

    if ($counterFibs === 1) {
      $x1 = $upAxisTime;
      $y1 = $upAxis;
      $dnIdx = $this->timeIndexAtOrBefore($time, $dnAxisTime);
      $y2 = INF;
      for ($i = $dnIdx; $i < $n; $i++) {
        if ($low[$i] <= $y2) {
          $y2 = $low[$i];
          $x2 = (int) $time[$i];
        }
      }
    } elseif ($counterFibs === -1) {
      $x1 = $dnAxisTime;
      $y1 = $dnAxis;
      $upIdx = $this->timeIndexAtOrBefore($time, $upAxisTime);
      $y2 = -INF;
      for ($i = $upIdx; $i < $n; $i++) {
        if ($high[$i] >= $y2) {
          $y2 = $high[$i];
          $x2 = (int) $time[$i];
        }
      }
    } else {
      return ['lines' => [], 'labels' => []];
    }

    if (! is_finite($y2) || $x2 <= 0) {
      return ['lines' => [], 'labels' => []];
    }

    if ($x2 < $x1) {
      [$x1, $x2] = [$x2, $x1];
      [$y1, $y2] = [$y2, $y1];
    }

    $legColor = $y2 < $y1 ? $bearColor : $bullColor;
    $lines = [];
    $labels = [];

    if ((bool) ($p['mxwll_show_main_leg'] ?? true)) {
      $lines[] = [
        'time_start' => $x1,
        'time_end' => $x2,
        'price_start' => $y1,
        'price_end' => $y2,
        'color' => $legColor,
        'style' => 'Dashed',
        'width' => 2,
        'kind' => 'mxwll_main_leg',
      ];
    }

    if ((bool) ($p['mxwll_show_fibs'] ?? true)) {
      $sub = $y1 - $y2;
      $fibLevels = self::defaultMxwllFibLevels();
      foreach ($fibLevels as $fib) {
        if (! ($fib['show'] ?? true)) {
          continue;
        }
        $ratio = (float) ($fib['ratio'] ?? 0.0);
        $price = $sub * $ratio + $y2;
        $col = (string) ($fib['color'] ?? '#a0a5b9');
        $lines[] = [
          'time_start' => $x2,
          'time_end' => $fibEndTime,
          'price_start' => $price,
          'price_end' => $price,
          'color' => $col,
          'style' => 'Solid',
          'width' => 1,
          'kind' => 'mxwll_fib',
        ];
        $labels[] = [
          'time' => $fibEndTime,
          'price' => $price,
          'text' => rtrim(rtrim(sprintf('%.3f', $ratio), '0'), '.'),
          'color' => $col,
          'background' => 'transparent',
          'position' => 'left',
          'kind' => 'mxwll_fib',
        ];
      }
    }

    return ['lines' => $lines, 'labels' => $labels];
  }

  /**
   * Фон торговых сессий (NY / Asia / London) — America/New_York, как bgcolor в Pine.
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return array{areas:list<mixed>}
   */
  private function computeSessions(array $rows, array $p): array
  {
    $n = count($rows);
    if ($n < 2) {
      return ['areas' => []];
    }

    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $chartHigh = max($high);
    $chartLow = min($low);
    $trans = max(0, min(100, (int) ($p['sessions_transparency'] ?? 98)));
    $alpha = round((100 - $trans) / 100, 3);
    $colors = [
      'ny' => (string) ($p['color_session_ny'] ?? '#f24968'),
      'asia' => (string) ($p['color_session_asia'] ?? '#14D990'),
      'london' => (string) ($p['color_session_london'] ?? '#F2B807'),
    ];

    $areas = [];
    $segStart = 0;
    $segSession = $this->nySessionAt((int) $time[0]);

    for ($i = 1; $i < $n; $i++) {
      $session = $this->nySessionAt((int) $time[$i]);
      if ($session !== $segSession) {
        if ($segSession !== null) {
          $areas[] = [
            'time_start' => (int) $time[$segStart],
            'time_end' => (int) $time[$i - 1],
            'price_high' => $chartHigh,
            'price_low' => $chartLow,
            'fill' => $this->colorWithAlpha($colors[$segSession], $alpha),
            'border' => 'none',
            'kind' => 'session_'.$segSession,
          ];
        }
        $segStart = $i;
        $segSession = $session;
      }
    }

    if ($segSession !== null) {
      $areas[] = [
        'time_start' => (int) $time[$segStart],
        'time_end' => (int) $time[$n - 1],
        'price_high' => $chartHigh,
        'price_low' => $chartLow,
        'fill' => $this->colorWithAlpha($colors[$segSession], $alpha),
        'border' => 'none',
        'kind' => 'session_'.$segSession,
      ];
    }

    return ['areas' => $areas];
  }

  /** Индекс последней свечи с time <= $target. */
  private function timeIndexAtOrBefore(array $time, int $target): int
  {
    $idx = 0;
    foreach ($time as $i => $t) {
      if ((int) $t <= $target) {
        $idx = (int) $i;
      }
    }

    return $idx;
  }

  /** NY / Asia / London / null (dead zone). */
  private function nySessionAt(int $unixTime): ?string
  {
    $dt = (new \DateTimeImmutable('@'.$unixTime))->setTimezone(new \DateTimeZone('America/New_York'));
    $mins = ((int) $dt->format('G')) * 60 + (int) $dt->format('i');

    if ($mins >= (9 * 60 + 30) && $mins < (16 * 60)) {
      return 'ny';
    }
    if ($mins >= (20 * 60) || $mins < (2 * 60)) {
      return 'asia';
    }
    if ($mins >= (3 * 60) && $mins < (11 * 60 + 30)) {
      return 'london';
    }

    return null;
  }

  private function colorWithAlpha(string $hex, float $alpha): string
  {
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
      $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (strlen($hex) !== 6) {
      return 'rgba(148,163,184,'.$alpha.')';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    return sprintf('rgba(%d,%d,%d,%.3f)', $r, $g, $b, max(0.0, min(1.0, $alpha)));
  }

  /**
   * LuxAlgo FVG: detectFVG(), зоны, mitigation и unmitigated levels (3.txt).
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return array{lines:list<mixed>, areas:list<mixed>}
   */
  private function computeLuxFvg(array $rows, array $p): array
  {
    $n = count($rows);
    if ($n < 3) {
      return ['lines' => [], 'areas' => []];
    }

    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $close = array_map('floatval', array_column($rows, 'close'));
    $lastTime = (int) $time[$n - 1];
    $periodSec = max(60, (int) $time[$n - 1] - (int) $time[$n - 2]);
    $extendBars = max(0, (int) ($p['lux_fvg_extend_bars'] ?? 20));
    $dynamic = (bool) ($p['lux_fvg_dynamic'] ?? false);
    $mitigationLevels = (bool) ($p['lux_fvg_mitigation_levels'] ?? false);
    $showLast = max(0, (int) ($p['lux_fvg_show_last'] ?? 0));
    $thresholdAuto = (bool) ($p['lux_fvg_threshold_auto'] ?? false);
    $thresholdPct = max(0.0, min(100.0, (float) ($p['lux_fvg_threshold_pct'] ?? 0.0))) / 100.0;
    $bullFill = $this->colorWithAlpha(
      (string) ($p['color_lux_fvg_bull'] ?? '#089981'),
      (100 - max(0, min(100, (int) ($p['color_lux_fvg_bull_alpha'] ?? 70)))) / 100
    );
    $bearFill = $this->colorWithAlpha(
      (string) ($p['color_lux_fvg_bear'] ?? '#f23645'),
      (100 - max(0, min(100, (int) ($p['color_lux_fvg_bear_alpha'] ?? 70)))) / 100
    );
    $bullSolid = (string) ($p['color_lux_fvg_bull'] ?? '#089981');
    $bearSolid = (string) ($p['color_lux_fvg_bear'] ?? '#f23645');

    /** @var list<array{max:float,min:float,isbull:bool,t:int}> $records */
    $records = [];
    /** @var list<array{time_start:int,time_end:int,price_high:float,price_low:float,fill:string,border:string,kind:string}> $areas */
    $areas = [];
    $lines = [];
    $lastFvgTime = 0;
    $cumRangeRatio = 0.0;
    $maxBullFvg = null;
    $minBullFvg = null;
    $maxBearFvg = null;
    $minBearFvg = null;
    $dynamicBullStart = 0;
    $dynamicBearStart = 0;

    for ($i = 2; $i < $n; $i++) {
      if ($low[$i] > 0.0) {
        $cumRangeRatio += ($high[$i] - $low[$i]) / $low[$i];
      }
      $threshold = $thresholdAuto && $i > 0 ? ($cumRangeRatio / $i) : $thresholdPct;

      $bullFvg = $low[$i] > $high[$i - 2]
        && $close[$i - 1] > $high[$i - 2]
        && $high[$i - 2] > 0.0
        && (($low[$i] - $high[$i - 2]) / $high[$i - 2]) > $threshold;
      $bearFvg = $high[$i] < $low[$i - 2]
        && $close[$i - 1] < $low[$i - 2]
        && $high[$i] > 0.0
        && (($low[$i - 2] - $high[$i]) / $high[$i]) > $threshold;

      $barTime = (int) $time[$i];

      if ($bullFvg && $barTime !== $lastFvgTime) {
        $new = ['max' => $low[$i], 'min' => $high[$i - 2], 'isbull' => true, 't' => $barTime];
        if ($dynamic) {
          $maxBullFvg = $new['max'];
          $minBullFvg = $new['min'];
          $dynamicBullStart = (int) $time[$i - 2];
        } else {
          $areas[] = [
            'time_start' => (int) $time[$i - 2],
            'time_end' => $barTime + $extendBars * $periodSec,
            'price_high' => $new['max'],
            'price_low' => $new['min'],
            'fill' => $bullFill,
            'border' => 'none',
            'kind' => 'lux_fvg_bull',
          ];
        }
        array_unshift($records, $new);
        $lastFvgTime = $barTime;
      } elseif ($dynamic && $maxBullFvg !== null && $minBullFvg !== null) {
        $maxBullFvg = max(min($close[$i], $maxBullFvg), $minBullFvg);
      }

      if ($bearFvg && $barTime !== $lastFvgTime) {
        $new = ['max' => $low[$i - 2], 'min' => $high[$i], 'isbull' => false, 't' => $barTime];
        if ($dynamic) {
          $maxBearFvg = $new['max'];
          $minBearFvg = $new['min'];
          $dynamicBearStart = (int) $time[$i - 2];
        } else {
          $areas[] = [
            'time_start' => (int) $time[$i - 2],
            'time_end' => $barTime + $extendBars * $periodSec,
            'price_high' => $new['max'],
            'price_low' => $new['min'],
            'fill' => $bearFill,
            'border' => 'none',
            'kind' => 'lux_fvg_bear',
          ];
        }
        array_unshift($records, $new);
        $lastFvgTime = $barTime;
      } elseif ($dynamic && $maxBearFvg !== null && $minBearFvg !== null) {
        $minBearFvg = min(max($close[$i], $minBearFvg), $maxBearFvg);
      }

      for ($ri = count($records) - 1; $ri >= 0; $ri--) {
        $rec = $records[$ri];
        $mitigated = ($rec['isbull'] && $close[$i] < $rec['min'])
          || (! $rec['isbull'] && $close[$i] > $rec['max']);
        if (! $mitigated) {
          continue;
        }
        if ($mitigationLevels) {
          $level = $rec['isbull'] ? $rec['min'] : $rec['max'];
          $lines[] = [
            'time_start' => (int) $rec['t'],
            'time_end' => $barTime,
            'price_start' => $level,
            'price_end' => $level,
            'color' => $rec['isbull'] ? $bullSolid : $bearSolid,
            'style' => 'Dashed',
            'width' => 1,
            'kind' => 'lux_fvg_mitigation',
          ];
        }
        if (! $dynamic) {
          $this->luxFvgRemoveAreaAt($areas, $ri);
        }
        array_splice($records, $ri, 1);
      }
    }

    if ($dynamic) {
      if ($maxBullFvg !== null && $minBullFvg !== null && $dynamicBullStart > 0) {
        $areas[] = [
          'time_start' => $dynamicBullStart,
          'time_end' => $lastTime,
          'price_high' => (float) $maxBullFvg,
          'price_low' => (float) $minBullFvg,
          'fill' => $bullFill,
          'border' => 'none',
          'kind' => 'lux_fvg_bull_dynamic',
        ];
      }
      if ($maxBearFvg !== null && $minBearFvg !== null && $dynamicBearStart > 0) {
        $areas[] = [
          'time_start' => $dynamicBearStart,
          'time_end' => $lastTime,
          'price_high' => (float) $maxBearFvg,
          'price_low' => (float) $minBearFvg,
          'fill' => $bearFill,
          'border' => 'none',
          'kind' => 'lux_fvg_bear_dynamic',
        ];
      }
    }

    if ($showLast > 0 && $records !== []) {
      $limit = min($showLast, count($records));
      for ($i = 0; $i < $limit; $i++) {
        $rec = $records[$i];
        $level = $rec['isbull'] ? $rec['min'] : $rec['max'];
        $lines[] = [
          'time_start' => (int) $rec['t'],
          'time_end' => $lastTime,
          'price_start' => $level,
          'price_end' => $level,
          'color' => $rec['isbull'] ? $bullSolid : $bearSolid,
          'style' => 'Solid',
          'width' => 1,
          'kind' => 'lux_fvg_unmitigated',
        ];
      }
    }

    return ['lines' => $lines, 'areas' => $areas];
  }

  /** Удаляет i-ю FVG-зону (records и areas синхронны через unshift). */
  private function luxFvgRemoveAreaAt(array &$areas, int $recordIndex): void
  {
    if ($recordIndex >= 0 && $recordIndex < count($areas)) {
      array_splice($areas, $recordIndex, 1);
    }
  }

  /**
   * LuxAlgo Order Blocks: volume pivot + OS + mitigation (3.txt).
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return array{lines:list<mixed>, areas:list<mixed>}
   */
  private function computeLuxOrderBlocks(array $rows, array $p): array
  {
    $n = count($rows);
    $len = max(1, (int) ($p['lux_ob_length'] ?? 5));
    if ($n < ($len * 2) + 1) {
      return ['lines' => [], 'areas' => []];
    }

    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $close = array_map('floatval', array_column($rows, 'close'));
    $volume = array_map(static fn (array $r): float => (float) ($r['volume'] ?? 0.0), $rows);
    $lastTime = (int) $time[$n - 1];
    $mitigation = (string) ($p['lux_ob_mitigation'] ?? 'Wick');
    $bullCount = max(1, (int) ($p['lux_ob_bull_count'] ?? 3));
    $bearCount = max(1, (int) ($p['lux_ob_bear_count'] ?? 3));
    $lineStyle = $this->luxObLineStyle((string) ($p['lux_ob_avg_line_style'] ?? 'dotted'));
    $lineWidth = max(1, (int) ($p['lux_ob_avg_line_width'] ?? 1));

    $bullBg = $this->colorWithAlpha(
      (string) ($p['color_lux_ob_bull_bg'] ?? '#089981'),
      (100 - max(0, min(100, (int) ($p['color_lux_ob_bull_bg_alpha'] ?? 80)))) / 100
    );
    $bearBg = $this->colorWithAlpha(
      (string) ($p['color_lux_ob_bear_bg'] ?? '#f23645'),
      (100 - max(0, min(100, (int) ($p['color_lux_ob_bear_bg_alpha'] ?? 80)))) / 100
    );
    $bullBorder = $this->colorWithAlpha(
      (string) ($p['color_lux_ob_bull_border'] ?? '#089981'),
      0.3
    );
    $bearBorder = $this->colorWithAlpha(
      (string) ($p['color_lux_ob_bear_border'] ?? '#f23645'),
      0.3
    );
    $bullAvgCol = $this->colorWithAlpha(
      (string) ($p['color_lux_ob_bull_avg'] ?? '#9598a1'),
      (100 - max(0, min(100, (int) ($p['color_lux_ob_bull_avg_alpha'] ?? 37)))) / 100
    );
    $bearAvgCol = $this->colorWithAlpha(
      (string) ($p['color_lux_ob_bear_avg'] ?? '#9598a1'),
      (100 - max(0, min(100, (int) ($p['color_lux_ob_bear_avg_alpha'] ?? 37)))) / 100
    );

    $bullTop = [];
    $bullBtm = [];
    $bullAvg = [];
    $bullLeft = [];
    $bearTop = [];
    $bearBtm = [];
    $bearAvg = [];
    $bearLeft = [];
    $os = 0;

    for ($i = 0; $i < $n; $i++) {
      $upper = $this->seriesHighest($high, $i, $len);
      $lower = $this->seriesLowest($low, $i, $len);
      $targetBull = $mitigation === 'Close'
        ? $this->seriesLowest($close, $i, $len)
        : $lower;
      $targetBear = $mitigation === 'Close'
        ? $this->seriesHighest($close, $i, $len)
        : $upper;

      if ($i >= $len) {
        if ($high[$i - $len] > $upper) {
          $os = 0;
        } elseif ($low[$i - $len] < $lower) {
          $os = 1;
        }
      }

      $phvDetected = $i >= (2 * $len) && $this->luxPivotHighVolume($volume, $i, $len);

      if ($phvDetected && $os === 1 && $i >= $len) {
        $idx = $i - $len;
        $top = ($high[$idx] + $low[$idx]) / 2.0;
        $btm = $low[$idx];
        array_unshift($bullTop, $top);
        array_unshift($bullBtm, $btm);
        array_unshift($bullAvg, ($top + $btm) / 2.0);
        array_unshift($bullLeft, (int) $time[$idx]);
      }

      if ($phvDetected && $os === 0 && $i >= $len) {
        $idx = $i - $len;
        $top = $high[$idx];
        $btm = ($high[$idx] + $low[$idx]) / 2.0;
        array_unshift($bearTop, $top);
        array_unshift($bearBtm, $btm);
        array_unshift($bearAvg, ($top + $btm) / 2.0);
        array_unshift($bearLeft, (int) $time[$idx]);
      }

      $this->luxObRemoveMitigated($bullTop, $bullBtm, $bullLeft, $bullAvg, $targetBull, true);
      $this->luxObRemoveMitigated($bearTop, $bearBtm, $bearLeft, $bearAvg, $targetBear, false);
    }

    $areas = [];
    $lines = [];

    $this->luxObEmitBlocks($areas, $lines, $bullTop, $bullBtm, $bullLeft, $bullAvg, $bullCount, $lastTime, $bullBg, $bullBorder, $bullAvgCol, $lineStyle, $lineWidth, 'lux_ob_bull');
    $this->luxObEmitBlocks($areas, $lines, $bearTop, $bearBtm, $bearLeft, $bearAvg, $bearCount, $lastTime, $bearBg, $bearBorder, $bearAvgCol, $lineStyle, $lineWidth, 'lux_ob_bear');

    return ['lines' => $lines, 'areas' => $areas];
  }

  /** @param  list<float>  $series */
  private function seriesHighest(array $series, int $i, int $len): float
  {
    $start = max(0, $i - $len + 1);

    return max(array_slice($series, $start, $i - $start + 1));
  }

  /** @param  list<float>  $series */
  private function seriesLowest(array $series, int $i, int $len): float
  {
    $start = max(0, $i - $len + 1);

    return min(array_slice($series, $start, $i - $start + 1));
  }

  /** ta.pivothigh(volume, len, len) подтверждён на баре $i. */
  private function luxPivotHighVolume(array $volume, int $i, int $len): bool
  {
    $pivotIdx = $i - $len;
    if ($pivotIdx < $len || $pivotIdx + $len >= count($volume)) {
      return false;
    }
    $pivotVal = $volume[$pivotIdx];
    for ($j = $pivotIdx - $len; $j <= $pivotIdx + $len; $j++) {
      if ($volume[$j] > $pivotVal) {
        return false;
      }
    }

    return true;
  }

  /**
   * remove_mitigated() из Pine.
   *
   * @param  list<float>  $tops
   * @param  list<float>  $btms
   * @param  list<int>  $lefts
   * @param  list<float>  $avgs
   */
  private function luxObRemoveMitigated(array &$tops, array &$btms, array &$lefts, array &$avgs, float $target, bool $bull): void
  {
    for ($idx = count($tops) - 1; $idx >= 0; $idx--) {
      $level = $bull ? $btms[$idx] : $tops[$idx];
      if ($bull ? $target < $level : $target > $level) {
        array_splice($tops, $idx, 1);
        array_splice($btms, $idx, 1);
        array_splice($lefts, $idx, 1);
        array_splice($avgs, $idx, 1);
      }
    }
  }

  /**
   * set_order_blocks() на последнем баре — зоны + средняя линия вправо.
   *
   * @param  list<float>  $tops
   * @param  list<float>  $btms
   * @param  list<int>  $lefts
   * @param  list<float>  $avgs
   */
  private function luxObEmitBlocks(
    array &$areas,
    array &$lines,
    array $tops,
    array $btms,
    array $lefts,
    array $avgs,
    int $extLast,
    int $lastTime,
    string $bgFill,
    string $borderColor,
    string $avgColor,
    string $lineStyle,
    int $lineWidth,
    string $kind
  ): void {
    $count = min($extLast, count($tops));
    for ($i = 0; $i < $count; $i++) {
      $areas[] = [
        'time_start' => (int) $lefts[$i],
        'time_end' => $lastTime,
        'price_high' => (float) $tops[$i],
        'price_low' => (float) $btms[$i],
        'fill' => $bgFill,
        'border' => '1px solid '.$borderColor,
        'kind' => $kind,
      ];
      $lines[] = [
        'time_start' => (int) $lefts[$i],
        'time_end' => $lastTime,
        'price_start' => (float) $avgs[$i],
        'price_end' => (float) $avgs[$i],
        'color' => $avgColor,
        'style' => $lineStyle,
        'width' => $lineWidth,
        'kind' => $kind.'_avg',
      ];
    }
  }

  private function luxObLineStyle(string $style): string
  {
    return match (strtolower($style)) {
      'dashed', '----' => 'Dashed',
      'dotted', '···', '...' => 'Dotted',
      default => 'Solid',
    };
  }

  /** @param  list<int>  $allowed */
  private function normalizeMxwllSensitivity(int $value, array $allowed, int $default): int
  {
    return in_array($value, $allowed, true) ? $value : $default;
  }
}
