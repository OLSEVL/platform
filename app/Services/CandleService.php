<?php

namespace App\Services;

use App\Models\InstrumentCandle;

class CandleService
{
    /**
     * @return array{ candles: array, instrument: string, timeframe: string }
     */
    public function getCandles(string $instrument, int $timeframe, int $count = 100): array
    {
        $rows = $timeframe === 1
            ? $this->getM1($instrument, $count)
            : $this->aggregateM1($instrument, $timeframe, $count);

        $candles = [];
        foreach ($rows as $r) {
            $candles[] = [
                'time' => (int) $r['time'],
                'open' => (float) $r['open'],
                'high' => (float) $r['high'],
                'low' => (float) $r['low'],
                'close' => (float) $r['close'],
                'volume' => (float) ($r['volume'] ?? 0),
            ];
        }
        return ['candles' => $candles, 'instrument' => $instrument, 'timeframe' => (string) $timeframe];
    }

    public function hasFreshData(string $instrument, int $maxAgeMinutes = 5): bool
    {
        $latest = InstrumentCandle::forInstrument($instrument)->forTimeframe(1)->orderByDesc('time')->first();
        return $latest && $latest->time >= time() - ($maxAgeMinutes * 60);
    }

    private function getM1(string $instrument, int $count): array
    {
        $rows = InstrumentCandle::forInstrument($instrument)->forTimeframe(1)->orderByDesc('time')->limit($count)->get();
        $out = $rows->map(fn ($r) => ['time' => $r->time, 'open' => $r->open, 'high' => $r->high, 'low' => $r->low, 'close' => $r->close, 'volume' => $r->volume])->all();
        usort($out, fn ($a, $b) => $a['time'] <=> $b['time']);
        return $out;
    }

    private function aggregateM1(string $instrument, int $timeframe, int $count): array
    {
        $periodSeconds = $timeframe * 60;
        $m1Limit = $count * $timeframe + 50;
        $m1Rows = InstrumentCandle::forInstrument($instrument)->forTimeframe(1)->orderByDesc('time')->limit($m1Limit)->get()->sortBy('time')->values();
        $groups = [];
        foreach ($m1Rows as $r) {
            $key = (int) (floor($r->time / $periodSeconds) * $periodSeconds);
            if (! isset($groups[$key])) {
                $groups[$key] = ['time' => $key, 'open' => (float) $r->open, 'high' => (float) $r->high, 'low' => (float) $r->low, 'close' => (float) $r->close, 'volume' => (float) $r->volume];
            } else {
                $g = &$groups[$key];
                $g['high'] = max($g['high'], (float) $r->high);
                $g['low'] = min($g['low'], (float) $r->low);
                $g['close'] = (float) $r->close;
                $g['volume'] += (float) $r->volume;
            }
        }
        $result = array_values($groups);
        usort($result, fn ($a, $b) => $a['time'] <=> $b['time']);
        return array_slice($result, -$count);
    }
}
