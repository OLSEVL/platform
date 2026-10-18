<?php

namespace App\Support;

use App\Services\Bots\AuthorFlow\AuthorFlowContextBuilder;
use App\Services\Bots\AuthorFlow\BreakoutEScenario;
use App\Services\Bots\AuthorFlow\EngulfDScenario;
use App\Services\Bots\AuthorFlow\SkopFeatures;
use App\Services\Bots\AuthorFlow\SweepRaidPbScenario;
use App\Services\CandleService;

/**
 * Компактный снимок Author Flow для LLM-аналитика (средний вариант).
 *
 * Детекторы D/E/sweep/raid_pb собирают пул кандидатов на последнем закрытом баре;
 * в payload уходит pool + structure/liquidity-контекст. LLM выбирает side только из
 * candidates (или NONE) — не изобретает новые сетапы и не обязан брать победителя Ranker.
 * Горизонт — следующая свеча S15 (expiry_focus_sec = 15 по умолчанию).
 */
final class AuthorFlowLlmSnapshot
{
    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $s15Rows
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function build(array $s15Rows, array $config = []): array
    {
        $expirySec = max(5, min(900, (int) ($config['expiry_sec'] ?? 15)));
        $cfg = array_merge(AuthorFlowConfig::defaultConfig(), $config);
        // Аналитик не сканирует — убираем scan-only шум из reminder/snapshot.
        unset($cfg['telegram_bot_token'], $cfg['telegram_chat_id']);

        if ($s15Rows === []) {
            return self::emptySnapshot($expirySec, $cfg);
        }

        if (OtcGapFilter::shouldSkip($s15Rows, $cfg, 'af')) {
            $empty = self::emptySnapshot($expirySec, $cfg);
            $empty['gap_skip'] = true;
            $gapStats = OtcGapFilter::measure($s15Rows, $cfg, 'af');
            if ($gapStats !== null) {
                $empty['gap_ratio'] = $gapStats['gap_ratio'];
            }

            return $empty;
        }

        $last = $s15Rows[array_key_last($s15Rows)];
        $lastIndex = count($s15Rows) - 1;
        $lastTime = (int) ($last['time'] ?? 0);
        $currentPrice = (float) ($last['close'] ?? 0);

        $builder = new AuthorFlowContextBuilder;
        $afContext = $builder->build($s15Rows, $cfg);
        $afContext['config'] = $cfg;
        $afContext['last_bar_index'] = $lastIndex;

        $scenarios = [
            new BreakoutEScenario,
            new EngulfDScenario,
            new SweepRaidPbScenario,
        ];
        $all = [];
        foreach ($scenarios as $scenario) {
            foreach ($scenario->collect($s15Rows, $afContext) as $c) {
                $all[] = $c;
            }
        }
        $all = (new SkopFeatures)->apply($all, $afContext, $cfg);

        // Пул для LLM: все кандидаты на last closed bar (без hard-filter Ranker).
        $pool = [];
        foreach ($all as $c) {
            if (! is_array($c)) {
                continue;
            }
            if ((string) ($c['setup'] ?? '') === 'skop_zone') {
                continue;
            }
            if ((int) ($c['bar_index'] ?? -1) !== $lastIndex) {
                continue;
            }
            $side = strtoupper((string) ($c['side'] ?? ''));
            if ($side !== 'CALL' && $side !== 'PUT') {
                continue;
            }
            $pool[] = $c;
        }

        usort($pool, static function (array $a, array $b): int {
            $pa = (int) ($a['priority'] ?? 99);
            $pb = (int) ($b['priority'] ?? 99);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0));
        });

        $candidates = array_map(static fn (array $c): array => self::compactCandidate($c), $pool);

        return [
            'timeframe' => 'S15',
            'expiry_focus_sec' => $expirySec,
            'mode' => 'pool_plus_context',
            'current_price' => $currentPrice,
            'last_bar_time' => $lastTime,
            'last_bar_index' => $lastIndex,
            'structure_bias' => (string) ($afContext['structure_bias'] ?? 'range'),
            'last_swing_high' => $afContext['last_swing_high'] ?? null,
            'last_swing_low' => $afContext['last_swing_low'] ?? null,
            'open_liquidity_call' => (bool) ($afContext['open_liquidity_call'] ?? false),
            'open_liquidity_put' => (bool) ($afContext['open_liquidity_put'] ?? false),
            'require_structure' => AuthorFlowConfig::flag($cfg, 'af_require_structure', true),
            'allow_range_entries' => AuthorFlowConfig::flag($cfg, 'af_allow_range_entries', false),
            'require_open_liquidity' => AuthorFlowConfig::flag($cfg, 'af_require_open_liquidity', false),
            // LLM обязан выбрать side только из этого пула (или NONE).
            'candidates' => array_values($candidates),
            'rule_reminder' => [
                'horizon' => "Прогноз на следующую свечу S15 (~{$expirySec} с от close текущего бара).",
                'pool' => 'candidates — пул D/E/sweep/raid_pb на last closed S15; side ∈ {CALL,PUT} только из candidates, иначе NONE.',
                'context' => 'structure_bias / open_liquidity_* — фильтр качества, не источник новой стороны.',
                'priority' => 'E(10) → D(20) → raid_pb(30) → sweep(40); при конфликте CALL+PUT на одном баре чаще NONE.',
                'engulf_D' => '3-шаговое поглощение (classic / same-color) → fade на close шага 3.',
                'breakout_E' => 'Пробой + закрепление N closes за уровнем → продолжение.',
                'raid_pb' => 'Снятие ликвидности → вход на первой откатной.',
                'sweep' => 'Свип FH/FL с возвратом → fade.',
                'invalid' => 'Пустой pool, конфликт сторон, структура против, «уже ушли» → NONE.',
            ],
        ];
    }

    /**
     * Snapshot + лёгкий overlay (линии свингов) + seed chart_levels для графика аналитика.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $s15Rows
     * @param  array<string, mixed>  $config
     * @return array{snapshot:array<string,mixed>,overlay:array<string,mixed>,chart_levels:array<string,mixed>}
     */
    public static function pack(array $s15Rows, array $config = []): array
    {
        $snapshot = self::build($s15Rows, $config);

        return [
            'snapshot' => $snapshot,
            'overlay' => self::overlayFromSnapshot($snapshot),
            'chart_levels' => self::seedChartLevels($snapshot),
        ];
    }

    /**
     * Дописывает seed-уровни Author Flow поверх ответа LLM (как ImpulseLvlLlmSnapshot::mergeChartLevels).
     *
     * @param  array<string, mixed>  $llmLevels
     * @param  array<string, mixed>  $seed
     * @return array{levels:list<array<string,mixed>>,zones:list<array<string,mixed>>,entry:?array<string,mixed>}
     */
    public static function mergeChartLevels(array $llmLevels, array $seed): array
    {
        $levels = array_values(array_merge(
            is_array($seed['levels'] ?? null) ? $seed['levels'] : [],
            is_array($llmLevels['levels'] ?? null) ? $llmLevels['levels'] : [],
        ));
        $zones = array_values(array_merge(
            is_array($seed['zones'] ?? null) ? $seed['zones'] : [],
            is_array($llmLevels['zones'] ?? null) ? $llmLevels['zones'] : [],
        ));
        $entry = is_array($llmLevels['entry'] ?? null)
            ? $llmLevels['entry']
            : (is_array($seed['entry'] ?? null) ? $seed['entry'] : null);

        return [
            'levels' => $levels,
            'zones' => $zones,
            'entry' => $entry,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{levels:list<array<string,mixed>>,zones:list<array<string,mixed>>,entry:?array<string,mixed>}
     */
    public static function seedChartLevels(array $snapshot): array
    {
        $levels = [];
        $high = $snapshot['last_swing_high'] ?? null;
        $low = $snapshot['last_swing_low'] ?? null;
        if (is_numeric($high) && (float) $high > 0) {
            $levels[] = [
                'price' => (float) $high,
                'kind' => 'resistance',
                'label' => 'S15 swing high',
                'timeframe' => 'S15',
            ];
        }
        if (is_numeric($low) && (float) $low > 0) {
            $levels[] = [
                'price' => (float) $low,
                'kind' => 'support',
                'label' => 'S15 swing low',
                'timeframe' => 'S15',
            ];
        }

        $entry = null;
        $candidates = is_array($snapshot['candidates'] ?? null) ? $snapshot['candidates'] : [];
        if ($candidates !== []) {
            $top = $candidates[0];
            $price = (float) ($top['entry_price'] ?? 0);
            $side = strtoupper((string) ($top['side'] ?? ''));
            if ($price > 0 && ($side === 'CALL' || $side === 'PUT')) {
                $entry = [
                    'price' => $price,
                    'side' => $side,
                    'label' => 'S15 '.((string) ($top['setup'] ?? 'af')).' · '.$side,
                ];
                $levels[] = [
                    'price' => $price,
                    'kind' => 'entry',
                    'label' => $entry['label'],
                    'timeframe' => 'S15',
                ];
            }
        }

        return [
            'levels' => $levels,
            'zones' => [],
            'entry' => $entry,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{lines:list<array<string,mixed>>,labels:list<array<string,mixed>>,label_size:string}
     */
    private static function overlayFromSnapshot(array $snapshot): array
    {
        $lines = [];
        $labels = [];
        $high = $snapshot['last_swing_high'] ?? null;
        $low = $snapshot['last_swing_low'] ?? null;
        if (is_numeric($high) && (float) $high > 0) {
            $lines[] = [
                'price' => (float) $high,
                'color' => '#f87171',
                'style' => 'dashed',
                'label' => 'swing H',
            ];
        }
        if (is_numeric($low) && (float) $low > 0) {
            $lines[] = [
                'price' => (float) $low,
                'color' => '#4ade80',
                'style' => 'dashed',
                'label' => 'swing L',
            ];
        }
        foreach (is_array($snapshot['candidates'] ?? null) ? $snapshot['candidates'] : [] as $c) {
            if (! is_array($c)) {
                continue;
            }
            $price = (float) ($c['entry_price'] ?? 0);
            $side = (string) ($c['side'] ?? '');
            $setup = (string) ($c['setup'] ?? '');
            if ($price <= 0) {
                continue;
            }
            $labels[] = [
                'price' => $price,
                'text' => $setup.' '.$side,
                'color' => $side === 'CALL' ? '#4ade80' : '#f87171',
            ];
        }

        return [
            'lines' => $lines,
            'labels' => $labels,
            'label_size' => 'small',
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private static function compactCandidate(array $c): array
    {
        $features = is_array($c['features'] ?? null) ? $c['features'] : [];

        return [
            'setup' => (string) ($c['setup'] ?? ''),
            'side' => strtoupper((string) ($c['side'] ?? '')),
            'priority' => (int) ($c['priority'] ?? 99),
            'score' => round((float) ($c['score'] ?? 0), 4),
            'bar_index' => (int) ($c['bar_index'] ?? 0),
            'event_time' => (int) ($c['time'] ?? 0),
            'entry_price' => (float) ($c['entry_price'] ?? 0),
            'engulf_kind' => isset($features['engulf_kind']) ? (string) $features['engulf_kind'] : null,
            'reason_ru' => isset($features['reason_ru']) ? (string) $features['reason_ru'] : null,
            'level_price' => isset($features['level_price']) ? (float) $features['level_price'] : null,
            'structure_aligned' => (bool) ($features['structure_aligned'] ?? false),
            'liquidity_ok' => (bool) ($features['liquidity_ok'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    private static function emptySnapshot(int $expirySec, array $cfg): array
    {
        return [
            'timeframe' => 'S15',
            'expiry_focus_sec' => $expirySec,
            'mode' => 'pool_plus_context',
            'current_price' => null,
            'last_bar_time' => null,
            'last_bar_index' => null,
            'structure_bias' => 'range',
            'last_swing_high' => null,
            'last_swing_low' => null,
            'open_liquidity_call' => false,
            'open_liquidity_put' => false,
            'require_structure' => AuthorFlowConfig::flag($cfg, 'af_require_structure', true),
            'allow_range_entries' => AuthorFlowConfig::flag($cfg, 'af_allow_range_entries', false),
            'require_open_liquidity' => AuthorFlowConfig::flag($cfg, 'af_require_open_liquidity', false),
            'candidates' => [],
            'rule_reminder' => [
                'horizon' => "Прогноз на следующую свечу S15 (~{$expirySec} с).",
                'pool' => 'Пустой candidates → NONE.',
            ],
            'candle_tf_id' => CandleService::TF_S15,
        ];
    }
}
