<?php

namespace App\Services;

use App\Models\InstrumentCandle;
use Illuminate\Support\Collection;

class CandleService
{
    /** Минуты таймфрейма для дашборда (1ч = 60, 4ч = 240) */
    public const DASHBOARD_TIMEFRAMES = [1, 2, 3, 5, 10, 15, 30, 60, 240];

    public static function isDashboardTimeframe(int $minutes): bool
    {
        return in_array($minutes, self::DASHBOARD_TIMEFRAMES, true);
    }

    /**
     * Свечи для графика: M1 из БД или агрегат из M1. Порядок по time по возрастанию.
     *
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function seriesForDashboard(
        string $instrument,
        int $timeframeMinutes,
        ?int $sinceUnix,
        ?int $beforeUnix,
        ?int $maxBars,
    ): array {
        if ($timeframeMinutes === 1) {
            return $this->m1SeriesForDashboard($instrument, $sinceUnix, $beforeUnix, $maxBars);
        }

        $q = InstrumentCandle::forInstrument($instrument)->forTimeframe(1);
        if ($sinceUnix !== null) {
            $q->where('time', '>=', $sinceUnix);
        }
        if ($beforeUnix !== null) {
            $q->where('time', '<', $beforeUnix);
        }
        $m1Rows = $q->orderBy('time')->limit(5000)->get(['time', 'open', 'high', 'low', 'close', 'volume']);
        $agg = $this->aggregateM1Collection($m1Rows, $timeframeMinutes);
        if ($maxBars !== null && count($agg) > $maxBars) {
            $agg = array_slice($agg, -$maxBars);
        }

        return $agg;
    }

    /**
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function m1SeriesForDashboard(
        string $instrument,
        ?int $sinceUnix,
        ?int $beforeUnix,
        ?int $maxBars,
    ): array {
        $q = InstrumentCandle::forInstrument($instrument)->forTimeframe(1)->orderByDesc('time');
        if ($sinceUnix !== null) {
            $q->where('time', '>=', $sinceUnix);
        }
        if ($beforeUnix !== null) {
            $q->where('time', '<', $beforeUnix);
        }
        $limit = $maxBars ?? 5000;
        $rows = $q->limit($limit)->get(['time', 'open', 'high', 'low', 'close', 'volume']);
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
        usort($out, fn ($a, $b) => $a['time'] <=> $b['time']);

        return $out;
    }

    /**
     * @param  Collection<int, InstrumentCandle>|array<int, InstrumentCandle>  $m1Rows  по возрастанию time
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function aggregateM1Collection(Collection|array $m1Rows, int $timeframeMinutes): array
    {
        $periodSeconds = $timeframeMinutes * 60;
        $groups = [];
        foreach ($m1Rows as $r) {
            $key = (int) (floor($r->time / $periodSeconds) * $periodSeconds);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'time' => $key,
                    'open' => (float) $r->open,
                    'high' => (float) $r->high,
                    'low' => (float) $r->low,
                    'close' => (float) $r->close,
                    'volume' => (float) ($r->volume ?? 0),
                ];
            } else {
                $g = &$groups[$key];
                $g['high'] = max($g['high'], (float) $r->high);
                $g['low'] = min($g['low'], (float) $r->low);
                $g['close'] = (float) $r->close;
                $g['volume'] += (float) ($r->volume ?? 0);
            }
        }
        $result = array_values($groups);
        usort($result, fn ($a, $b) => $a['time'] <=> $b['time']);

        return $result;
    }

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
        $m1Limit = $count * $timeframe + 50;
        $m1Rows = InstrumentCandle::forInstrument($instrument)->forTimeframe(1)->orderByDesc('time')->limit($m1Limit)->get()->sortBy('time')->values();
        $result = $this->aggregateM1Collection($m1Rows, $timeframe);

        return array_slice($result, -$count);
    }
}
