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
     *   overlay: array<string, mixed>
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
            ];
        }

        $zones = ImpulseLvl1minLevels::activeZones($m1Closed, $evalCandle, $mtf, $config);
        $liveHits = ImpulseLvl1minSignalEvaluator::findAllSignals($m1Closed, $evalCandle, $config, $mtf);
        $liveSetups = [];
        foreach ($liveHits as $hit) {
            $color = (string) ($hit['color'] ?? 'green');
            $liveSetups[] = [
                'side' => $color === 'green' ? 'CALL' : 'PUT',
                'kind' => (string) ($hit['kind'] ?? ''),
                'id' => (string) ($hit['strategy_id'] ?? ''),
                'value' => (float) ($hit['value'] ?? 0),
                'lvl_labels' => is_array($hit['lvl_labels'] ?? null) ? $hit['lvl_labels'] : [],
                'is_prev' => (bool) ($hit['is_prev'] ?? false),
            ];
        }

        $chartLevels = self::zonesToChartLevels($zones);
        $touching = array_values(array_filter($zones, static fn (array $z): bool => (bool) $z['touching']));

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
                'rule_reminder' => [
                    'impulse' => 'Сильное тело vs trim-mean × множитель профиля или MAX-ratio к истории (как бот Impulse LVL 1min).',
                    'lvl' => 'Вход по тренду импульса только если цена касается непробитой LVL-зоны M1/M5/M15/M30.',
                    'call' => 'Бычий импульс + касание поддержки (нижняя зона) → CALL, экспирация 60 с.',
                    'put' => 'Медвежий импульс + касание сопротивления (верхняя зона) → PUT, экспирация 60 с.',
                    'none' => 'Нет импульса, нет касания LVL, конфликт ТФ или уже пробитая зона → NONE.',
                    'llm_role' => 'Ты не обязан копировать live_setups: это подсказка детектора. Решение CALL/PUT/NONE — твоё.',
                ],
            ],
            'chart_levels' => $chartLevels,
            'overlay' => $emptyOverlay,
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
            $levels[] = [
                'price' => $price,
                'kind' => $kind,
                'timeframe' => $tf,
                'style' => $touch ? 'solid' : 'dashed',
                'label' => sprintf('%s %s · %s', $tf, $sense, self::fmtPrice($price)),
            ];
            $chartZones[] = [
                'price_high' => max($zoneMin, $zoneMax),
                'price_low' => min($zoneMin, $zoneMax),
                'timeframe' => $tf,
                'label' => sprintf('%s зона · %s–%s', $tf, self::fmtPrice(min($zoneMin, $zoneMax)), self::fmtPrice(max($zoneMin, $zoneMax))),
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
}
