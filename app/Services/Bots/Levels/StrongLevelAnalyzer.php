<?php

namespace App\Services\Bots\Levels;

use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;

/**
 * Агрегация уровней из Price Action DGT + SMC Flow, скоринг конфлюенса
 * и детерминированный вход на отскок (bounce) или пробой (breakout).
 */
class StrongLevelAnalyzer
{
    /** Веса ТФ/источника при расчёте силы кластера (0..1). */
    private const SOURCE_WEIGHTS = [
        'h1_swing' => 1.0,
        'm15_swing' => 0.85,
        'mxwll_1d' => 0.95,
        'mxwll_4h' => 0.80,
        'pa_dgt_sr' => 0.75,
        'vp_poc' => 0.70,
        'vp_vah' => 0.60,
        'vp_val' => 0.60,
        'rs_sr' => 0.70,
        'three_br_sr' => 0.65,
    ];

    public function __construct(
        private readonly PriceActionDgtIndicatorService $paDgt,
        private readonly SmcFlowIndicatorService $smcFlow,
    ) {}

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $m1Closed
     * @param  array<string,mixed>  $cfg
     * @param  array<string, list<array<string,mixed>>>  $mtf
     * @param  array<string,mixed>  $paParams
     * @param  array<string,mixed>  $smcParams
     * @return array<string,mixed>|null
     */
    public function analyzeEntry(
        array $m1Closed,
        ?array $partial,
        array $cfg,
        array $mtf,
        array $paParams,
        array $smcParams,
        ?float $payout = null,
    ): ?array {
        $minBars = max(80, (int) ($cfg['min_closed_bars'] ?? 200));
        if (count($m1Closed) < $minBars) {
            return null;
        }

        $entryMode = (string) ($cfg['entry_mode'] ?? 'both');
        if (! in_array($entryMode, ['bounce', 'breakout', 'both'], true)) {
            $entryMode = 'both';
        }
        $minStrength = max(40.0, min(95.0, (float) ($cfg['min_level_strength'] ?? 68.0)));
        // 0 = без верхней границы; иначе отсекаем «переоптимизированные» уровни (часто пила на 90+).
        $maxStrength = max(0.0, min(100.0, (float) ($cfg['max_level_strength'] ?? 0.0)));
        $minSources = max(1, min(6, (int) ($cfg['min_sources'] ?? 2)));
        $clusterAtrMult = max(0.05, min(1.0, (float) ($cfg['cluster_atr_mult_m5'] ?? 0.20)));
        $touchAtrMult = max(0.05, min(1.5, (float) ($cfg['touch_atr_mult_m1'] ?? 0.35)));
        $requireH1Alignment = (bool) ($cfg['require_h1_alignment'] ?? true);
        // Жёсткий запрет входов при боковом H1 (сильнее, чем h1_flat_strength_extra).
        $blockH1Flat = (bool) ($cfg['block_h1_flat'] ?? false);
        $bounceMinWickM5 = max(0.15, min(0.80, (float) ($cfg['bounce_min_wick_ratio_m5'] ?? 0.28)));
        // При false — для отскока достаточно M5 rejection, M1 может быть нейтральной (больше сигналов на OTC).
        $bounceRequireM1Confirm = (bool) ($cfg['bounce_require_m1_confirm'] ?? true);
        // Доп. порог силы уровня во флэте H1 (если block_h1_flat выкл.). До 40 — почти нет сигналов во флэте.
        $h1FlatStrengthExtra = max(0.0, min(40.0, (float) ($cfg['h1_flat_strength_extra'] ?? 5.0)));
        $breakoutRequireRetest = (bool) ($cfg['breakout_require_retest'] ?? true);
        $breakoutVolAboveSma = (bool) ($cfg['breakout_vol_above_sma'] ?? true);
        $expiryBounce = max(120, min(600, (int) ($cfg['expiry_sec_bounce'] ?? 180)));
        $expiryBreakout = max(120, min(600, (int) ($cfg['expiry_sec_breakout'] ?? 300)));

        $m5 = is_array($mtf['5'] ?? null) ? array_values($mtf['5']) : [];
        $m15 = is_array($mtf['15'] ?? null) ? array_values($mtf['15']) : [];
        $h1 = is_array($mtf['60'] ?? null) ? array_values($mtf['60']) : [];
        if (count($m5) < 20) {
            return null;
        }

        $lastM1 = $m1Closed[count($m1Closed) - 1];
        $price = (float) $lastM1['close'];
        $atrM1 = $this->atr($m1Closed, 14);
        $atrM5 = $this->atr($m5, 14);
        if ($atrM1 <= 0.0 || $atrM5 <= 0.0) {
            return null;
        }

        $clusterRadius = $clusterAtrMult * $atrM5;
        $touchRadius = $touchAtrMult * $atrM1;

        // SMC: только модули, связанные с уровнями (быстрее scan).
        $levelSmcParams = array_replace($smcParams, [
            'ut_enabled' => false,
            'hull_enabled' => false,
            'lux_fvg_enabled' => false,
            'lux_ob_enabled' => false,
            'structure_enabled' => false,
            'mxwll_enabled' => true,
            'rs_enabled' => true,
            'three_br_enabled' => true,
            'rs_sr_levels' => true,
        ]);

        $paPayload = $this->paDgt->compute($m1Closed, $paParams, 60);
        $smcPayload = $this->smcFlow->compute($m1Closed, $levelSmcParams);

        $rawLevels = $this->collectRawLevels($paPayload, $smcPayload, $m15, $h1);
        if ($rawLevels === []) {
            return null;
        }

        $clusters = $this->clusterLevels($rawLevels, $clusterRadius);
        $this->enrichClustersWithTouches($clusters, $m1Closed, $touchRadius);

        usort($clusters, static function (array $a, array $b): int {
            return ($b['strength'] <=> $a['strength']) ?: (abs($a['distance']) <=> abs($b['distance']));
        });

        $nearest = null;
        foreach ($clusters as $cluster) {
            if ($cluster['strength'] < $minStrength) {
                continue;
            }
            // Верхний потолок силы: слишком «идеальные» уровни с кучей касаний часто = пила.
            if ($maxStrength > 0.0 && $cluster['strength'] > $maxStrength) {
                continue;
            }
            if (count($cluster['sources']) < $minSources) {
                continue;
            }
            if ($cluster['distance'] > $touchRadius) {
                continue;
            }
            $nearest = $cluster;
            break;
        }

        if ($nearest === null) {
            return null;
        }

        $h1Bias = $this->detectH1Bias($h1);
        if ($blockH1Flat && $h1Bias === 'flat' && count($h1) >= 10) {
            return null;
        }
        if ($requireH1Alignment && $h1Bias === 'flat' && count($h1) >= 10) {
            // Во флэте H1 — только уровни чуть сильнее порога (настраивается h1_flat_strength_extra).
            if ($nearest['strength'] < $minStrength + $h1FlatStrengthExtra) {
                return null;
            }
        }

        $regime = $this->detectRegime($m5, $nearest, $touchRadius);
        if ($entryMode !== 'both' && $regime !== $entryMode) {
            return null;
        }

        $entry = null;
        if ($regime === 'bounce') {
            $entry = $this->tryBounceEntry(
                $m5,
                $m1Closed,
                $nearest,
                $h1Bias,
                $bounceMinWickM5,
                $requireH1Alignment,
                $bounceRequireM1Confirm
            );
        } elseif ($regime === 'breakout') {
            $entry = $this->tryBreakoutEntry(
                $m5,
                $m1Closed,
                $nearest,
                $h1Bias,
                $breakoutRequireRetest,
                $breakoutVolAboveSma,
                $touchRadius,
                $requireH1Alignment
            );
        }

        if ($entry === null) {
            return null;
        }

        [$side, $reasons, $setupType] = $entry;
        $expirySec = $regime === 'breakout' ? $expiryBreakout : $expiryBounce;
        $confidence = $this->scoreConfidence($nearest, $regime, $h1Bias);

        return [
            'side' => $side,
            'confidence' => $confidence,
            'expiry_sec' => $expirySec,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'strong_level: %s @ %.5f strength=%.1f sources=%d regime=%s h1=%s conf=%.2f',
                $side,
                $nearest['price'],
                $nearest['strength'],
                count($nearest['sources']),
                $regime,
                $h1Bias,
                $confidence
            ),
            'features' => [
                'level_price' => round($nearest['price'], 8),
                'level_strength' => round($nearest['strength'], 2),
                'level_kind' => $nearest['kind'],
                'level_sources' => $nearest['sources'],
                'level_touches' => $nearest['touches'],
                'setup_type' => $setupType,
                'entry_regime' => $regime,
                'h1_bias' => $h1Bias,
                'atr_m1' => round($atrM1, 8),
                'atr_m5' => round($atrM5, 8),
                'distance_to_level' => round($nearest['distance'], 8),
                'payout' => $payout,
                'partial_present' => $partial !== null,
            ],
            'decision_version' => 'strong_level_v1',
        ];
    }

    /**
     * @param  array<string,mixed>  $paPayload
     * @param  array<string,mixed>  $smcPayload
     * @param  list<array<string,mixed>>  $m15
     * @param  list<array<string,mixed>>  $h1
     * @return list<array{price:float,kind:string,source:string,weight:float}>
     */
    private function collectRawLevels(array $paPayload, array $smcPayload, array $m15, array $h1): array
    {
        $levels = [];

        foreach ($paPayload['lines'] ?? [] as $line) {
            if (! is_array($line) || ($line['source'] ?? '') !== 'sr') {
                continue;
            }
            $price = (float) ($line['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $levels[] = [
                'price' => $price,
                'kind' => 'neutral',
                'source' => 'pa_dgt_sr',
                'weight' => self::SOURCE_WEIGHTS['pa_dgt_sr'],
            ];
        }

        $vpMeta = is_array($paPayload['volume_profile']['meta'] ?? null)
            ? $paPayload['volume_profile']['meta']
            : [];
        foreach (['poc_price' => 'vp_poc', 'vah_price' => 'vp_vah', 'val_price' => 'vp_val'] as $key => $source) {
            if (isset($vpMeta[$key]) && is_numeric($vpMeta[$key])) {
                $levels[] = [
                    'price' => (float) $vpMeta[$key],
                    'kind' => 'neutral',
                    'source' => $source,
                    'weight' => self::SOURCE_WEIGHTS[$source],
                ];
            }
        }

        foreach ($smcPayload['lines'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $kind = (string) ($line['kind'] ?? '');
            $price = (float) ($line['price_end'] ?? $line['price_start'] ?? $line['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            if ($kind === 'mxwll_level') {
                $levels[] = [
                    'price' => $price,
                    'kind' => 'neutral',
                    'source' => str_contains((string) ($line['label'] ?? ''), '1D') ? 'mxwll_1d' : 'mxwll_4h',
                    'weight' => self::SOURCE_WEIGHTS[str_contains((string) ($line['label'] ?? ''), '1D') ? 'mxwll_1d' : 'mxwll_4h'],
                ];
            } elseif ($kind === 'rs_resistance') {
                $levels[] = ['price' => $price, 'kind' => 'resistance', 'source' => 'rs_sr', 'weight' => self::SOURCE_WEIGHTS['rs_sr']];
            } elseif ($kind === 'rs_support') {
                $levels[] = ['price' => $price, 'kind' => 'support', 'source' => 'rs_sr', 'weight' => self::SOURCE_WEIGHTS['rs_sr']];
            }
        }

        foreach ($smcPayload['labels'] ?? [] as $label) {
            if (! is_array($label) || ($label['kind'] ?? '') !== 'mxwll_level') {
                continue;
            }
            $text = (string) ($label['text'] ?? '');
            $price = (float) ($label['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $is1d = str_contains($text, '1D') || str_contains($text, '1440');
            $levels[] = [
                'price' => $price,
                'kind' => str_ends_with($text, 'H') ? 'resistance' : (str_ends_with($text, 'L') ? 'support' : 'neutral'),
                'source' => $is1d ? 'mxwll_1d' : 'mxwll_4h',
                'weight' => self::SOURCE_WEIGHTS[$is1d ? 'mxwll_1d' : 'mxwll_4h'],
            ];
        }

        foreach ($smcPayload['areas'] ?? [] as $area) {
            if (! is_array($area)) {
                continue;
            }
            $kind = (string) ($area['kind'] ?? '');
            if ($kind !== 'three_br_sr_bull' && $kind !== 'three_br_sr_bear') {
                continue;
            }
            $hi = (float) ($area['price_high'] ?? 0);
            $lo = (float) ($area['price_low'] ?? 0);
            if ($hi <= 0 || $lo <= 0) {
                continue;
            }
            $mid = ($hi + $lo) / 2.0;
            $levels[] = [
                'price' => $mid,
                'kind' => $kind === 'three_br_sr_bull' ? 'support' : 'resistance',
                'source' => 'three_br_sr',
                'weight' => self::SOURCE_WEIGHTS['three_br_sr'],
            ];
        }

        $levels = array_merge($levels, $this->swingLevels($h1, 'h1_swing'));
        $levels = array_merge($levels, $this->swingLevels($m15, 'm15_swing'));

        return $levels;
    }

    /**
     * Локальные swing high/low на старшем ТФ.
     *
     * @param  list<array<string,mixed>>  $bars
     * @return list<array{price:float,kind:string,source:string,weight:float}>
     */
    private function swingLevels(array $bars, string $source): array
    {
        $n = count($bars);
        if ($n < 5) {
            return [];
        }
        $weight = self::SOURCE_WEIGHTS[$source] ?? 0.7;
        $out = [];
        for ($i = 2; $i < $n - 2; $i++) {
            $hi = (float) $bars[$i]['high'];
            $lo = (float) $bars[$i]['low'];
            $isHigh = $hi >= (float) $bars[$i - 1]['high']
                && $hi >= (float) $bars[$i - 2]['high']
                && $hi >= (float) $bars[$i + 1]['high']
                && $hi >= (float) $bars[$i + 2]['high'];
            $isLow = $lo <= (float) $bars[$i - 1]['low']
                && $lo <= (float) $bars[$i - 2]['low']
                && $lo <= (float) $bars[$i + 1]['low']
                && $lo <= (float) $bars[$i + 2]['low'];
            if ($isHigh) {
                $out[] = ['price' => $hi, 'kind' => 'resistance', 'source' => $source, 'weight' => $weight];
            }
            if ($isLow) {
                $out[] = ['price' => $lo, 'kind' => 'support', 'source' => $source, 'weight' => $weight];
            }
        }

        // Берём только последние 6 свингов, чтобы не размывать кластеры.
        return array_slice($out, -6);
    }

    /**
     * @param  list<array{price:float,kind:string,source:string,weight:float}>  $raw
     * @return list<array<string,mixed>>
     */
    private function clusterLevels(array $raw, float $radius): array
    {
        if ($raw === []) {
            return [];
        }

        usort($raw, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);

        $clusters = [];
        foreach ($raw as $item) {
            $merged = false;
            foreach ($clusters as &$cluster) {
                if (abs($item['price'] - $cluster['price']) <= $radius) {
                    $n = $cluster['count'];
                    $cluster['price'] = (($cluster['price'] * $n) + $item['price']) / ($n + 1);
                    $cluster['count'] = $n + 1;
                    $cluster['sources'][$item['source']] = true;
                    $cluster['weight_sum'] += $item['weight'];
                    if ($item['kind'] === 'support') {
                        $cluster['support_votes']++;
                    } elseif ($item['kind'] === 'resistance') {
                        $cluster['resistance_votes']++;
                    }
                    $merged = true;
                    break;
                }
            }
            unset($cluster);

            if (! $merged) {
                $clusters[] = [
                    'price' => $item['price'],
                    'count' => 1,
                    'sources' => [$item['source'] => true],
                    'weight_sum' => $item['weight'],
                    'support_votes' => $item['kind'] === 'support' ? 1 : 0,
                    'resistance_votes' => $item['kind'] === 'resistance' ? 1 : 0,
                    'touches' => 0,
                    'distance' => 0.0,
                    'strength' => 0.0,
                    'kind' => 'neutral',
                ];
            }
        }

        foreach ($clusters as &$cluster) {
            $sourceCount = count($cluster['sources']);
            $maxSources = count(self::SOURCE_WEIGHTS);
            $cluster['kind'] = $cluster['support_votes'] > $cluster['resistance_votes']
                ? 'support'
                : ($cluster['resistance_votes'] > $cluster['support_votes'] ? 'resistance' : 'neutral');
            $cluster['sources'] = array_keys($cluster['sources']);
            // Два разных источника (DGT+SMC) должны реально набирать 55–65 до бонуса за касания.
            $cluster['strength'] = min(100.0,
                28.0 * ($sourceCount / max(1, $maxSources))
                + 24.0 * min(1.0, $cluster['weight_sum'] / 2.0)
                + 20.0 * min(1.0, $sourceCount / 2.0)
                + 28.0 * min(1.0, $cluster['count'] / 3.0)
            );
        }
        unset($cluster);

        return $clusters;
    }

    /**
     * @param  list<array<string,mixed>>  $clusters
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $m1
     */
    private function enrichClustersWithTouches(array &$clusters, array $m1, float $touchRadius): void
    {
        $last = $m1[count($m1) - 1];
        $price = (float) $last['close'];
        $lookback = array_slice($m1, max(0, count($m1) - 120));

        foreach ($clusters as &$cluster) {
            $level = (float) $cluster['price'];
            $cluster['distance'] = abs($price - $level);
            $touches = 0;
            foreach ($lookback as $bar) {
                $hi = (float) $bar['high'];
                $lo = (float) $bar['low'];
                if ($hi >= $level - $touchRadius && $lo <= $level + $touchRadius) {
                    $touches++;
                }
            }
            $cluster['touches'] = $touches;
            // Бонус за историю реакций у уровня.
            $cluster['strength'] = min(100.0, $cluster['strength'] + 10.0 * min(1.0, $touches / 5.0));
        }
        unset($cluster);
    }

    /**
     * @param  list<array<string,mixed>>  $h1
     */
    private function detectH1Bias(array $h1): string
    {
        if (count($h1) < 8) {
            return 'flat';
        }
        $tail = array_slice($h1, -12);
        $ups = 0;
        $downs = 0;
        foreach ($tail as $bar) {
            $open = (float) $bar['open'];
            $close = (float) $bar['close'];
            if ($close > $open) {
                $ups++;
            } elseif ($close < $open) {
                $downs++;
            }
        }
        if ($ups >= $downs + 3) {
            return 'up';
        }
        if ($downs >= $ups + 3) {
            return 'down';
        }

        return 'flat';
    }

    /**
     * @param  list<array<string,mixed>>  $m5
     * @param  array<string,mixed>  $cluster
     */
    private function detectRegime(array $m5, array $cluster, float $touchRadius): string
    {
        $lastM5 = $m5[count($m5) - 1];
        $close = (float) $lastM5['close'];
        $level = (float) $cluster['price'];
        $kind = (string) $cluster['kind'];

        // Пробой: закрытие M5 за уровнем.
        if ($kind === 'resistance' && $close > $level + $touchRadius * 0.15) {
            return 'breakout';
        }
        if ($kind === 'support' && $close < $level - $touchRadius * 0.15) {
            return 'breakout';
        }
        if ($kind === 'neutral') {
            if ($close > $level + $touchRadius * 0.2) {
                return 'breakout';
            }
            if ($close < $level - $touchRadius * 0.2) {
                return 'breakout';
            }
        }

        return 'bounce';
    }

    /**
     * @return array{0:string,1:list<string>,2:string}|null  [side, reasons, setup_type]
     */
    private function tryBounceEntry(
        array $m5,
        array $m1,
        array $cluster,
        string $h1Bias,
        float $minWickM5,
        bool $requireH1Alignment,
        bool $requireM1Confirm,
    ): ?array {
        $lastM5 = $m5[count($m5) - 1];
        $lastM1 = $m1[count($m1) - 1];
        $level = (float) $cluster['price'];
        $kind = (string) $cluster['kind'];
        $price = (float) $lastM1['close'];

        $side = null;
        if ($kind === 'support' || ($kind === 'neutral' && $price >= $level)) {
            $side = 'CALL';
        } elseif ($kind === 'resistance' || ($kind === 'neutral' && $price <= $level)) {
            $side = 'PUT';
        }

        if ($side === null) {
            return null;
        }

        if ($requireH1Alignment) {
            if ($side === 'CALL' && $h1Bias === 'down') {
                return null;
            }
            if ($side === 'PUT' && $h1Bias === 'up') {
                return null;
            }
        }

        $wick = $side === 'CALL' ? $this->lowerWickRatio($lastM5) : $this->upperWickRatio($lastM5);
        if ($wick < $minWickM5) {
            return null;
        }

        $m5Bull = (float) $lastM5['close'] >= (float) $lastM5['open'];
        $m5Bear = (float) $lastM5['close'] <= (float) $lastM5['open'];
        if ($side === 'CALL' && ! $m5Bull) {
            return null;
        }
        if ($side === 'PUT' && ! $m5Bear) {
            return null;
        }

        if ($requireM1Confirm) {
            $m1Bull = (float) $lastM1['close'] >= (float) $lastM1['open'];
            $m1Bear = (float) $lastM1['close'] <= (float) $lastM1['open'];
            if ($side === 'CALL' && ! $m1Bull) {
                return null;
            }
            if ($side === 'PUT' && ! $m1Bear) {
                return null;
            }
        }

        $reasons = [
            sprintf('Сильный уровень %.5f (strength %.0f, источников %d).', $level, $cluster['strength'], count($cluster['sources'])),
            sprintf('Режим: отскок; M5 rejection wick=%.0f%%.', $wick * 100),
            sprintf('H1 bias: %s.', $h1Bias),
            sprintf('Источники: %s.', implode(', ', $cluster['sources'])),
        ];

        return [$side, $reasons, 'rejection'];
    }

    /**
     * @return array{0:string,1:list<string>,2:string}|null
     */
    private function tryBreakoutEntry(
        array $m5,
        array $m1,
        array $cluster,
        string $h1Bias,
        bool $requireRetest,
        bool $volAboveSma,
        float $touchRadius,
        bool $requireH1Alignment,
    ): ?array {
        if (count($m5) < 3) {
            return null;
        }

        $lastM5 = $m5[count($m5) - 1];
        $prevM5 = $m5[count($m5) - 2];
        $lastM1 = $m1[count($m1) - 1];
        $level = (float) $cluster['price'];
        $kind = (string) $cluster['kind'];

        $side = null;
        $close = (float) $lastM5['close'];
        if ($kind === 'resistance' || ($kind === 'neutral' && $close > $level)) {
            $side = 'CALL';
        } elseif ($kind === 'support' || ($kind === 'neutral' && $close < $level)) {
            $side = 'PUT';
        }

        if ($side === null) {
            return null;
        }

        if ($requireH1Alignment) {
            if ($side === 'CALL' && $h1Bias === 'down') {
                return null;
            }
            if ($side === 'PUT' && $h1Bias === 'up') {
                return null;
            }
        }

        // Ложный пробой: предыдущая M5 уже закрылась обратно в зону.
        if ($side === 'CALL' && (float) $prevM5['close'] < $level) {
            // ok — только что пробили
        } elseif ($side === 'PUT' && (float) $prevM5['close'] > $level) {
            // ok
        } elseif ($requireRetest) {
            $retestOk = $this->breakoutRetestOk($m5, $level, $side, $touchRadius);
            if (! $retestOk) {
                return null;
            }
        }

        if ($volAboveSma) {
            $vols = array_map(static fn (array $b): float => (float) ($b['volume'] ?? 0), array_slice($m5, -21, 20));
            $avgVol = $vols !== [] ? array_sum($vols) / count($vols) : 0.0;
            if ($avgVol > 0 && (float) ($lastM5['volume'] ?? 0) < $avgVol * 0.9) {
                return null;
            }
        }

        $m1Ok = $side === 'CALL'
            ? (float) $lastM1['close'] >= (float) $lastM1['open']
            : (float) $lastM1['close'] <= (float) $lastM1['open'];
        if (! $m1Ok) {
            return null;
        }

        $reasons = [
            sprintf('Пробой уровня %.5f (strength %.0f).', $level, $cluster['strength']),
            sprintf('M5 close=%.5f за уровнем; сторона %s.', $close, $side),
            sprintf('H1 bias: %s.', $h1Bias),
            $requireRetest ? 'Ретест уровня подтверждён.' : 'Ретест не обязателен.',
        ];

        return [$side, $reasons, 'breakout_retest'];
    }

    /**
     * @param  list<array<string,mixed>>  $m5
     */
    private function breakoutRetestOk(array $m5, float $level, string $side, float $touchRadius): bool
    {
        $tail = array_slice($m5, -6, 5);
        $touched = false;
        $confirmed = false;
        foreach ($tail as $bar) {
            $hi = (float) $bar['high'];
            $lo = (float) $bar['low'];
            $c = (float) $bar['close'];
            if ($hi >= $level - $touchRadius && $lo <= $level + $touchRadius) {
                $touched = true;
            }
            if ($touched) {
                if ($side === 'CALL' && $c > $level) {
                    $confirmed = true;
                }
                if ($side === 'PUT' && $c < $level) {
                    $confirmed = true;
                }
            }
        }

        return $confirmed;
    }

    private function scoreConfidence(array $cluster, string $regime, string $h1Bias): float
    {
        $confidence = 0.56 + min(0.22, ($cluster['strength'] - 50.0) / 200.0);
        $confidence += min(0.06, $cluster['touches'] * 0.012);
        if ($regime === 'bounce') {
            $confidence += 0.03;
        }
        if ($h1Bias === 'up' || $h1Bias === 'down') {
            $confidence += 0.02;
        }

        return round(min(0.88, max(0.58, $confidence)), 4);
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $bars
     */
    private function atr(array $bars, int $period): float
    {
        $n = count($bars);
        if ($n < $period + 1) {
            return 0.0;
        }
        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $hi = (float) $bars[$i]['high'];
            $lo = (float) $bars[$i]['low'];
            $prevClose = (float) $bars[$i - 1]['close'];
            $trs[] = max($hi - $lo, abs($hi - $prevClose), abs($lo - $prevClose));
        }
        $slice = array_slice($trs, -$period);

        return array_sum($slice) / max(1, count($slice));
    }

    /**
     * @param  array<string,mixed>  $candle
     */
    private function lowerWickRatio(array $candle): float
    {
        $range = max(1e-8, (float) $candle['high'] - (float) $candle['low']);
        $bodyBottom = min((float) $candle['open'], (float) $candle['close']);

        return max(0.0, ($bodyBottom - (float) $candle['low']) / $range);
    }

    /**
     * @param  array<string,mixed>  $candle
     */
    private function upperWickRatio(array $candle): float
    {
        $range = max(1e-8, (float) $candle['high'] - (float) $candle['low']);
        $bodyTop = max((float) $candle['open'], (float) $candle['close']);

        return max(0.0, ((float) $candle['high'] - $bodyTop) / $range);
    }
}
