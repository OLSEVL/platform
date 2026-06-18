<?php

namespace App\Services\Indicators;

/**
 * Торговые сигналы SMC Flow для бота (без геометрии оверлея).
 *
 * Правило входа v1:
 * - триггер: свежий UT Buy/Sell на последней закрытой свече;
 * - фильтр: подтверждённый 3BR (^/¡ + confirm) в том же направлении за последние N баров;
 * - фильтр: цена в активной LuxAlgo OB зоне нужной стороны.
 */
class SmcFlowBotSignalAnalyzer
{
  /**
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @param  array<string, mixed>  $p  параметры SmcFlowIndicatorService
   * @return array{
   *   ut: array{side:?string,bar_index:?int,bar_time:?int,fresh:bool},
   *   three_br: array{bull_confirm_bar:?int,bull_confirm_time:?int,bear_confirm_bar:?int,bear_confirm_time:?int},
   *   ob: array{bull_zones:list<array{top:float,btm:float,left_time:int}>,bear_zones:list<array{top:float,btm:float,left_time:int}>,price:float},
   *   last_bar_index: int
   * }
   */
  public function analyze(array $rows, array $p): array
  {
    $n = count($rows);
    if ($n < 3) {
      return $this->emptyContext();
    }

    $lastIdx = $n - 1;
    $price = (float) $rows[$lastIdx]['close'];

    return [
      'ut' => $this->detectUtFlipOnBar($rows, $p, $lastIdx),
      'three_br' => $this->scanThreeBarReversalConfirms($rows, $p),
      'ob' => [
        'bull_zones' => $this->activeLuxObZones($rows, $p, true),
        'bear_zones' => $this->activeLuxObZones($rows, $p, false),
        'price' => $price,
        // Несколько последних close — для фильтра «касание OB» за N баров.
        'recent_closes' => array_map(
          static fn (array $row): float => (float) $row['close'],
          array_slice($rows, max(0, $n - 12))
        ),
      ],
      'last_bar_index' => $lastIdx,
    ];
  }

  /**
   * Проверяет готовность входа CALL/PUT по контексту analyze().
   *
   * @param  array<string, mixed>  $context
   * @param  array<string, mixed>  $options
   */
  public function matchesEntry(array $context, string $side, int $confirmMaxBars, array $options = []): bool
  {
    return $this->diagnoseEntry($context, $side, $confirmMaxBars, $options)['matches'];
  }

  /**
   * Диагностика фильтров входа (для explain UI и логов).
   *
   * @param  array<string, mixed>  $context
   * @param  array<string, mixed>  $options  smc_require_ob, smc_three_br_allow_pattern
   * @return array{
   *   matches: bool,
   *   ut_ok: bool,
   *   three_br_ok: bool,
   *   ob_ok: bool,
   *   three_br_bars_ago: ?int,
   *   three_br_source: ?string
   * }
   */
  public function diagnoseEntry(array $context, string $side, int $confirmMaxBars, array $options = []): array
  {
    $side = strtoupper($side);
    $requireOb = (bool) ($options['smc_require_ob'] ?? true);
    $allowPattern = (bool) ($options['smc_three_br_allow_pattern'] ?? true);
    $obTouchMaxBars = max(0, min(12, (int) ($options['smc_ob_touch_max_bars'] ?? 3)));
    $lastIdx = (int) ($context['last_bar_index'] ?? 0);

    $empty = [
      'matches' => false,
      'ut_ok' => false,
      'three_br_ok' => false,
      'ob_ok' => false,
      'three_br_bars_ago' => null,
      'three_br_source' => null,
    ];

    if (! in_array($side, ['CALL', 'PUT'], true)) {
      return $empty;
    }

    $ut = $context['ut'] ?? [];
    $utOk = ($ut['fresh'] ?? false) && ($ut['side'] ?? null) === $side;

    $threeBr = $context['three_br'] ?? [];
    $confirmBar = $side === 'CALL'
      ? ($threeBr['bull_confirm_bar'] ?? null)
      : ($threeBr['bear_confirm_bar'] ?? null);
    $patternBar = $side === 'CALL'
      ? ($threeBr['bull_pattern_bar'] ?? null)
      : ($threeBr['bear_pattern_bar'] ?? null);

    $threeBrOk = false;
    $threeBrBarsAgo = null;
    $threeBrSource = null;

    foreach ([
      ['bar' => $confirmBar, 'source' => 'confirm'],
      ['bar' => $allowPattern ? $patternBar : null, 'source' => 'pattern'],
    ] as $candidate) {
      $bar = $candidate['bar'];
      if ($bar === null) {
        continue;
      }
      $barsAgo = $lastIdx - (int) $bar;
      if ($barsAgo >= 0 && $barsAgo <= $confirmMaxBars) {
        $threeBrOk = true;
        $threeBrBarsAgo = $barsAgo;
        $threeBrSource = $candidate['source'];
        break;
      }
    }

    $obOk = $requireOb ? $this->priceInObZone($context, $side, $obTouchMaxBars) : true;

    return [
      'matches' => $utOk && $threeBrOk && $obOk,
      'ut_ok' => $utOk,
      'three_br_ok' => $threeBrOk,
      'ob_ok' => $obOk,
      'three_br_bars_ago' => $threeBrBarsAgo,
      'three_br_source' => $threeBrSource,
    ];
  }

