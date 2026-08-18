<?php

namespace App\Services;

use App\Models\InstrumentCandle;
use App\Support\FeedTimeNormalizer;
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
     * Длина бара в секундах для dashboard-TF (1 = M1, 5 = M5, 4030 = S30).
     */
    public static function periodSecondsForDashboardTf(int $timeframe): ?int
    {
        return match ($timeframe) {
            self::TF_S5 => 5,
            self::TF_S15 => 15,
            self::TF_S30 => 30,
            default => in_array($timeframe, self::DASHBOARD_MINUTE_TIMEFRAMES, true)
                ? max(60, $timeframe * 60)
                : null,
        };
    }

    /**
     * Несколько ТФ из одной выборки S5: Strong Level / CRC больше не ходят в БД 4 раза на пару.
     * H1=48 баров из S5 потребовал бы ~34k рядов — оставляем потолок 15000 (~20 ч ≈ 20 H1).
     *
     * @param  array<int, int>  $tfToMaxBars  ключ — dashboard TF, значение — сколько баров
     * @return array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     */
    public function seriesBundle(string $instrument, array $tfToMaxBars): array
    {
        $normalized = [];
        $s5Limit = 0;
        foreach ($tfToMaxBars as $tf => $maxBars) {
            $tf = (int) $tf;
            $maxBars = (int) $maxBars;
            $period = self::periodSecondsForDashboardTf($tf);
            if ($period === null || $maxBars <= 0) {
                continue;
            }
            $normalized[$tf] = $maxBars;
            $s5Limit = max($s5Limit, $this->s5FetchLimit($period, $maxBars));
        }
        if ($normalized === []) {
            return [];
        }

        $s5Rows = $this->fetchRecentS5($instrument, null, null, $s5Limit, true);
        $remapped = $s5Rows->isNotEmpty()
            ? $this->remapS5RowsForAggregation($s5Rows)
            : [];

        $legacyM1 = null;
        $out = [];
        foreach ($normalized as $tf => $maxBars) {
            $period = self::periodSecondsForDashboardTf($tf);
            if ($period === null) {
                continue;
            }
            if ($remapped !== []) {
                $agg = $this->aggregateBySecondsCollection($remapped, $period);
            } else {
                // Нет S5 — собираем минутные ТФ из legacy timeframe=1.
                $legacyM1 ??= $this->m1SeriesFromLegacyTimeframe($instrument, null, null, null);
                $agg = $tf === 1
                    ? $legacyM1
                    : ($period >= 60 ? $this->aggregateM1Array($legacyM1, intdiv($period, 60)) : []);
            }
            if (count($agg) > $maxBars) {
                $agg = array_slice($agg, -$maxBars);
            }
            $out[(string) $tf] = $agg;
        }

        return $out;
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
            // Берём только столько последних S5, сколько нужно для maxBars — не 15000 моделей.
            $rows = $this->fetchRecentS5(
                $instrument,
                $sinceUnix,
                $beforeUnix,
                $this->s5FetchLimit($periodSeconds, $maxBars),
                false,
            );
            $agg = $this->aggregateBySecondsCollection(
                $this->remapS5RowsForAggregation($rows),
                $periodSeconds,
            );
            if ($maxBars !== null && count($agg) > $maxBars) {
                $agg = array_slice($agg, -$maxBars);
            }

            return $agg;
        }

        // Все минутные TF считаем от одного M1-источника (график, боты, API).
        // maxBars прокидываем внутрь, иначе M1 всё равно строится из 15000 S5 и режется уже после.
        $m1Needed = $timeframeMinutes === 1
            ? $maxBars
            : ($maxBars !== null ? $maxBars * max(1, $timeframeMinutes) : null);
        $m1 = $this->m1SeriesForDashboard($instrument, $sinceUnix, $beforeUnix, $m1Needed);
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
     * Сколько последних S5 взять из БД, чтобы после агрегации набрать $maxBars.
     * Запас 240 баров закрывает неполный крайний бакет. Потолок 15000 — для графика без maxBars.
     */
    public function s5FetchLimit(int $periodSeconds, ?int $maxBars): int
    {
        $hardCap = 15000;
        if ($maxBars === null || $maxBars <= 0) {
            return $hardCap;
        }

        $s5PerBar = max(1, intdiv(max(1, $periodSeconds), 5));

        // Один UTC-поток S5 (дубль +2h в таблице сейчас пуст). Запас 240 баров —
        // неполные бакеты и редкий remap. Потолок 15000 оставляем для графика без maxBars.
        return max(120, min($hardCap, ($maxBars * $s5PerBar) + 240));
    }

    /**
     * Последние S5 без гидрации Eloquent-моделей (stdClass).
     * Scan бота раньше грузил 15000 моделей на каждую пару (~3 с) даже при count=120.
     *
     * @return Collection<int, object>
     */
    private function fetchRecentS5(
        string $instrument,
        ?int $sinceUnix,
        ?int $beforeUnix,
        int $limit,
        bool $includeCorrectionWindow,
    ): Collection {
        $query = InstrumentCandle::query()
            ->toBase()
            ->where('instrument', $instrument)
            ->where('timeframe', self::TF_S5);

        if ($sinceUnix !== null) {
            $correction = $includeCorrectionWindow ? FeedTimeNormalizer::correctionSec() : 0;
            $query->where(function ($inner) use ($sinceUnix, $correction): void {
                $inner->where('time', '>=', $sinceUnix);
                // Параллельный поток S5 без коррекции лежит на +|correction| сек.
                if ($correction < 0) {
                    $inner->orWhere('time', '>=', $sinceUnix - $correction);
                }
            });
        }
        if ($beforeUnix !== null) {
            $query->where('time', '<', $beforeUnix);
        }

        // desc + limit — нужны последние бары, не самые ранние в таблице.
        return $query
            ->orderByDesc('time')
            ->limit(max(1, $limit))
            ->get(['time', 'open', 'high', 'low', 'close', 'volume'])
            ->reverse()
            ->values();
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
        // M1 приоритетно строим из последних S5 (лимит зависит от maxBars, не фиксированные 15000).
        $s5Rows = $this->fetchRecentS5(
            $instrument,
            $sinceUnix,
            $beforeUnix,
            $this->s5FetchLimit(self::M1_SECONDS, $maxBars),
            true,
        );
        if ($s5Rows->isNotEmpty()) {
            $m1 = $this->aggregateBySecondsCollection(
                $this->remapS5RowsForAggregation($s5Rows),
                self::M1_SECONDS,
            );
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

    /**
     * Живая свеча для графика: тот же алгоритм, что и для закрытых баров (агрегация S5 из БД + S5 partial).
     * Так M1/M5 на платформе совпадают с PO, а не с «одним срезом» 5-секундного OHLC.
     *
     * @param  array<string, mixed>  $s5Partial  S5 partial из Redis (ingest-current-candle)
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float,is_partial:bool}|null
     */
    public function liveBarFromS5Partial(string $instrument, int $timeframeMinutes, array $s5Partial): ?array
    {
        $s5Partial = FeedTimeNormalizer::normalizePartial($s5Partial);
        $partialTime = (int) ($s5Partial['effective_feed_time'] ?? ($s5Partial['time'] ?? 0));
        if ($partialTime <= 0 || FeedTimeNormalizer::isSkewedVsServer($partialTime, time())) {
            return null;
        }

        if ($timeframeMinutes === self::TF_S5) {
            $bucket = (int) (floor($partialTime / 5) * 5);

            return [
                'time' => $bucket,
                'open' => (float) ($s5Partial['open'] ?? 0),
                'high' => (float) ($s5Partial['high'] ?? 0),
                'low' => (float) ($s5Partial['low'] ?? 0),
                'close' => (float) ($s5Partial['close'] ?? 0),
                'volume' => round((float) ($s5Partial['volume'] ?? 0), 2),
                'is_partial' => true,
            ];
        }

        $periodSeconds = match ($timeframeMinutes) {
            self::TF_S15 => 15,
            self::TF_S30 => 30,
            default => max(60, $timeframeMinutes * 60),
        };
        $bucketStart = (int) (floor($partialTime / $periodSeconds) * $periodSeconds);
        $partialS5Bucket = (int) (floor($partialTime / 5) * 5);

        $s5Query = InstrumentCandle::forInstrument($instrument)
            ->forTimeframe(self::TF_S5)
            ->where(function ($query) use ($bucketStart, $periodSeconds): void {
                $query->where(function ($q) use ($bucketStart, $periodSeconds): void {
                    $q->where('time', '>=', $bucketStart)
                        ->where('time', '<', $bucketStart + $periodSeconds);
                });
                $correction = FeedTimeNormalizer::correctionSec();
                if ($correction < 0) {
                    $offset = -$correction;
                    $query->orWhere(function ($q) use ($bucketStart, $periodSeconds, $offset): void {
                        $q->where('time', '>=', $bucketStart + $offset)
                            ->where('time', '<', $bucketStart + $periodSeconds + $offset);
                    });
                }
            })
            ->orderBy('time');

        $s5Rows = $s5Query->get(['time', 'open', 'high', 'low', 'close', 'volume']);

        $rows = $this->remapS5RowsForAggregation($s5Rows);
        $rows = array_values(array_filter(
            $rows,
            static fn (object $row): bool => (int) $row->time !== $partialS5Bucket,
        ));

        $rows[] = (object) [
            'time' => $partialS5Bucket,
            'open' => (float) ($s5Partial['open'] ?? 0),
            'high' => (float) ($s5Partial['high'] ?? 0),
            'low' => (float) ($s5Partial['low'] ?? 0),
            'close' => (float) ($s5Partial['close'] ?? 0),
            'volume' => (float) ($s5Partial['volume'] ?? 0),
        ];

        if ($rows === []) {
            return null;
        }

        $aggregated = $this->aggregateBySecondsCollection($rows, $periodSeconds);
        foreach ($aggregated as $bar) {
            if ((int) ($bar['time'] ?? 0) !== $bucketStart) {
                continue;
            }

            return [
                'time' => (int) $bar['time'],
                'open' => (float) $bar['open'],
                'high' => (float) $bar['high'],
                'low' => (float) $bar['low'],
                'close' => (float) $bar['close'],
                'volume' => round((float) ($bar['volume'] ?? 0), 2),
                'is_partial' => true,
            ];
        }

        return null;
    }

    /**
     * Переносит S5-бары с «дублирующей» (+2h) оси времени на UTC-ось платформы перед агрегацией M1.
     *
     * @param  Collection<int, InstrumentCandle>|iterable<InstrumentCandle>  $rows
     * @return list<object{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    private function remapS5RowsForAggregation(Collection|iterable $rows): array
    {
        $serverNow = time();
        /** @var array<int, object{time:int,stored_time:int,open:float,high:float,low:float,close:float,volume:float}> $byS5Bucket */
        $byS5Bucket = [];
        foreach ($rows as $row) {
            $storedTime = (int) $row->time;
            $normalizedTime = FeedTimeNormalizer::normalizeStoredUnixTime($storedTime, $serverNow);
            if ($normalizedTime <= 0) {
                continue;
            }
            $s5Bucket = (int) (floor($normalizedTime / 5) * 5);
            $candidate = (object) [
                'time' => $s5Bucket,
                'stored_time' => $storedTime,
                'open' => (float) $row->open,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'close' => (float) $row->close,
                'volume' => (float) ($row->volume ?? 0),
            ];
            if (! isset($byS5Bucket[$s5Bucket])) {
                $byS5Bucket[$s5Bucket] = $candidate;

                continue;
            }
            // Два потока (скорректированный и «+2h») на один 5s bucket — берём ближе к server_unix.
            $prevDistance = abs($byS5Bucket[$s5Bucket]->stored_time - $serverNow);
            $newDistance = abs($storedTime - $serverNow);
            if ($newDistance < $prevDistance) {
                $byS5Bucket[$s5Bucket] = $candidate;
            }
        }

        return array_values($byS5Bucket);
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
