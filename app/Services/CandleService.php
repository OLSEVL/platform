<?php

namespace App\Services;

use App\Models\InstrumentCandle;
use Illuminate\Support\Collection;

class CandleService
{
    /**
     * Минутные таймфреймы для дашборда (legacy режим, как было раньше).
     *
     * Важно: значения 5/15/30 здесь означают МИНУТЫ.
     * Чтобы не путать их с секундами, для секундных баров используем отдельные ID (S5/S15/S30 ниже).
     */
    public const DASHBOARD_MINUTE_TIMEFRAMES = [1, 2, 3, 5, 10, 15, 30, 60, 240];

    /**
     * Секундные таймфреймы (внутренние ID, чтобы не конфликтовать с "минутами").
     *
     * Схема ID:
     * - 4005 -> S5
     * - 4015 -> S15
     * - 4030 -> S30
     */
    public const TF_S5 = 4005;
    public const TF_S15 = 4015;
    public const TF_S30 = 4030;
    public const DASHBOARD_SECOND_TIMEFRAMES = [self::TF_S5, self::TF_S15, self::TF_S30];
    public const M1_SECONDS = 60;

    /** Полный список таймфреймов, доступных в API графика. */
    public const DASHBOARD_TIMEFRAMES = [
        ...self::DASHBOARD_MINUTE_TIMEFRAMES,
        ...self::DASHBOARD_SECOND_TIMEFRAMES,
    ];

    public static function isDashboardTimeframe(int $minutes): bool
    {
        return in_array($minutes, self::DASHBOARD_TIMEFRAMES, true);
    }

