<?php

namespace App\Services\Bots\Levels;

use App\Services\Telegram\LevelBounceAlertPresenter;

use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;

/**
 * Candle Reversal + Confirm v3: отбитие от сильного уровня (как Strong Level),
 * только режим bounce. Подтверждение настраивается отдельно:
 * - опционально M5 rejection;
 * - опционально N последних M1 в сторону разворота (1–3).
 */
class CandleReversalLevelAnalyzer
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

        $minStrength = max(40.0, min(95.0, (float) ($cfg['min_level_strength'] ?? 58.0)));
        $minSources = max(1, min(6, (int) ($cfg['min_sources'] ?? 1)));
        $clusterAtrMult = max(0.05, min(1.0, (float) ($cfg['cluster_atr_mult_m5'] ?? 0.28)));
        $touchAtrMult = max(0.05, min(1.5, (float) ($cfg['touch_atr_mult_m1'] ?? 0.55)));
        $requireH1Alignment = (bool) ($cfg['require_h1_alignment'] ?? false);
        $h1FlatStrengthExtra = max(0.0, min(15.0, (float) ($cfg['h1_flat_strength_extra'] ?? 4.0)));
        $expirySec = max(120, min(600, (int) ($cfg['expiry_sec'] ?? 300)));

        // M5 rejection (опционально).
        $m5RejectionEnabled = (bool) ($cfg['m5_rejection_enabled'] ?? true);
        $bounceMinWickM5 = max(0.15, min(0.80, (float) ($cfg['bounce_min_wick_ratio_m5'] ?? 0.28)));
        $m5RejectionRequireDirection = (bool) ($cfg['m5_rejection_require_direction'] ?? true);

        // M1 разворот: N последних закрытых свечей в сторону отскока (опционально).
        $m1ReversalEnabled = (bool) ($cfg['m1_reversal_enabled'] ?? true);
        $m1ReversalBars = max(1, min(3, (int) ($cfg['m1_reversal_confirm_bars'] ?? 1)));
        $m1MinWick = max(0.10, min(0.70, (float) ($cfg['m1_reversal_min_wick_ratio'] ?? 0.22)));
        $m1MinBody = max(0.05, min(0.80, (float) ($cfg['m1_reversal_min_body_ratio'] ?? 0.25)));
        $m1RequireWickDominance = (bool) ($cfg['m1_reversal_require_wick_dominance'] ?? true);
        $m1WickScope = (string) ($cfg['m1_reversal_wick_scope'] ?? 'last');
        if (! in_array($m1WickScope, ['all', 'last'], true)) {
            $m1WickScope = 'last';
        }

        // Без хотя бы одного блока подтверждения вход «голым» касанием уровня запрещён.
        if (! $m5RejectionEnabled && ! $m1ReversalEnabled) {
            return null;
        }

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
        if ($requireH1Alignment && $h1Bias === 'flat' && count($h1) >= 10) {
            if ($nearest['strength'] < $minStrength + $h1FlatStrengthExtra) {
                return null;
            }
        }

        // Только отскок: если M5 уже закрылась за уровнем — это пробой, пропускаем.
        if ($this->detectRegime($m5, $nearest, $touchRadius) !== 'bounce') {
            return null;
        }

        $entry = $this->tryLevelBounceEntry(
            $m5,
            $m1Closed,
            $nearest,
            $h1Bias,
            $requireH1Alignment,
            $m5RejectionEnabled,
            $bounceMinWickM5,
            $m5RejectionRequireDirection,
            $m1ReversalEnabled,
            $m1ReversalBars,
            $m1MinWick,
            $m1MinBody,
            $m1RequireWickDominance,
            $m1WickScope,
        );

        if ($entry === null) {
            return null;
        }

        [$side, $reasons, $setupType, $m5Wick] = $entry;
        $confidence = $this->scoreConfidence($nearest, $h1Bias, $payout);

        $features = [
                'level_price' => round($nearest['price'], 8),
                'level_strength' => round($nearest['strength'], 2),
                'level_kind' => $nearest['kind'],
                'level_sources' => $nearest['sources'],
                'level_touches' => $nearest['touches'],
                'setup_type' => $setupType,
                'entry_regime' => 'bounce',
                'h1_bias' => $h1Bias,
                'atr_m1' => round($atrM1, 8),
                'atr_m5' => round($atrM5, 8),
                'distance_to_level' => round($nearest['distance'], 8),
                'm5_rejection_enabled' => $m5RejectionEnabled,
                'm1_reversal_enabled' => $m1ReversalEnabled,
                'm1_reversal_confirm_bars' => $m1ReversalBars,
                'm1_reversal_wick_scope' => $m1WickScope,
                'signal_side' => $side,
                'payout' => $payout,
                'partial_present' => $partial !== null,
        ];
        if ($m5Wick !== null) {
            $features['m5_reversal_wick'] = round($m5Wick, 4);
        }

        return [
            'side' => $side,
            'confidence' => $confidence,
            'expiry_sec' => $expirySec,
            'reasons_short' => $reasons,
            'analysis_text' => sprintf(
                'candle_reversal_level: %s @ %.5f strength=%.1f sources=%d h1=%s conf=%.2f',
                $side,
                $nearest['price'],
                $nearest['strength'],
                count($nearest['sources']),
                $h1Bias,
                $confidence
            ),
            'features' => $features,
            'decision_version' => 'candle_reversal_confirm_v3_level_bounce',
        ];
    }

    /**
     * @return array{0:string,1:list<string>,2:string,3:?float}|null  [side, reasons, setup_type, m5_wick]
     */
    private function tryLevelBounceEntry(
        array $m5,
        array $m1,
        array $cluster,
        string $h1Bias,
        bool $requireH1Alignment,
        bool $m5RejectionEnabled,
        float $minWickM5,
        bool $m5RejectionRequireDirection,
        bool $m1ReversalEnabled,
        int $m1ReversalBars,
        float $m1MinWick,
        float $m1MinBody,
        bool $m1RequireWickDominance,
        string $m1WickScope,
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

        $m5Wick = null;
        if ($m5RejectionEnabled) {
            $wick = $side === 'CALL' ? $this->lowerWickRatio($lastM5) : $this->upperWickRatio($lastM5);
            if ($wick < $minWickM5) {
                return null;
            }
            if ($m5RejectionRequireDirection) {
                $m5Bull = (float) $lastM5['close'] >= (float) $lastM5['open'];
                $m5Bear = (float) $lastM5['close'] <= (float) $lastM5['open'];
                if ($side === 'CALL' && ! $m5Bull) {
                    return null;
                }
                if ($side === 'PUT' && ! $m5Bear) {
                    return null;
                }
            }
            $m5Wick = $wick;
        }

        if ($m1ReversalEnabled) {
            if (count($m1) < $m1ReversalBars) {
                return null;
            }
            /** @var list<array<string,mixed>> $confirmBars */
            $confirmBars = array_values(array_slice($m1, -$m1ReversalBars));
            if (! $this->confirmM1ReversalSide(
                $confirmBars,
                $side,
                $m1MinWick,
                $m1MinBody,
                $m1RequireWickDominance,
                $m1WickScope
            )) {
                return null;
            }
        }

        $presenter = app(LevelBounceAlertPresenter::class);
        $kindRu = $presenter->levelKindRuGenitive((string) $cluster['kind']);
        $sourcesHuman = $presenter->formatSourceList($cluster['sources']);

        $reasons = [
            sprintf(
                'Сильный уровень %s %.5f (сила %.0f, %s).',
                $kindRu,
                $level,
                $cluster['strength'],
                implode('+', $sourcesHuman)
            ),
            sprintf('Режим: отскок; сторона %s.', $side),
        ];
        if ($m5RejectionEnabled && $m5Wick !== null) {
            $reasons[] = sprintf('M5 rejection (тень %.0f%%).', $m5Wick * 100);
        }
        if ($m1ReversalEnabled) {
            $reasons[] = sprintf('M1: %d свечи подтверждают разворот.', $m1ReversalBars);
        }
        $h1Ru = $presenter->h1BiasRu($h1Bias);
        $reasons[] = $h1Ru !== '' ? sprintf('H1: %s.', $h1Ru) : sprintf('H1: %s.', $h1Bias);

        return [$side, $reasons, 'level_bounce_reversal', $m5Wick];
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function confirmM1ReversalSide(
        array $bars,
        string $side,
        float $m1MinWick,
        float $m1MinBody,
        bool $m1RequireWickDominance,
        string $m1WickScope
    ): bool {
        if ($bars === []) {
            return false;
        }

        $lastIdx = count($bars) - 1;
        foreach ($bars as $idx => $bar) {
            if ($side === 'CALL' && ! $this->isBull($bar)) {
                return false;
            }
            if ($side === 'PUT' && ! $this->isBear($bar)) {
                return false;
            }
            if ($this->bodyToRangeRatio($bar) < $m1MinBody) {
                return false;
            }

            $applyWickRules = $m1WickScope === 'all' || $idx === $lastIdx;
            if (! $applyWickRules) {
                continue;
            }

            $sideWick = $side === 'CALL' ? $this->lowerWickRatio($bar) : $this->upperWickRatio($bar);
            $oppWick = $side === 'CALL' ? $this->upperWickRatio($bar) : $this->lowerWickRatio($bar);
            if ($sideWick < $m1MinWick) {
                return false;
            }
            if ($m1RequireWickDominance && $sideWick <= $oppWick) {
                return false;
            }
        }

        return true;
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

    private function scoreConfidence(array $cluster, string $h1Bias, ?float $payout): float
    {
        $confidence = 0.56 + min(0.22, ($cluster['strength'] - 50.0) / 200.0);
        $confidence += min(0.06, $cluster['touches'] * 0.012);
        $confidence += 0.03;
        if ($h1Bias === 'up' || $h1Bias === 'down') {
            $confidence += 0.02;
        }
        if ($payout !== null) {
            $confidence += 0.05 * min(1.0, max(0.0, $payout / 100.0));
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
     * @param  array<string,mixed>  $c
     */
    private function isBull(array $c): bool
    {
        return (float) $c['close'] > (float) $c['open'];
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function isBear(array $c): bool
    {
        return (float) $c['close'] < (float) $c['open'];
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function bodyToRangeRatio(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $body = abs((float) $c['close'] - (float) $c['open']);

        return min(1.0, $body / $range);
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
