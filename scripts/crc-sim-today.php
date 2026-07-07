<?php

/**
 * Replay Candle Reversal Confirm for today: current vs relaxed params.
 * Uses CandleService (S5→M1) and CandleReversalMtfConfirmStrategy.
 *
 * Usage: php scripts/crc-sim-today.php
 */

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\PocketInstrument;
use App\Services\Bots\Strategies\CandleReversalMtfConfirmStrategy;
use App\Services\CandleService;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$bot = Bot::find(11);
if (! $bot) {
    fwrite(STDERR, "Bot #11 not found\n");
    exit(1);
}

$baseCfg = is_array($bot->config) ? $bot->config : [];
$minPayout = (float) ($baseCfg['min_payout'] ?? 80);
$minConfidence = (float) ($baseCfg['min_confidence'] ?? 0.56);
$dedupeSec = 300;

$presets = [
    'current' => $baseCfg,
    'relaxed' => array_merge($baseCfg, [
        'm5_reversal_min_wick_ratio' => 0.25,
        'm1_min_body_ratio' => 0.18,
    ]),
];

$candles = app(CandleService::class);
$strategy = new CandleReversalMtfConfirmStrategy;

$dayStart = now()->startOfDay()->timestamp;
$since = $dayStart - 8 * 3600;

$instruments = PocketInstrument::query()
    ->where('is_active', 1)
    ->where('current_payout', '>=', $minPayout)
    ->orderBy('instrument')
    ->get(['instrument', 'current_payout']);

/** @var array<string, list<array<string,mixed>>> $m1ByIns */
$m1ByIns = [];
/** @var array<string, float> $payoutByIns */
$payoutByIns = [];

foreach ($instruments as $row) {
    $ins = (string) $row->instrument;
    $payoutByIns[$ins] = (float) $row->current_payout;
    $raw = $candles->seriesForDashboard($ins, 1, $since, null, 2500);
    $m1ByIns[$ins] = array_map(static fn (array $r) => [
        'time' => (int) $r['time'],
        'open' => (float) $r['open'],
        'high' => (float) $r['high'],
        'low' => (float) $r['low'],
        'close' => (float) $r['close'],
        'volume' => (float) ($r['volume'] ?? 0),
    ], $raw);
}

/**
 * @param  list<array<string,mixed>>  $m1
 * @return list<array<string,mixed>>
 */
function aggregateM5(array $m1): array
{
    $buckets = [];
    foreach ($m1 as $bar) {
        $bk = intdiv((int) $bar['time'], 300) * 300;
        if (! isset($buckets[$bk])) {
            $buckets[$bk] = [
                'time' => $bk,
                'open' => (float) $bar['open'],
                'high' => (float) $bar['high'],
                'low' => (float) $bar['low'],
                'close' => (float) $bar['close'],
                'volume' => (float) ($bar['volume'] ?? 0),
            ];
        } else {
            $buckets[$bk]['high'] = max($buckets[$bk]['high'], (float) $bar['high']);
            $buckets[$bk]['low'] = min($buckets[$bk]['low'], (float) $bar['low']);
            $buckets[$bk]['close'] = (float) $bar['close'];
            $buckets[$bk]['volume'] += (float) ($bar['volume'] ?? 0);
        }
    }
    ksort($buckets);

    return array_values($buckets);
}

/**
 * @param  list<array<string,mixed>>  $m1
 * @return array{closed:list<array<string,mixed>>,m5:list<array<string,mixed>>}|null
 */
function sliceAt(array $m1, int $beforeUnix): ?array
{
    $closed = [];
    foreach ($m1 as $bar) {
        if ((int) $bar['time'] >= $beforeUnix) {
            break;
        }
        $closed[] = $bar;
    }
    if (count($closed) < 60) {
        return null;
    }
    $m5 = aggregateM5($closed);
    if (count($m5) < 3) {
        return null;
    }
    if (count($m5) > 60) {
        $m5 = array_slice($m5, -60);
    }

    return ['closed' => $closed, 'm5' => $m5];
}

$results = ['current' => [], 'relaxed' => []];
$dedupe = ['current' => [], 'relaxed' => []];

// Уникальные моменты закрытия M1 за сегодня (replay «как если бы scan был на каждом закрытии»).
$closeTimes = [];
foreach ($m1ByIns as $m1) {
    foreach ($m1 as $bar) {
        $t = (int) $bar['time'];
        if ($t < $dayStart) {
            continue;
        }
        $closeTimes[$t + 60] = true;
    }
}
ksort($closeTimes);