  /**
   * @param  array<string, mixed>  $context
   */
  public function priceInObZone(array $context, string $side, int $touchMaxBars = 0): bool
  {
    $ob = $context['ob'] ?? [];
    $zones = $side === 'CALL'
      ? ($ob['bull_zones'] ?? [])
      : ($ob['bear_zones'] ?? []);
    if ($zones === []) {
      return false;
    }

    $prices = [];
    if (isset($ob['recent_closes']) && is_array($ob['recent_closes'])) {
      $prices = array_map('floatval', $ob['recent_closes']);
    }
    if ($prices === []) {
      $prices = [(float) ($ob['price'] ?? 0.0)];
    }
    $prices = array_slice($prices, -max(1, $touchMaxBars + 1));

    foreach ($prices as $price) {
      foreach ($zones as $zone) {
        $top = (float) ($zone['top'] ?? 0.0);
        $btm = (float) ($zone['btm'] ?? 0.0);
        if ($price >= min($btm, $top) && $price <= max($btm, $top)) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return array{side:?string,bar_index:?int,bar_time:?int,fresh:bool}
   */
  private function detectUtFlipOnBar(array $rows, array $p, int $barIndex): array
  {
    $empty = ['side' => null, 'bar_index' => null, 'bar_time' => null, 'fresh' => false];
    if (! (bool) ($p['ut_enabled'] ?? true) || $barIndex < 1) {
      return $empty;
    }

    $key = max(0.0001, (float) ($p['ut_key_value'] ?? 2));
    $period = max(1, (int) ($p['ut_atr_period'] ?? 6));

    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $close = array_map('floatval', array_column($rows, 'close'));
    $atr = $this->atrWilder($high, $low, $close, $period);

    $stops = array_fill(0, $barIndex + 1, 0.0);
    for ($i = 0; $i <= $barIndex; $i++) {
      $src = $close[$i];
      $prevSrc = $i > 0 ? $close[$i - 1] : $src;
      $prevStop = $i > 0 ? $stops[$i - 1] : 0.0;
      $nLoss = $key * (float) ($atr[$i] ?? 0.0);

      if ($src > $prevStop && $prevSrc > $prevStop) {
        $stops[$i] = max($prevStop, $src - $nLoss);
      } elseif ($src < $prevStop && $prevSrc < $prevStop) {
        $stops[$i] = min($prevStop, $src + $nLoss);
      } elseif ($src > $prevStop) {
        $stops[$i] = $src - $nLoss;
      } else {
        $stops[$i] = $src + $nLoss;
      }
    }

    $i = $barIndex;
    $src = $close[$i];
    $prevSrc = $close[$i - 1];
    $stop = $stops[$i];
    $prevStop = $stops[$i - 1];

    // Как в SmcFlowIndicatorService::computeUtBot — crossover close через ATR-stop (не требуем src > prevStop).
    $buy = $src > $stop && $prevSrc <= $prevStop;
    $sell = $src < $stop && $prevSrc >= $prevStop;

    if ($buy) {
      return [
        'side' => 'CALL',
        'bar_index' => $i,
        'bar_time' => (int) $rows[$i]['time'],
        'fresh' => true,
      ];
    }
    if ($sell) {
      return [
        'side' => 'PUT',
        'bar_index' => $i,
        'bar_time' => (int) $rows[$i]['time'],
        'fresh' => true,
      ];
    }

    return $empty;
  }

  /**
   * Последние индексы баров с подтверждённым 3BR (логика confirm из Pine).
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return array{
   *   bull_confirm_bar:?int,bull_confirm_time:?int,bear_confirm_bar:?int,bear_confirm_time:?int,
   *   bull_pattern_bar:?int,bull_pattern_time:?int,bear_pattern_bar:?int,bear_pattern_time:?int
   * }
   */
  private function scanThreeBarReversalConfirms(array $rows, array $p): array
  {
    $n = count($rows);
    $result = [
      'bull_confirm_bar' => null,
      'bull_confirm_time' => null,
      'bear_confirm_bar' => null,
      'bear_confirm_time' => null,
      'bull_pattern_bar' => null,
      'bull_pattern_time' => null,
      'bear_pattern_bar' => null,
      'bear_pattern_time' => null,
    ];
    if ($n < 3 || ! (bool) ($p['three_br_enabled'] ?? true)) {
      return $result;
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

    [$trendUp, $trendDown] = $this->threeBarTrendFilters(
      $close, $high, $low, $volume, $trendType, $trendFilter, $maType, $fastLen, $slowLen, $stAtr, $stFactor, $dcLen
    );

    $bullProcess = false;
    $bearProcess = false;
    $bullTop = 0.0;
    $bullBtm = 0.0;
    $bearBot = 0.0;

    for ($i = 0; $i < $n; $i++) {
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
        // Последний бар с паттерном ^ (до confirm «?») — для более частого совпадения с UT.
        $result['bull_pattern_bar'] = $i;
        $result['bull_pattern_time'] = (int) $time[$i];
      }

      if ($i >= 2 && $bearishRev && $this->threeBarPatternAllowed($patternType, false, $close[$i], $high[$i - 2], $low[$i - 2])) {
        $bearProcess = true;
        $bearBot = (float) $low[$i - 2];
        $result['bear_pattern_bar'] = $i;
        $result['bear_pattern_time'] = (int) $time[$i];
      }

      if ($bullProcess && $i >= 1) {
        $confirmed = $close[$i - 1] > $bullTop;
        $failed = $close[$i - 1] < $bullBtm || $bearishRev;
        if ($confirmed) {
          $result['bull_confirm_bar'] = $i - 1;
          $result['bull_confirm_time'] = (int) $time[$i - 1];
          $bullProcess = false;
        } elseif ($failed) {
          $bullProcess = false;
        }
      }

      if ($bearProcess && $i >= 1) {
        $confirmed = $close[$i - 1] < $bearBot;
        $failed = $close[$i - 1] > (float) max($high[$i - 1], $high[$i]) || $bullishRev;
        if ($confirmed) {
          $result['bear_confirm_bar'] = $i - 1;
          $result['bear_confirm_time'] = (int) $time[$i - 1];
          $bearProcess = false;
        } elseif ($failed) {
          $bearProcess = false;
        }
      }
    }

    return $result;
  }

  /**
   * Активные LuxAlgo OB на последнем баре (после mitigation).
   *
   * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
   * @return list<array{top:float,btm:float,left_time:int}>
   */
  private function activeLuxObZones(array $rows, array $p, bool $bull): array
  {
    if (! (bool) ($p['lux_ob_enabled'] ?? true)) {
      return [];
    }

    $n = count($rows);
    $len = max(1, (int) ($p['lux_ob_length'] ?? 5));
    if ($n < ($len * 2) + 1) {
      return [];
    }

    $time = array_column($rows, 'time');
    $high = array_map('floatval', array_column($rows, 'high'));
    $low = array_map('floatval', array_column($rows, 'low'));
    $close = array_map('floatval', array_column($rows, 'close'));
    $volume = array_map(static fn (array $r): float => (float) ($r['volume'] ?? 0.0), $rows);
    $mitigation = (string) ($p['lux_ob_mitigation'] ?? 'Wick');
    $maxZones = max(1, (int) ($bull ? ($p['lux_ob_bull_count'] ?? 3) : ($p['lux_ob_bear_count'] ?? 3)));

    $bullTop = [];
    $bullBtm = [];
    $bullLeft = [];
    $bearTop = [];
    $bearBtm = [];
    $bearLeft = [];
    $os = 0;

    for ($i = 0; $i < $n; $i++) {
      $upper = $this->seriesHighest($high, $i, $len);
      $lower = $this->seriesLowest($low, $i, $len);
      $targetBull = $mitigation === 'Close' ? $this->seriesLowest($close, $i, $len) : $lower;
      $targetBear = $mitigation === 'Close' ? $this->seriesHighest($close, $i, $len) : $upper;

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
        array_unshift($bullTop, ($high[$idx] + $low[$idx]) / 2.0);
        array_unshift($bullBtm, $low[$idx]);
        array_unshift($bullLeft, (int) $time[$idx]);
      }

      if ($phvDetected && $os === 0 && $i >= $len) {
        $idx = $i - $len;
        array_unshift($bearTop, $high[$idx]);
        array_unshift($bearBtm, ($high[$idx] + $low[$idx]) / 2.0);
        array_unshift($bearLeft, (int) $time[$idx]);
      }

      $this->luxObRemoveMitigated($bullTop, $bullBtm, $bullLeft, $targetBull, true);
      $this->luxObRemoveMitigated($bearTop, $bearBtm, $bearLeft, $targetBear, false);
    }

    $tops = $bull ? $bullTop : $bearTop;
    $btms = $bull ? $bullBtm : $bearBtm;
    $lefts = $bull ? $bullLeft : $bearLeft;
    $count = min($maxZones, count($tops));
    $zones = [];
    for ($i = 0; $i < $count; $i++) {
      $zones[] = [
        'top' => (float) $tops[$i],
        'btm' => (float) $btms[$i],
        'left_time' => (int) $lefts[$i],
      ];
    }

    return $zones;
  }

  /** @return array<string, mixed> */
  private function emptyContext(): array
  {
    return [
      'ut' => ['side' => null, 'bar_index' => null, 'bar_time' => null, 'fresh' => false],
      'three_br' => [
        'bull_confirm_bar' => null,
        'bull_confirm_time' => null,
        'bear_confirm_bar' => null,
        'bear_confirm_time' => null,
        'bull_pattern_bar' => null,
        'bull_pattern_time' => null,
        'bear_pattern_bar' => null,
        'bear_pattern_time' => null,
      ],
      'ob' => ['bull_zones' => [], 'bear_zones' => [], 'price' => 0.0],
      'last_bar_index' => -1,
    ];
  }

  /**
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

    $seed = array_sum(array_slice($tr, 0, $len)) / $len;
    $out[$len - 1] = $seed;
    for ($i = $len; $i < $n; $i++) {
      $out[$i] = (((float) $out[$i - 1]) * ($len - 1) + $tr[$i]) / $len;
    }

    return $out;
  }

  /**
   * @param  list<float>  $close
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @param  list<float>  $volume
   * @return array{0:list<bool>,1:list<bool>}
   */
  private function threeBarTrendFilters(
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
      $maFast = $this->movingAverage($close, $volume, $maType, $fastLen);
      $maSlow = $this->movingAverage($close, $volume, $maType, $slowLen);
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
      $dir = $this->superTrendDirection($high, $low, $close, $stAtr, $stFactor);
      for ($i = 0; $i < $n; $i++) {
        if ($trendFilter === 'Opposite') {
          $trendDown[$i] = $dir[$i] < 0;
          $trendUp[$i] = $dir[$i] > 0;
        } else {
          $trendDown[$i] = $dir[$i] > 0;
          $trendUp[$i] = $dir[$i] < 0;
        }
      }
    } elseif ($trendType === 'Donchian Channels') {
      $os = 0;
      for ($i = 0; $i < $n; $i++) {
        $upper = $this->rollingExtreme($close, $i, $dcLen, true);
        $lower = $this->rollingExtreme($close, $i, $dcLen, false);
        if ($i > 0) {
          $prevUpper = $this->rollingExtreme($close, $i - 1, $dcLen, true);
          $prevLower = $this->rollingExtreme($close, $i - 1, $dcLen, false);
          if ($upper !== null && $prevUpper !== null && $upper > $prevUpper) {
            $os = 1;
          } elseif ($lower !== null && $prevLower !== null && $lower < $prevLower) {
            $os = 0;
          }
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

  /**
   * @param  list<float>  $close
   * @param  list<float>  $volume
   * @return list<float|null>
   */
  private function movingAverage(array $close, array $volume, string $type, int $len): array
  {
    $n = count($close);
    $out = array_fill(0, $n, null);
    if ($len <= 0) {
      return $out;
    }

    for ($i = $len - 1; $i < $n; $i++) {
      $slice = array_slice($close, $i - $len + 1, $len);
      $out[$i] = match ($type) {
        'SMA' => array_sum($slice) / $len,
        'EMA' => $this->emaAt($close, $i, $len),
        'WMA' => $this->wmaAt($close, $i, $len),
        'VWMA' => $this->vwmaAt($close, $volume, $i, $len),
        default => $this->hmaAt($close, $i, $len),
      };
    }

    return $out;
  }

  private function emaAt(array $close, int $i, int $len): float
  {
    $k = 2.0 / ($len + 1);
    $ema = array_sum(array_slice($close, 0, $len)) / $len;
    for ($j = $len; $j <= $i; $j++) {
      $ema = ($close[$j] * $k) + ($ema * (1 - $k));
    }

    return $ema;
  }

  private function wmaAt(array $close, int $i, int $len): float
  {
    $sum = 0.0;
    $wSum = 0.0;
    for ($j = 0; $j < $len; $j++) {
      $w = $j + 1;
      $sum += $close[$i - $len + 1 + $j] * $w;
      $wSum += $w;
    }

    return $sum / max(1.0, $wSum);
  }

  private function vwmaAt(array $close, array $volume, int $i, int $len): float
  {
    $pv = 0.0;
    $v = 0.0;
    for ($j = 0; $j < $len; $j++) {
      $idx = $i - $len + 1 + $j;
      $pv += $close[$idx] * $volume[$idx];
      $v += $volume[$idx];
    }

    return $v > 0 ? $pv / $v : $close[$i];
  }

  private function hmaAt(array $close, int $i, int $len): float
  {
    $half = max(1, (int) floor($len / 2));
    $sqrt = max(1, (int) floor(sqrt($len)));
    $wmaHalf = $this->wmaAt($close, $i, $half);
    $wmaFull = $this->wmaAt($close, $i, $len);
    $raw = 2 * $wmaHalf - $wmaFull;

    $rawSeries = [];
    for ($j = max($len - 1, $i - $sqrt + 1); $j <= $i; $j++) {
      $wh = $this->wmaAt($close, $j, $half);
      $wf = $this->wmaAt($close, $j, $len);
      $rawSeries[] = 2 * $wh - $wf;
    }
    $sum = 0.0;
    $wSum = 0.0;
    foreach ($rawSeries as $idx => $val) {
      $w = $idx + 1;
      $sum += $val * $w;
      $wSum += $w;
    }

    return $wSum > 0 ? $sum / $wSum : $raw;
  }

  /**
   * @param  list<float>  $high
   * @param  list<float>  $low
   * @param  list<float>  $close
   * @return list<int>
   */
  private function superTrendDirection(array $high, array $low, array $close, int $atrPeriod, float $factor): array
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
        $lowerBand[$i] = ($basicLower > (float) $lowerBand[$i - 1] || $close[$i - 1] < (float) $lowerBand[$i - 1])
          ? $basicLower : (float) $lowerBand[$i - 1];
        $upperBand[$i] = ($basicUpper < (float) $upperBand[$i - 1] || $close[$i - 1] > (float) $upperBand[$i - 1])
          ? $basicUpper : (float) $upperBand[$i - 1];
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

    return $direction;
  }

  private function rollingExtreme(array $values, int $i, int $len, bool $high): ?float
  {
    if ($i < $len - 1) {
      return null;
    }
    $slice = array_slice($values, $i - $len + 1, $len);

    return $high ? max($slice) : min($slice);
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
   * @param  list<float>  $tops
   * @param  list<float>  $btms
   * @param  list<int>  $lefts
   */
  private function luxObRemoveMitigated(array &$tops, array &$btms, array &$lefts, float $target, bool $bull): void
  {
    for ($idx = count($tops) - 1; $idx >= 0; $idx--) {
      $level = $bull ? $btms[$idx] : $tops[$idx];
      if ($bull ? $target < $level : $target > $level) {
        array_splice($tops, $idx, 1);
        array_splice($btms, $idx, 1);
        array_splice($lefts, $idx, 1);
      }
    }
  }
}