    /**
     * Свечи для графика: M1 строится из S5 (fallback: legacy timeframe=1),
     * остальные минутные TF агрегируются из M1-источника.
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
        // S5: читаем как "базовые" свечи напрямую из candles.timeframe=4005.
        // Это даёт максимально точные данные для скальперского графика.
        if ($timeframeMinutes === self::TF_S5) {
            return $this->seriesByStoredTimeframe($instrument, self::TF_S5, $sinceUnix, $beforeUnix, $maxBars);
        }

        // S15/S30: агрегируем из S5, чтобы не дублировать хранение в БД.
        if (in_array($timeframeMinutes, [self::TF_S15, self::TF_S30], true)) {
            $periodSeconds = $timeframeMinutes === self::TF_S15 ? 15 : 30;
            $s5Rows = InstrumentCandle::forInstrument($instrument)->forTimeframe(self::TF_S5);
            if ($sinceUnix !== null) {
                $s5Rows->where('time', '>=', $sinceUnix);
            }
            if ($beforeUnix !== null) {
                $s5Rows->where('time', '<', $beforeUnix);
            }
            // Критично: для построения "живого" графика нужны ПОСЛЕДНИЕ S5 свечи,
            // а не самые ранние в таблице. Поэтому берём desc + limit и затем
            // разворачиваем в возрастающий порядок времени перед агрегацией.
            $rows = $s5Rows
                ->orderByDesc('time')
                ->limit(15000)
                ->get(['time', 'open', 'high', 'low', 'close', 'volume'])
                ->reverse()
                ->values();
            $agg = $this->aggregateBySecondsCollection($rows, $periodSeconds);
            if ($maxBars !== null && count($agg) > $maxBars) {
                $agg = array_slice($agg, -$maxBars);
            }

            return $agg;
        }

        // Все минутные TF теперь считаем от унифицированного M1-источника,
        // чтобы график, боты и API работали по одному и тому же ряду.
        $m1 = $this->m1SeriesForDashboard($instrument, $sinceUnix, $beforeUnix, null);
        if ($timeframeMinutes === 1) {
            $agg = $m1;
        } else {
            $agg = $this->aggregateM1Array($m1, $timeframeMinutes);
        }
        if ($maxBars !== null && count($agg) > $maxBars) {
            $agg = array_slice($agg, -$maxBars);
        }

        return $agg;
    }

    /**
     * Возвращает ряд по "сырому" timeframe ID из candles без дополнительной агрегации.
     *
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function seriesByStoredTimeframe(
        string $instrument,
        int $timeframeId,
        ?int $sinceUnix,
        ?int $beforeUnix,
        ?int $maxBars,
    ): array {
        $q = InstrumentCandle::forInstrument($instrument)->forTimeframe($timeframeId)->orderByDesc('time');
        if ($sinceUnix !== null) {
            $q->where('time', '>=', $sinceUnix);
        }
        if ($beforeUnix !== null) {
            $q->where('time', '<', $beforeUnix);
        }
        $rows = $q->limit($maxBars ?? 5000)->get(['time', 'open', 'high', 'low', 'close', 'volume']);
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
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function m1SeriesForDashboard(
        string $instrument,
        ?int $sinceUnix,
        ?int $beforeUnix,
        ?int $maxBars,
    ): array {
        // M1 приоритетно строим из S5.
        $s5Rows = InstrumentCandle::forInstrument($instrument)->forTimeframe(self::TF_S5);
        if ($sinceUnix !== null) {
            $s5Rows->where('time', '>=', $sinceUnix);
        }
        if ($beforeUnix !== null) {
            $s5Rows->where('time', '<', $beforeUnix);
        }
        // Аналогично: M1 собираем из самых новых S5, чтобы серия совпадала с площадкой.
        $s5Rows = $s5Rows
            ->orderByDesc('time')
            ->limit(15000)
            ->get(['time', 'open', 'high', 'low', 'close', 'volume'])
            ->reverse()
            ->values();
        if ($s5Rows->isNotEmpty()) {
            $m1 = $this->aggregateBySecondsCollection($s5Rows, self::M1_SECONDS);
            if ($maxBars !== null && count($m1) > $maxBars) {
                $m1 = array_slice($m1, -$maxBars);
            }

            return $m1;
        }

        // Fallback на старый ряд timeframe=1 для совместимости/исторических данных.
        return $this->m1SeriesFromLegacyTimeframe($instrument, $sinceUnix, $beforeUnix, $maxBars);
    }

    /**
     * Legacy M1 из candles.timeframe=1 (временный fallback во время миграции).
     *
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function m1SeriesFromLegacyTimeframe(
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
        $rows = $q->limit($maxBars ?? 5000)->get(['time', 'open', 'high', 'low', 'close', 'volume']);
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
     * Агрегация минутных свечей из уже нормализованного массива.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>  $m1Rows
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    public function aggregateM1Array(array $m1Rows, int $timeframeMinutes): array
    {
        $periodSeconds = $timeframeMinutes * 60;
        $groups = [];
        foreach ($m1Rows as $r) {
            $key = (int) (floor($r['time'] / $periodSeconds) * $periodSeconds);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'time' => $key,
                    'open' => (float) $r['open'],
                    'high' => (float) $r['high'],
                    'low' => (float) $r['low'],
                    'close' => (float) $r['close'],
                    'volume' => (float) ($r['volume'] ?? 0),
                ];
                continue;
            }
            $g = &$groups[$key];
            $g['high'] = max($g['high'], (float) $r['high']);
            $g['low'] = min($g['low'], (float) $r['low']);
            $g['close'] = (float) $r['close'];
            $g['volume'] += (float) ($r['volume'] ?? 0);
        }
        $result = array_values($groups);
        usort($result, fn ($a, $b) => $a['time'] <=> $b['time']);

        return $result;
    }

    /**
     * Универсальная агрегация свечей по секундному бакету.
     *
     * Используется для S15/S30 из S5:
     * - key = floor(time / periodSeconds) * periodSeconds
     * - open = open первой свечи бакета
     * - high/low = экстремумы бакета
     * - close = close последней свечи бакета
     * - volume = сумма volume внутри бакета
     *
     * @param  Collection<int, InstrumentCandle>|array<int, InstrumentCandle>  $rows
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function aggregateBySecondsCollection(Collection|array $rows, int $periodSeconds): array
    {
        $periodSeconds = max(1, $periodSeconds);
        $groups = [];
        foreach ($rows as $r) {
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
                continue;
            }

            $g = &$groups[$key];
            $g['high'] = max($g['high'], (float) $r->high);
            $g['low'] = min($g['low'], (float) $r->low);
            $g['close'] = (float) $r->close;
            $g['volume'] += (float) ($r->volume ?? 0);
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
        // Свежесть определяем приоритетно по S5; fallback на legacy M1.
        $latest = InstrumentCandle::forInstrument($instrument)->forTimeframe(self::TF_S5)->orderByDesc('time')->first();
        if (! $latest) {
            $latest = InstrumentCandle::forInstrument($instrument)->forTimeframe(1)->orderByDesc('time')->first();
        }

        return $latest && $latest->time >= time() - ($maxAgeMinutes * 60);
    }

    private function getM1(string $instrument, int $count): array
    {
        return $this->m1SeriesForDashboard($instrument, null, null, $count);
    }

    private function aggregateM1(string $instrument, int $timeframe, int $count): array
    {
        $m1Limit = $count * $timeframe + 50;
        $m1Rows = $this->m1SeriesForDashboard($instrument, null, null, $m1Limit);
        $result = $this->aggregateM1Array($m1Rows, $timeframe);

        return array_slice($result, -$count);
    }
}