foreach (array_keys($closeTimes) as $beforeUnix) {
    foreach ($m1ByIns as $ins => $m1) {
        $slice = sliceAt($m1, $beforeUnix);
        if ($slice === null) {
            continue;
        }
        $payout = $payoutByIns[$ins] ?? 80.0;
        foreach (['current', 'relaxed'] as $presetName) {
            $cfg = $presets[$presetName];
            $ctx = [
                'config' => $cfg,
                'mtf' => ['5' => $slice['m5']],
                'payout' => $payout,
            ];
            $decision = $strategy->analyze($slice['closed'], null, $ctx);
            if (! $decision) {
                continue;
            }
            if ((float) ($decision['confidence'] ?? 0) < $minConfidence) {
                continue;
            }
            $side = (string) $decision['side'];
            $bucket = intdiv($beforeUnix, $dedupeSec) * $dedupeSec;
            $key = $ins.':'.$bucket.':'.$side;
            if (isset($dedupe[$presetName][$key])) {
                continue;
            }
            $dedupe[$presetName][$key] = true;
            $results[$presetName][] = [
                'time' => $beforeUnix,
                'hm' => date('H:i', $beforeUnix),
                'instrument' => $ins,
                'side' => $side,
            ];
        }
    }
}

$actual = BotSignal::query()
    ->where('bot_id', 11)
    ->where('signal_at', '>=', now()->startOfDay())
    ->orderBy('signal_at')
    ->get(['signal_at', 'instrument', 'side']);

echo '=== CRC simulation '.now()->toDateString()." ===\n";
echo 'Source: S5→M1 via CandleService, real CandleReversalMtfConfirmStrategy'."\n";
echo 'Replay: on each M1 close, all pairs, dedupe '.$dedupeSec."s\n";
echo 'Pairs payout>='.$minPayout.': '.$instruments->count()."\n\n";

echo 'ACTUAL in DB: '.$actual->count()."\n";
foreach ($actual as $s) {
    echo '  '.$s->signal_at->format('H:i').' '.$s->instrument.' '.$s->side."\n";
}

foreach (['current', 'relaxed'] as $presetName) {
    $m5w = $presetName === 'current'
        ? ($baseCfg['m5_reversal_min_wick_ratio'] ?? 0.30)
        : 0.25;
    $m1b = $presetName === 'current'
        ? ($baseCfg['m1_min_body_ratio'] ?? 0.25)
        : 0.18;
    echo "\n".strtoupper($presetName)." (m5_wick={$m5w}, m1_body={$m1b}): ".count($results[$presetName])."\n";
    foreach ($results[$presetName] as $e) {
        echo '  '.$e['hm'].' '.$e['instrument'].' '.$e['side']."\n";
    }
}

$match = static function (array $sim, $actualRows, int $tolMin = 6): array {
    $hits = 0;
    $miss = [];
    foreach ($actualRows as $s) {
        $ts = $s->signal_at->timestamp;
        $found = false;
        foreach ($sim as $e) {
            if ($e['instrument'] !== $s->instrument || $e['side'] !== $s->side) {
                continue;
            }
            if (abs($e['time'] - $ts) <= $tolMin * 60) {
                $found = true;
                break;
            }
        }
        if ($found) {
            $hits++;
        } else {
            $miss[] = $s->signal_at->format('H:i').' '.$s->instrument.' '.$s->side;
        }
    }

    return [$hits, $miss];
};

[$hCur, $missCur] = $match($results['current'], $actual);
[$hRel, $missRel] = $match($results['relaxed'], $actual);

echo "\nOverlap with actual (±6 min): current {$hCur}/".$actual->count().", relaxed {$hRel}/".$actual->count()."\n";
if ($missCur !== []) {
    echo 'Unmatched (current): '.implode('; ', $missCur)."\n";
}

$curKeys = array_map(static fn (array $e) => $e['hm'].$e['instrument'].$e['side'], $results['current']);
$onlyRelaxed = array_filter($results['relaxed'], static fn (array $e) => ! in_array($e['hm'].$e['instrument'].$e['side'], $curKeys, true));

echo "\nOnly with RELAXED (+".(count($results['relaxed']) - count($results['current']))."): ".count($onlyRelaxed)."\n";
foreach ($onlyRelaxed as $e) {
    echo '  + '.$e['hm'].' '.$e['instrument'].' '.$e['side']."\n";
}

// Краткая сводка для принятия решения.
echo "\n--- Summary ---\n";
echo 'Theoretical signals today: current '.count($results['current']).', relaxed '.count($results['relaxed']);
echo ' (+'.(count($results['relaxed']) - count($results['current'])).", +";
$pct = count($results['current']) > 0
    ? round(100 * (count($results['relaxed']) - count($results['current'])) / count($results['current']))
    : 0;
echo $pct."%)\n";
echo 'Live bot produced '.$actual->count().' — scan is sequential (~25 pairs/cycle), not every M1 close.'."\n";
