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
    'three_br_max_patterns' => 120,
    'color_three_br_bull' => '#2962ff',
    'color_three_br_bear' => '#ff9800',
    'label_size' => 'tiny',
  ];

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
      if ($hull['lines'] !== []) {
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

    return ['lines' => $lines];
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
    $showLevels = (bool) ($p['three_br_show_levels'] ?? true);
    $maxPatterns = max(10, min(500, (int) ($p['three_br_max_patterns'] ?? 120)));
    $bullColor = (string) ($p['color_three_br_bull'] ?? '#2962ff');
    $bearColor = (string) ($p['color_three_br_bear'] ?? '#ff9800');

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
    }

    $lines = [];
    $areas = [];
    $labels = [];
    for ($i = 2; $i < $n; $i++) {
      $bullBase = ($close[$i - 2] < $open[$i - 2])
        && ($low[$i - 1] < $low[$i - 2])
        && ($high[$i - 1] < $high[$i - 2])
        && ($close[$i - 1] < $open[$i - 1])
        && ($close[$i] > $open[$i])
        && ($high[$i] > $high[$i - 2]);
      $bearBase = ($close[$i - 2] > $open[$i - 2])
        && ($high[$i - 1] > $high[$i - 2])
        && ($low[$i - 1] > $low[$i - 2])
        && ($close[$i - 1] > $open[$i - 1])
        && ($close[$i] < $open[$i])
        && ($low[$i] < $low[$i - 2]);
      $bull = $bullBase && $trendUp[$i];
      $bear = $bearBase && $trendDown[$i];

      if ($bull) {
        $enhanced = $close[$i] > $high[$i - 2];
        $allowed = match ($patternType) {
          'Normal' => ! $enhanced,
          'Enhanced' => $enhanced,
          default => true,
        };
        if ($allowed) {
          $labels[] = [
            'time' => (int) $time[$i],
            'price' => (float) $low[$i],
            'text' => '3BR Buy',
            'color' => '#ffffff',
            'background' => $bullColor,
            'position' => 'below',
          ];
          if ($showLevels) {
            $top = (float) $high[$i - 2];
            $btm = (float) min($low[$i - 1], $low[$i]);
            $lines[] = [
              'time_start' => (int) $time[$i - 2],
              'time_end' => (int) $time[$i],
              'price_start' => $top,
              'price_end' => $top,
              'color' => $bullColor,
              'style' => 'Dotted',
              'width' => 1,
            ];
            $lines[] = [
              'time_start' => (int) $time[$i - 1],
              'time_end' => (int) $time[$i],
              'price_start' => $btm,
              'price_end' => $btm,
              'color' => $bullColor,
              'style' => 'Dotted',
              'width' => 1,
            ];
            // Полупрозрачная зона паттерна (аналог linefill в оригинальном Pine).
            $areas[] = [
              'time_start' => (int) $time[$i - 2],
              'time_end' => (int) $time[$i],
              'price_high' => $top,
              'price_low' => $btm,
              'fill' => $this->hexToRgba($bullColor, 0.24),
              'border' => 'none',
            ];
          }
        }
      }

      if ($bear) {
        $enhanced = $close[$i] < $low[$i - 2];
        $allowed = match ($patternType) {
          'Normal' => ! $enhanced,
          'Enhanced' => $enhanced,
          default => true,
        };
        if ($allowed) {
          $labels[] = [
            'time' => (int) $time[$i],
            'price' => (float) $high[$i],
            'text' => '3BR Sell',
            'color' => '#ffffff',
            'background' => $bearColor,
            'position' => 'above',
          ];
          if ($showLevels) {
            $bot = (float) $low[$i - 2];
            $top = (float) max($high[$i - 1], $high[$i]);
            $lines[] = [
              'time_start' => (int) $time[$i - 2],
              'time_end' => (int) $time[$i],
              'price_start' => $bot,
              'price_end' => $bot,
              'color' => $bearColor,
              'style' => 'Dotted',
              'width' => 1,
            ];
            $lines[] = [
              'time_start' => (int) $time[$i - 1],
              'time_end' => (int) $time[$i],
              'price_start' => $top,
              'price_end' => $top,
              'color' => $bearColor,
              'style' => 'Dotted',
              'width' => 1,
            ];
            $areas[] = [
              'time_start' => (int) $time[$i - 2],
              'time_end' => (int) $time[$i],
              'price_high' => $top,
              'price_low' => $bot,
              'fill' => $this->hexToRgba($bearColor, 0.24),
              'border' => 'none',
            ];
          }
        }
      }
    }

    // Срезаем хвост, чтобы не перегружать DOM старыми паттернами при масштабировании.
    if (count($labels) > $maxPatterns) {
      $labels = array_slice($labels, -$maxPatterns);
    }
    if (count($lines) > $maxPatterns * 2) {
      $lines = array_slice($lines, -$maxPatterns * 2);
    }
    if (count($areas) > $maxPatterns) {
      $areas = array_slice($areas, -$maxPatterns);
    }

    return ['lines' => $lines, 'areas' => $areas, 'labels' => $labels];
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
}
