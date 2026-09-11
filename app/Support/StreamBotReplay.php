<?php

/**
 * Общий движок бар-в-бар replay стрима vs SweepRaid / Engulfing.
 * Конфиг JSON рядом со стримом или в configs/.
 */

declare(strict_types=1);

namespace App\Support;

use App\Services\Bots\Strategies\EngulfingLiquidityStrategy;
use App\Services\Bots\Strategies\SweepRaidLiquidityStrategy;
use Illuminate\Support\Facades\DB;

final class StreamBotReplay
{
    public const MATCH_WINDOW_SEC = 20;

    public const WARMUP_SEC = 45 * 60;

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public static function run(array $config): array
    {
        $streamId = (string) $config['stream_id'];
        $streamStart = (int) $config['stream_start_utc'];
        $duration = (int) $config['duration_sec'];
        $instruments = array_values($config['instruments'] ?? []);
        $pairSchedule = $config['pair_schedule'] ?? []; // [[max_offset, instrument], ...]
        $transcriptPath = (string) ($config['transcript_path'] ?? '');
        $manualEntries = $config['manual_entries'] ?? [];
        $gapStart = isset($config['gap_start_utc']) ? (int) $config['gap_start_utc'] : null;

        $replayEnd = $streamStart + $duration;
        if ($gapStart !== null) {
            $replayEnd = min($replayEnd, $gapStart - 15);
        }

        $segments = [];
        if ($transcriptPath !== '' && is_file($transcriptPath)) {
            $raw = json_decode((string) file_get_contents($transcriptPath), true);
            $segments = is_array($raw['segments'] ?? null) ? $raw['segments'] : [];
        }

        $authorEvents = self::extractAuthorEntries($segments, $pairSchedule);
        foreach ($manualEntries as $me) {
            $authorEvents[] = [
                'offset' => (int) $me['offset'],
                'side' => $me['side'] ?? null,
                'kind' => (string) ($me['kind'] ?? 'manual'),
                'text' => (string) ($me['text'] ?? 'manual'),
                'pair' => (string) ($me['pair'] ?? self::pairAtOffset((int) $me['offset'], $pairSchedule)),
            ];
        }
        usort($authorEvents, fn ($a, $b) => $a['offset'] <=> $b['offset']);
        $authorEntries = array_values(array_filter($authorEvents, fn ($e) => ($e['side'] ?? null) !== null));

        $cfgLive = [
            'expiry_sec' => 60,
            'srl_pivot_length' => 2,
            'srl_enter_sweep' => true,
            'srl_enter_pullback' => true,
            'srl_enter_breakout' => false,
            'srl_demarker_enabled' => false,
            'srl_gap_filter_enabled' => false,
        ];
        // Author-like P0: S15-серия уже в replay; expiry 15с + breakout + gap + exclusive + PB-sweeps-only.
        $cfgAuthor = [
            'expiry_sec' => 15,
            'srl_pivot_length' => 2,
            'srl_enter_sweep' => true,
            'srl_enter_pullback' => true,
            'srl_pullback_sweeps_only' => true,
            'srl_enter_breakout' => true,
            'srl_breakout_closes' => 2,
            'srl_exclusive_fade_breakout' => true,
            'srl_demarker_enabled' => false,
            'srl_gap_filter_enabled' => true,
            'srl_gap_lookback' => 40,
            'srl_gap_max_ratio' => 0.12,
            'srl_gap_size_mult' => 0.5,
            'srl_skip_martingale_if_session_green' => true,
        ];
        // Опциональный оверрайд author-like (A/B: pullback OFF и т.п.).
        if (isset($config['cfg_author_override']) && is_array($config['cfg_author_override'])) {
            $cfgAuthor = array_merge($cfgAuthor, $config['cfg_author_override']);
        }

        $srl = new SweepRaidLiquidityStrategy;
        $eng = new EngulfingLiquidityStrategy;
        $warmupFrom = $streamStart - self::WARMUP_SEC;

        $allLive = [];
        $allAuth = [];
        $allEng = [];
        $allScan = [];
        $pairMeta = [];

        foreach ($instruments as $inst) {
            $s5 = self::loadS5($inst, $warmupFrom, $replayEnd);
            $s15 = self::aggregateS15($s5);
            $pairMeta[$inst] = [
                's5_bars' => count($s5),
                's15_bars' => count($s15),
                'first' => $s15[0]['time'] ?? null,
                'last' => $s15 !== [] ? $s15[count($s15) - 1]['time'] : null,
            ];
            if (count($s15) < 30) {
                continue;
            }
            $allLive = array_merge($allLive, self::replayStrategy($srl, $s15, $cfgLive, $inst, $streamStart, $replayEnd, $pairSchedule, true));
            $allAuth = array_merge($allAuth, self::replayStrategy($srl, $s15, $cfgAuthor, $inst, $streamStart, $replayEnd, $pairSchedule, true));
            $allEng = array_merge($allEng, self::replayStrategy($eng, $s15, [
                'expiry_sec' => 60,
                'eng_allow_classic' => true,
                'eng_allow_same_color' => true,
            ], $inst, $streamStart, $replayEnd, $pairSchedule, true));
            $allScan = array_merge($allScan, self::replayStrategy($srl, $s15, $cfgLive, $inst, $streamStart, $replayEnd, $pairSchedule, false));
        }

        usort($allLive, fn ($a, $b) => $a['time'] <=> $b['time']);
        usort($allAuth, fn ($a, $b) => $a['time'] <=> $b['time']);
        usort($allEng, fn ($a, $b) => $a['time'] <=> $b['time']);
        usort($allScan, fn ($a, $b) => $a['time'] <=> $b['time']);

        $coverageLimit = $replayEnd - $streamStart;
        $matchesLive = self::matchAuthorToBot($authorEntries, $allLive, $streamStart, $coverageLimit);
        $matchesAuth = self::matchAuthorToBot($authorEntries, $allAuth, $streamStart, $coverageLimit);
        $covered = array_values(array_filter($authorEntries, fn ($e) => $e['offset'] <= $coverageLimit));

        $summary = [
            'stream_id' => $streamId,
            'title' => $config['title'] ?? $streamId,
            'stream_start_utc' => $streamStart,
            'stream_start_iso' => gmdate('c', $streamStart),
            'stream_start_msk' => gmdate('Y-m-d H:i:s', $streamStart + 3 * 3600).' MSK',
            'replay_end_utc' => $replayEnd,
            'coverage_sec' => $replayEnd - $streamStart,
            'coverage_min' => round(($replayEnd - $streamStart) / 60, 1),
            'duration_sec' => $duration,
            'sync_note' => $config['sync_note'] ?? '',
            'gap_note' => $config['gap_note'] ?? null,
            'pair_meta' => $pairMeta,
            'author_entries_total' => count($authorEntries),
            'author_entries_in_coverage' => count($covered),
            'bot_live' => self::signalStats($allLive),
            'bot_live_scan_all_pairs' => self::signalStats($allScan),
            'bot_author_like' => self::signalStats($allAuth),
            'bot_engulfing' => self::signalStats($allEng),
            'alignment_live' => self::alignCounts($matchesLive),
            'alignment_author_cfg' => self::alignCounts($matchesAuth),
        ];

        foreach (['sweep', 'pullback', 'breakout'] as $mode) {
            $subset = array_values(array_filter($allLive, fn ($s) => $s['entry_mode'] === $mode));
            $summary['bot_live']['by_mode'][$mode] = [
                'n' => count($subset),
                'wr_15s' => self::wr(array_column($subset, 'result_15s')),
                'wr_60s' => self::wr(array_column($subset, 'result_60s')),
            ];
            $subsetAuth = array_values(array_filter($allAuth, fn ($s) => $s['entry_mode'] === $mode));
            $summary['bot_author_like']['by_mode'][$mode] = [
                'n' => count($subsetAuth),
                'wr_15s' => self::wr(array_column($subsetAuth, 'result_15s')),
                'wr_60s' => self::wr(array_column($subsetAuth, 'result_60s')),
            ];
        }

        return [
            'summary' => $summary,
            'author_entries' => $authorEntries,
            'signals_live' => $allLive,
            'signals_author_like' => $allAuth,
            'signals_engulfing' => $allEng,
            'signals_scan' => $allScan,
            'matches_live' => $matchesLive,
            'matches_author_like' => $matchesAuth,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $signals
     * @return array<string,mixed>
     */
    public static function signalStats(array $signals): array
    {
        return [
            'signals' => count($signals),
            'wr_15s' => self::wr(array_column($signals, 'result_15s')),
            'wr_60s' => self::wr(array_column($signals, 'result_60s')),
            'by_mode' => [],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $matches
     * @return array<string,int>
     */
    private static function alignCounts(array $matches): array
    {
        $out = ['aligned' => 0, 'side_mismatch' => 0, 'bot_miss' => 0, 'no_candles' => 0];
        foreach ($matches as $m) {
            $st = (string) ($m['status'] ?? '');
            if (isset($out[$st])) {
                $out[$st]++;
            }
        }

        return $out;
    }

    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    public static function loadS5(string $instrument, int $from, int $to): array
    {
        $rows = DB::table('candles')
            ->where('instrument', $instrument)
            ->where('timeframe', 4005)
            ->whereBetween('time', [$from, $to])
            ->orderBy('time')
            ->get(['time', 'open', 'high', 'low', 'close', 'volume']);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'time' => (int) $r->time,
                'open' => (float) $r->open,
                'high' => (float) $r->high,
                'low' => (float) $r->low,
                'close' => (float) $r->close,
                'volume' => (float) ($r->volume ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $s5
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    public static function aggregateS15(array $s5): array
    {
        $buckets = [];
        foreach ($s5 as $c) {
            $t = (int) (floor($c['time'] / 15) * 15);
            if (! isset($buckets[$t])) {
                $buckets[$t] = [
                    'time' => $t,
                    'open' => $c['open'],
                    'high' => $c['high'],
                    'low' => $c['low'],
                    'close' => $c['close'],
                    'volume' => $c['volume'],
                    '_n' => 1,
                ];
            } else {
                $buckets[$t]['high'] = max($buckets[$t]['high'], $c['high']);
                $buckets[$t]['low'] = min($buckets[$t]['low'], $c['low']);
                $buckets[$t]['close'] = $c['close'];
                $buckets[$t]['volume'] += $c['volume'];
                $buckets[$t]['_n']++;
            }
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $b) {
            if ($b['_n'] < 2) {
                continue;
            }
            unset($b['_n']);
            $out[] = $b;
        }

        return $out;
    }

    /**
     * @param  list<array{0:int,1:string}>|list<array{max_offset:int,instrument:string}>  $schedule
     */
    public static function pairAtOffset(int $offsetSec, array $schedule): string
    {
        if ($schedule === []) {
            return 'UNKNOWN';
        }
        foreach ($schedule as $row) {
            if (isset($row['max_offset'])) {
                if ($offsetSec < (int) $row['max_offset']) {
                    return (string) $row['instrument'];
                }
            } else {
                // [max_offset, instrument]
                if ($offsetSec < (int) $row[0]) {
                    return (string) $row[1];
                }
            }
        }
        $last = $schedule[count($schedule) - 1];

        return (string) ($last['instrument'] ?? $last[1] ?? 'UNKNOWN');
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     * @param  list<mixed>  $pairSchedule
     * @return list<array{offset:int,side:?string,kind:string,text:string,pair:string}>
     */
    public static function extractAuthorEntries(array $segments, array $pairSchedule): array
    {
        $entries = [];
        foreach ($segments as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            $offset = (int) round((float) ($s['start'] ?? 0));
            if ($text === '') {
                continue;
            }
            $low = mb_strtolower($text);
            $side = null;
            if (preg_match('/на\s+повышен/u', $low) || preg_match('/сделк\w*\s+на\s+повыш/u', $low)) {
                $side = 'CALL';
            } elseif (preg_match('/на\s+понижен/u', $low) || preg_match('/сделк\w*\s+на\s+пониж/u', $low)) {
                $side = 'PUT';
            }
            $isEntry = $side !== null && preg_match('/сделк|открыл|берём|берем|зайд|заход|разделк|разелку/u', $low);
            $isTouch = (bool) preg_match('/касани|прикос/u', $low);
            $isBreak = (bool) preg_match('/пробой|закреп/u', $low);
            if (! $isEntry && ! ($isTouch && $side !== null)) {
                continue;
            }
            $kind = 'entry';
            if ($isTouch) {
                $kind = 'touch_fade';
            } elseif ($isBreak) {
                $kind = 'breakout_talk';
            }
            $entries[] = [
                'offset' => $offset,
                'side' => $side,
                'kind' => $kind,
                'text' => $text,
                'pair' => self::pairAtOffset($offset, $pairSchedule),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $candles
     */
    public static function binaryResult(array $candles, int $barIndex, string $side, int $expirySec): ?string
    {
        if (! isset($candles[$barIndex])) {
            return null;
        }
        $entry = (float) $candles[$barIndex]['close'];
        $targetTime = (int) $candles[$barIndex]['time'] + $expirySec;
        $exit = null;
        for ($i = $barIndex + 1, $n = count($candles); $i < $n; $i++) {
            if ((int) $candles[$i]['time'] >= $targetTime) {
                $exit = (float) $candles[$i]['close'];
                break;
            }
        }
        if ($exit === null) {
            return null;
        }
        if ($side === 'CALL') {
            return $exit > $entry ? 'win' : ($exit < $entry ? 'loss' : 'tie');
        }

        return $exit < $entry ? 'win' : ($exit > $entry ? 'loss' : 'tie');
    }

    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $candles
     * @param  array<string,mixed>  $cfg
     * @param  list<mixed>  $pairSchedule
     * @return list<array<string,mixed>>
     */
    public static function replayStrategy(
        object $strategy,
        array $candles,
        array $cfg,
        string $instrument,
        int $streamStart,
        int $streamEnd,
        array $pairSchedule,
        bool $activePairOnly,
    ): array {
        $signals = [];
        $minBars = max(20, ((int) ($cfg['srl_pivot_length'] ?? 2)) * 2 + 5);
        for ($i = $minBars; $i < count($candles); $i++) {
            $t = (int) $candles[$i]['time'];
            if ($t < $streamStart || $t > $streamEnd) {
                continue;
            }
            $offset = $t - $streamStart;
            if ($activePairOnly && self::pairAtOffset($offset, $pairSchedule) !== $instrument) {
                continue;
            }
            $slice = array_slice($candles, 0, $i + 1);
            $decision = $strategy->analyze($slice, null, [
                'config' => $cfg,
                'instrument' => $instrument,
                'payout' => 0.92,
            ]);
            if ($decision === null) {
                continue;
            }
            $side = (string) $decision['side'];
            $mode = (string) ($decision['features']['entry_mode'] ?? 'unknown');
            $signals[] = [
                'time' => $t,
                'offset' => $offset,
                'instrument' => $instrument,
                'side' => $side,
                'entry_mode' => $mode,
                'setup' => (string) ($decision['features']['setup'] ?? ''),
                'level' => (float) ($decision['features']['level_price'] ?? 0),
                'confidence' => (float) ($decision['confidence'] ?? 0),
                'result_15s' => self::binaryResult($candles, $i, $side, 15),
                'result_60s' => self::binaryResult($candles, $i, $side, 60),
                'analysis' => (string) ($decision['analysis_text'] ?? ''),
            ];
        }

        return $signals;
    }

    /**
     * @param  list<array<string,mixed>>  $authorEntries
     * @param  list<array<string,mixed>>  $signals
     * @return list<array<string,mixed>>
     */
    public static function matchAuthorToBot(array $authorEntries, array $signals, int $streamStart, int $coverageLimit): array
    {
        $rows = [];
        foreach ($authorEntries as $ae) {
            if ($ae['offset'] > $coverageLimit) {
                $rows[] = [
                    'author' => $ae,
                    'match' => null,
                    'status' => 'no_candles',
                    'note' => 'offset за пределами покрытия OHLC',
                ];

                continue;
            }
            $target = $streamStart + (int) $ae['offset'];
            $best = null;
            $bestDt = PHP_INT_MAX;
            foreach ($signals as $sig) {
                if ($sig['instrument'] !== $ae['pair']) {
                    continue;
                }
                $dt = abs((int) $sig['time'] - $target);
                if ($dt < $bestDt) {
                    $bestDt = $dt;
                    $best = $sig;
                }
            }
            if ($best === null || $bestDt > self::MATCH_WINDOW_SEC) {
                $rows[] = [
                    'author' => $ae,
                    'match' => null,
                    'status' => 'bot_miss',
                    'nearest_dt' => $best === null ? null : $bestDt,
                    'nearest' => $best,
                ];

                continue;
            }
            $sideOk = ($ae['side'] === null) || ($ae['side'] === $best['side']);
            $rows[] = [
                'author' => $ae,
                'match' => $best,
                'status' => $sideOk ? 'aligned' : 'side_mismatch',
                'dt_sec' => $bestDt,
            ];
        }

        return $rows;
    }

    /** @param  list<?string>  $results */
    public static function wr(array $results): ?float
    {
        $decided = array_values(array_filter($results, fn ($r) => $r === 'win' || $r === 'loss'));
        if ($decided === []) {
            return null;
        }
        $wins = count(array_filter($decided, fn ($r) => $r === 'win'));

        return round(100.0 * $wins / count($decided), 1);
    }

    /**
     * Пишет markdown-отчёт.
     *
     * @param  array<string,mixed>  $result
     */
    public static function writeReport(string $path, array $result): void
    {
        $s = $result['summary'];
        $al = $s['alignment_live'];
        $cov = max(1, (int) $s['author_entries_in_coverage']);
        $hitRate = round(100 * (($al['aligned'] ?? 0) + ($al['side_mismatch'] ?? 0)) / $cov, 1);
        $md = [];
        $md[] = '# '.$s['stream_id'].' бар-в-бар replay vs бот';
        $md[] = '';
        $md[] = 'Стрим: https://www.youtube.com/watch?v='.$s['stream_id'];
        if (! empty($s['title'])) {
            $md[] = '';
            $md[] = '**'.$s['title'].'**';
        }
        $md[] = '';
        $md[] = '## Синхронизация';
        $md[] = '';
        $md[] = '| Параметр | Значение |';
        $md[] = '|----------|----------|';
        $md[] = '| Старт | **'.$s['stream_start_msk'].'** / '.$s['stream_start_iso'].' |';
        $md[] = '| Покрытие OHLC | **'.$s['coverage_min'].' мин** из ~'.round($s['duration_sec'] / 60).' мин |';
        $md[] = '| ТФ | S5→**S15** |';
        if (! empty($s['sync_note'])) {
            $md[] = '| Sync | '.$s['sync_note'].' |';
        }
        if (! empty($s['gap_note'])) {
            $md[] = '| Gap | '.$s['gap_note'].' |';
        }
        $md[] = '';
        $md[] = '## Вердикт';
        $md[] = '';
        $md[] = '- Голосовых/ручных входов в покрытии: **'.$s['author_entries_in_coverage'].'** / '.$s['author_entries_total'].'.';
        $md[] = '- Бот #40 active-pair: **'.$s['bot_live']['signals'].'** · WR@15с **'.($s['bot_live']['wr_15s'] ?? 'n/a').'%** · WR@60с **'.($s['bot_live']['wr_60s'] ?? 'n/a').'%**.';
        $md[] = '- Сканер всех пар конфига: **'.$s['bot_live_scan_all_pairs']['signals'].'**.';
        $md[] = '- Alignment (±'.self::MATCH_WINDOW_SEC.'с): aligned **'.($al['aligned'] ?? 0).'**, side≠ **'.($al['side_mismatch'] ?? 0).'**, miss **'.($al['bot_miss'] ?? 0).'** → hit ~**'.$hitRate.'%**.';
        $md[] = '- Author-like P0 (expiry 15 + breakout + gap + exclusive + PB-sweeps): **'.$s['bot_author_like']['signals'].'** · WR@15с **'.($s['bot_author_like']['wr_15s'] ?? 'n/a').'%** · WR@60с **'.($s['bot_author_like']['wr_60s'] ?? 'n/a').'%**.';
        $aa = $s['alignment_author_cfg'] ?? [];
        if ($aa !== []) {
            $md[] = '- Alignment author-cfg: aligned **'.($aa['aligned'] ?? 0).'**, side≠ **'.($aa['side_mismatch'] ?? 0).'**, miss **'.($aa['bot_miss'] ?? 0).'**.';
        }
        $md[] = '- Engulfing: **'.$s['bot_engulfing']['signals'].'** · WR@15с **'.($s['bot_engulfing']['wr_15s'] ?? 'n/a').'%**.';
        $md[] = '';
        $md[] = '## Режимы SRL (active / live-cfg)';
        $md[] = '';
        $md[] = '| Mode | N | WR@15s | WR@60s |';
        $md[] = '|------|---|--------|--------|';
        foreach ($s['bot_live']['by_mode'] as $mode => $st) {
            $md[] = '| '.$mode.' | '.$st['n'].' | '.($st['wr_15s'] ?? '—').' | '.($st['wr_60s'] ?? '—').' |';
        }
        $authModes = $s['bot_author_like']['by_mode'] ?? [];
        if ($authModes !== []) {
            $md[] = '';
            $md[] = '## Режимы SRL (author-like)';
            $md[] = '';
            $md[] = '| Mode | N | WR@15s | WR@60s |';
            $md[] = '|------|---|--------|--------|';
            foreach ($authModes as $mode => $st) {
                $md[] = '| '.$mode.' | '.$st['n'].' | '.($st['wr_15s'] ?? '—').' | '.($st['wr_60s'] ?? '—').' |';
            }
        }
        $md[] = '';
        $md[] = '## Автор ↔ бот';
        $md[] = '';
        $md[] = '| t | Пара | Автор | Статус | Бот | Δs |';
        $md[] = '|---|------|-------|--------|-----|----|';
        foreach ($result['matches_live'] as $m) {
            if (($m['status'] ?? '') === 'no_candles') {
                continue;
            }
            $a = $m['author'];
            $mm = (int) floor($a['offset'] / 60);
            $ss = $a['offset'] % 60;
            if ($m['match'] !== null) {
                $bot = ($m['match']['entry_mode'] ?? '?').'/'.($m['match']['side'] ?? '?');
            } elseif (($m['nearest'] ?? null) !== null) {
                $n = $m['nearest'];
                $bot = ($n['entry_mode'] ?? '?').'/'.($n['side'] ?? '?').' (nearest '.$m['nearest_dt'].'s)';
            } else {
                $bot = '—';
            }
            $dt = $m['dt_sec'] ?? ($m['nearest_dt'] ?? '—');
            $md[] = sprintf(
                '| %02d:%02d | %s | %s %s | %s | %s | %s |',
                $mm,
                $ss,
                str_replace('_otc', '', $a['pair']),
                $a['side'] ?? '—',
                $a['kind'],
                $m['status'],
                $bot,
                (string) $dt,
            );
        }
        $md[] = '';
        file_put_contents($path, implode("\n", $md)."\n");
    }
}
