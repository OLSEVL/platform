<?php

namespace App\Support;

/**
 * Компактный снимок Impulse LVL (бот 47) для Chart Analyst: активные зоны M1–M30 + live-хиты.
 * LLM сам решает CALL/PUT/NONE по зонам и импульсу, не только «подтверждает candidates».
 */
final class ImpulseLvlLlmSnapshot
{
    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1Closed
     * @param  array{time:int,open:float,high:float,low:float,close:float}|null  $evalCandle
     * @param  array<string, list<array<string, mixed>>>  $mtf
     * @param  array<string, mixed>  $config
     * @return array{
     *   snapshot: array<string, mixed>,
     *   chart_levels: array{levels: list<array<string, mixed>>, zones: list<array<string, mixed>>, entry: null},
     *   overlay: array<string, mixed>,
     *   telegram: array{zones: list<array<string, mixed>>, overlay_lines: list<string>, price_levels: list<array<string, mixed>>, signal_time: int}
     * }
     */
    public static function pack(array $m1Closed, ?array $evalCandle, array $mtf, array $config = []): array
    {
        $emptyLevels = [
            'levels' => [],
            'zones' => [],
            'entry' => null,
        ];
        $emptyOverlay = [
            'lines' => [],
            'areas' => [],
            'labels' => [],
            'label_size' => 'small',
        ];

        if ($evalCandle === null || $m1Closed === []) {
            return [
                'snapshot' => self::emptySnapshot($evalCandle),
                'chart_levels' => $emptyLevels,
                'overlay' => $emptyOverlay,
                'telegram' => [
                    'zones' => [],
                    'overlay_lines' => ['LVL: нет ряда M1 — фильтр не считался'],
                    'price_levels' => [],
                    'markers' => [],
                    'signal_time' => (int) ($evalCandle['time'] ?? 0),
                ],
            ];
        }

        $zones = ImpulseLvl1minLevels::activeZones($m1Closed, $evalCandle, $mtf, $config);
        $liveHits = ImpulseLvl1minSignalEvaluator::findAllSignals($m1Closed, $evalCandle, $config, $mtf);
        $liveSetups = [];
        foreach ($liveHits as $hit) {
            $color = (string) ($hit['color'] ?? 'green');
            $liveSetups[] = [
                'side' => ImpulseLvl1minConfig::sideFromImpulseColor($color, $config),
                'kind' => (string) ($hit['kind'] ?? ''),
                'id' => (string) ($hit['strategy_id'] ?? ''),
                'value' => (float) ($hit['value'] ?? 0),
                'lvl_labels' => is_array($hit['lvl_labels'] ?? null) ? $hit['lvl_labels'] : [],
                'is_prev' => (bool) ($hit['is_prev'] ?? false),
            ];
        }

        $chartLevels = self::zonesToChartLevels($zones);
        $touching = array_values(array_filter($zones, static fn (array $z): bool => (bool) $z['touching']));
        $filter = self::describeFilter($m1Closed, $evalCandle, $config, $zones, $liveSetups);
        $chartLevels['subtitle'] = $filter['subtitle'];
        $chartLevels['legend'] = $filter['legend'];

        return [
            'snapshot' => [
                'timeframe' => 'M1',
                'expiry_focus_sec' => 60,
                'current_price' => (float) ($evalCandle['close'] ?? 0),
                'eval_open' => (float) ($evalCandle['open'] ?? 0),
                'eval_high' => (float) ($evalCandle['high'] ?? 0),
                'eval_low' => (float) ($evalCandle['low'] ?? 0),
                'eval_close' => (float) ($evalCandle['close'] ?? 0),
                'last_bar_time' => (int) ($evalCandle['time'] ?? 0),
                'overlay_forming_htf' => ImpulseLvl1minConfig::flag($config, 'overlay_forming_htf', true),
                'lvl_filter' => (bool) ($config['lvl_filter'] ?? true),
                'active_zones' => self::compactZones($zones),
                'touching_zones' => self::compactZones($touching),
                'live_setups' => $liveSetups,
                'filter_status' => $filter,
                'entry_side_mode' => ImpulseLvl1minConfig::entrySideMode($config),
                'rule_reminder' => ImpulseLvl1minConfig::llmRuleReminder($config),
            ],
            'chart_levels' => $chartLevels,
            // Web-overlay пока пустой; Telegram PNG берёт telegram.* (зоны LVL + статус фильтра).
            'overlay' => $emptyOverlay,
            'telegram' => [
                'zones' => $zones,
                'overlay_lines' => $filter['overlay_lines'],
                'price_levels' => $filter['price_levels'],
                'markers' => $filter['markers'],
                'signal_time' => (int) ($evalCandle['time'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array{levels?: list<array<string, mixed>>, zones?: list<array<string, mixed>>, entry?: mixed}  $fromLlm
     * @param  array{levels?: list<array<string, mixed>>, zones?: list<array<string, mixed>>, entry?: mixed}  $seed
     * @return array{levels: list<array<string, mixed>>, zones: list<array<string, mixed>>, entry: mixed}
     */
    public static function mergeChartLevels(array $fromLlm, array $seed): array
    {
        $levels = array_values(array_merge(
            is_array($seed['levels'] ?? null) ? $seed['levels'] : [],
            is_array($fromLlm['levels'] ?? null) ? $fromLlm['levels'] : [],
        ));
        $zones = array_values(array_merge(
            is_array($seed['zones'] ?? null) ? $seed['zones'] : [],
            is_array($fromLlm['zones'] ?? null) ? $fromLlm['zones'] : [],
        ));
        $entry = $fromLlm['entry'] ?? ($seed['entry'] ?? null);

        return [
            'levels' => $levels,
            'zones' => $zones,
            'entry' => is_array($entry) ? $entry : null,
            'subtitle' => (string) ($seed['subtitle'] ?? $fromLlm['subtitle'] ?? ''),
            'legend' => (string) ($seed['legend'] ?? $fromLlm['legend'] ?? ''),
        ];
    }

    /**
     * @param  array{time?:int,open?:float,high?:float,low?:float,close?:float}|null  $evalCandle
     * @return array<string, mixed>
     */
    private static function emptySnapshot(?array $evalCandle): array
    {
        return [
            'timeframe' => 'M1',
            'expiry_focus_sec' => 60,
            'current_price' => $evalCandle !== null ? (float) ($evalCandle['close'] ?? 0) : null,
            'last_bar_time' => $evalCandle !== null ? (int) ($evalCandle['time'] ?? 0) : null,
            'active_zones' => [],
            'touching_zones' => [],
            'live_setups' => [],
            'rule_reminder' => [
                'none' => 'Нет ряда M1 — NONE.',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $zones
     * @return list<array<string, mixed>>
     */
    private static function compactZones(array $zones): array
    {
        $out = [];
        foreach ($zones as $z) {
            $out[] = [
                'tf' => (string) ($z['tf'] ?? ''),
                'side' => (string) ($z['side'] ?? ''),
                'kind' => (string) ($z['kind'] ?? ''),
                'price' => round((float) ($z['price'] ?? 0), 8),
                'zone_min' => round((float) ($z['zone_min'] ?? 0), 8),
                'zone_max' => round((float) ($z['zone_max'] ?? 0), 8),
                'time' => (int) ($z['time'] ?? 0),
                'touching' => (bool) ($z['touching'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $zones
     * @return array{levels: list<array<string, mixed>>, zones: list<array<string, mixed>>, entry: null}
     */
    private static function zonesToChartLevels(array $zones): array
    {
        $levels = [];
        $chartZones = [];
        foreach ($zones as $z) {
            $tf = (string) ($z['tf'] ?? 'M1');
            $kind = (string) ($z['kind'] ?? 'support');
            $price = (float) ($z['price'] ?? 0);
            $zoneMin = (float) ($z['zone_min'] ?? $price);
            $zoneMax = (float) ($z['zone_max'] ?? $price);
            $touch = (bool) ($z['touching'] ?? false);
            $sense = $kind === 'resistance' ? 'сопротивление' : 'поддержка';
            $touchLabel = $touch ? 'касание' : 'нет касания';
            $color = $kind === 'resistance' ? '#ef4444' : '#22c55e';
            $lo = min($zoneMin, $zoneMax);
            $hi = max($zoneMin, $zoneMax);
            // Полоса должна быть видимой даже если zone_min == zone_max.
            if ($hi <= $lo) {
                $pad = max(abs($price) * 1.0e-5, 1.0e-8);
                $lo = $price - $pad;
                $hi = $price + $pad;
            }
            $levels[] = [
                'price' => $price,
                'kind' => $kind,
                'timeframe' => $tf,
                'style' => $touch ? 'solid' : 'dashed',
                'color' => $color,
                'label' => sprintf('%s %s · %s', $tf, $sense, $touchLabel),
            ];
            $chartZones[] = [
                'price_high' => $hi,
                'price_low' => $lo,
                'timeframe' => $tf,
                'label' => sprintf('%s зона · %s', $tf, $touchLabel),
                'touching' => $touch,
            ];
        }

        return [
            'levels' => $levels,
            'zones' => $chartZones,
            'entry' => null,
        ];
    }

    private static function fmtPrice(float $price): string
    {
        $text = rtrim(rtrim(sprintf('%.8f', $price), '0'), '.');

        return $text !== '' ? $text : '0';
    }

    /**
     * Почему live_setups пуст / не пуст — для PNG и snapshot (не для промпта модели).
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float}>  $m1Closed
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $evalCandle
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $zones
     * @param  list<array<string, mixed>>  $liveSetups
     * @return array{
     *   passed:bool,
     *   reason:string,
     *   subtitle:string,
     *   legend:string,
     *   overlay_lines:list<string>,
     *   price_levels:list<array{price:float,label:string,color:string}>,
     *   markers:list<array<string,mixed>>
     * }
     */
    private static function describeFilter(
        array $m1Closed,
        array $evalCandle,
        array $config,
        array $zones,
        array $liveSetups,
    ): array {
        $touchN = 0;
        $priceLevels = [];
        $markers = [];
        $evalTime = (int) ($evalCandle['time'] ?? 0);
        $evalClose = (float) ($evalCandle['close'] ?? 0);
        foreach ($zones as $z) {
            $touch = (bool) ($z['touching'] ?? false);
            if ($touch) {
                $touchN++;
            }
            $tf = (string) ($z['tf'] ?? 'M1');
            $kind = (string) ($z['kind'] ?? 'support');
            $visual = ImpulseLvl1minConfig::lvlZoneEntryVisual($kind, $config);
            $isResistance = $kind === 'resistance';
            $priceLevels[] = [
                'price' => (float) ($z['price'] ?? 0),
                'label' => sprintf('%s%s %s', $tf, $visual['glyph'], $touch ? 'касание' : 'нет'),
                'color' => $touch
                    ? ($isResistance ? '#f87171' : '#4ade80')
                    : '#94a3b8',
            ];
            if ($touch && $evalTime > 0) {
                $markers[] = [
                    'time' => $evalTime,
                    'price' => $evalClose > 0 ? $evalClose : (float) ($z['price'] ?? 0),
                    'position' => $visual['position'],
                    'arrow' => $visual['arrow'],
                    'color' => $isResistance ? '#ef4444' : '#22c55e',
                    'size' => 'entry',
                    'text' => $visual['text'],
                ];
            }
        }

        $open = (float) ($evalCandle['open'] ?? 0);
        $close = (float) ($evalCandle['close'] ?? 0);
        $dir = $close <=> $open;
        $needHist = ImpulseLvl1minConfig::historyPeriod($config);
        $haveHist = count($m1Closed);
        $lvlOn = (bool) ($config['lvl_filter'] ?? true);

        if ($liveSetups !== []) {
            $ids = [];
            foreach ($liveSetups as $hit) {
                $id = trim((string) ($hit['id'] ?? $hit['kind'] ?? ''));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
            $reason = 'фильтр пропустил: '.($ids !== [] ? implode(', ', $ids) : 'импульс + LVL');
            $passed = true;
        } elseif ($dir === 0) {
            $reason = 'фильтр отсёк: нет направления (доджи)';
            $passed = false;
        } elseif ($haveHist < $needHist) {
            $reason = sprintf('фильтр отсёк: мало истории M1 (%d/%d)', $haveHist, $needHist);
            $passed = false;
        } elseif ($lvlOn && $touchN === 0) {
            $reason = 'фильтр отсёк: цена не касается непробитой LVL-зоны';
            $passed = false;
        } elseif ($touchN > 0) {
            $reason = 'фильтр отсёк: касание LVL есть, импульс тела слабый (нет MAX/профиля)';
            $passed = false;
        } else {
            $reason = 'фильтр отсёк: нет импульса';
            $passed = false;
        }

        $zoneBits = [];
        foreach ($zones as $z) {
            $zoneBits[] = (string) ($z['tf'] ?? '?');
        }
        $zoneBits = array_values(array_unique($zoneBits));
        $zoneLine = $zones === []
            ? 'LVL-зон нет (все пробиты или не найдены)'
            : sprintf('LVL-зоны: %s (%d), касаний: %d', implode('/', $zoneBits), count($zones), $touchN);

        $subtitle = $reason;
        $legend = ImpulseLvl1minConfig::isBounceEntry($config)
            ? '↑ CALL от поддержки · ↓ PUT от сопротивления (отбой)'
            : '↑ CALL по импульсу у сопротивления · ↓ PUT по импульсу у поддержки';

        return [
            'passed' => $passed,
            'reason' => $reason,
            'subtitle' => mb_substr($subtitle, 0, 96),
            'legend' => $legend,
            'overlay_lines' => [
                $zoneLine,
                $reason,
            ],
            'price_levels' => $priceLevels,
            'markers' => $markers,
        ];
    }
}
