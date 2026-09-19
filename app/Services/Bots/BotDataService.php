<?php

namespace App\Services\Bots;

use App\Services\CandleService;
use App\Services\CurrentCandleCacheService;

class BotDataService
{
    public function __construct(
        private readonly CurrentCandleCacheService $currentCandleCache,
        private readonly CandleService $candleService,
    ) {}

    /**
     * @return array{closed:list<array<string,mixed>>,partial:array<string,mixed>|null,series:list<array<string,mixed>>}
     */
    public function series(
        string $instrument,
        int $count = 120,
        bool $includePartial = true,
        int $staleAfterSec = 30,
        int $timeframe = 1
    ): array
    {
        // Таймфрейм задается ботом через runtime-настройку:
        // - 1    => классический M1 поток,
        // - 4005 => секундный S5 поток для быстрых стратегий.
        // Источник единый через CandleService, чтобы график и бот видели одинаковые свечи.
        $rows = $this->candleService->seriesForDashboard($instrument, $timeframe, null, null, $count);
        // Агрегат S15/S30/M1 всегда включает текущий (ещё открытый) бакет. Стратегия и Telegram
        // должны считать DeMarker только по закрытым барам, иначе после закрытия свечи
        // значение на веб-графике уже другое, чем на PNG в момент входа.
        $rows = self::withoutOpenLastBucket($rows, $timeframe);

        $closed = array_map(static fn (array $r) => [
            'time' => (int) ($r['time'] ?? 0),
            'open' => (float) ($r['open'] ?? 0),
            'high' => (float) ($r['high'] ?? 0),
            'low' => (float) ($r['low'] ?? 0),
            'close' => (float) ($r['close'] ?? 0),
            'volume' => (float) ($r['volume'] ?? 0),
            'is_partial' => false,
        ], $rows);

        $partial = null;
        if ($includePartial) {
            $partialCandidate = $this->currentCandleCache->getPartial($instrument);
            if ($partialCandidate && $this->currentCandleCache->isFresh($partialCandidate, $staleAfterSec)) {
                $lastClosedTime = ! empty($closed) ? (int) end($closed)['time'] : 0;
                if ((int) $partialCandidate['time'] > $lastClosedTime) {
                    $partial = $partialCandidate;
                }
            }
        }

        $series = $closed;
        if ($partial) {
            $series[] = $partial;
        }

        return [
            'closed' => $closed,
            'partial' => $partial,
            'series' => $series,
        ];
    }

    /**
     * Секундный ряд S5 (timeframe=4005) из БД + живая S5-свеча из Redis (ingest-current-candle).
     *
     * @return array{closed:list<array<string,mixed>>,partial:array<string,mixed>|null,series:list<array<string,mixed>>}
     */
    public function s5Series(
        string $instrument,
        int $count = 240,
        bool $includePartial = true,
        int $staleAfterSec = 30,
    ): array {
        $rows = $this->candleService->seriesForDashboard(
            $instrument,
            CandleService::TF_S5,
            null,
            null,
            $count,
        );

        $closed = array_map(static fn (array $r) => [
            'time' => (int) ($r['time'] ?? 0),
            'open' => (float) ($r['open'] ?? 0),
            'high' => (float) ($r['high'] ?? 0),
            'low' => (float) ($r['low'] ?? 0),
            'close' => (float) ($r['close'] ?? 0),
            'volume' => (float) ($r['volume'] ?? 0),
            'is_partial' => false,
        ], $rows);

        $partial = null;
        if ($includePartial) {
            $partialCandidate = $this->currentCandleCache->getPartial($instrument);
            if ($partialCandidate && $this->currentCandleCache->isFresh($partialCandidate, $staleAfterSec)) {
                $normalized = $this->normalizePartialBar($partialCandidate);
                if ($closed === []) {
                    $partial = $normalized;
                } else {
                    $lastClosedTime = (int) end($closed)['time'];
                    $partialTime = (int) $normalized['time'];
                    if ($partialTime > $lastClosedTime) {
                        $partial = $normalized;
                    } elseif ($partialTime === $lastClosedTime) {
                        // Текущий S5-бакет уже попал в БД — обновляем OHLC живым partial.
                        $closed[array_key_last($closed)] = $normalized;
                    }
                }
            }
        }

        $series = $closed;
        if ($partial) {
            $series[] = $partial;
        }

        return [
            'closed' => $closed,
            'partial' => $partial,
            'series' => $series,
        ];
    }

    /**
     * Убирает последний бакет, если его период ещё не закончился (now < time+period).
     * Нужно для S30/S15/M1/M5: seriesForDashboard отдаёт формирующуюся свечу как обычный ряд.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function withoutOpenLastBucket(array $rows, int $timeframe, ?int $now = null): array
    {
        $period = CandleService::periodSecondsForDashboardTf($timeframe);
        if ($period === null || $rows === []) {
            return $rows;
        }
        $now ??= time();
        $last = $rows[array_key_last($rows)];
        $start = (int) ($last['time'] ?? 0);
        if ($start > 0 && ($start + $period) > $now) {
            array_pop($rows);
        }

        return $rows;
    }

    /**
     * Срез MTF без look-ahead для bar-replay.
     *
     * `time` свечи = open бакета. M5 12:00 уже содержит OHLC всего интервала 12:00–12:05.
     * Если на закрытом M1 12:02 отдать эту M5, бэктест видит тень и close, которых
     * в 12:02 ещё не было — отсюда завышенный winrate CRC/Strong Level.
     *
     * Правило: HTF попадает в срез только когда уже закрылся к close текущего бара серии:
     * `htf.time + htfPeriod <= barTs + seriesPeriod`.
     * На последней минуте M5 (M1 11:59) close M1 = close M5 = 12:00 — rejection виден,
     * вход на close пятиминутки, без будущих минут.
     *
     * Курсор двигается вперёд по времени (бары серии идут по возрастанию) — O(1) амортизировано.
     *
     * @param  array<string, list<array<string,mixed>>>  $seriesByTf  ключ dashboard TF ('5','15','60')
     * @param  array<string, int>  $takeByTf
     * @param  array<string, int>  $cursor  индекс последнего включённого ЗАКРЫТОГО HTF-бара
     * @return array<string, list<array<string,mixed>>>
     */
    public static function sliceClosedMtfUntil(
        array $seriesByTf,
        array $takeByTf,
        array &$cursor,
        int $barTs,
        int $seriesPeriodSec,
    ): array {
        $seriesPeriodSec = max(1, $seriesPeriodSec);
        // Close текущего бара серии: к этому моменту OHLC бара уже известен.
        $knownUntil = $barTs + $seriesPeriodSec;
        $out = [];
        foreach ($seriesByTf as $tfKey => $rows) {
            $n = count($rows);
            $i = $cursor[$tfKey] ?? -1;
            $htfPeriod = CandleService::periodSecondsForDashboardTf((int) $tfKey) ?? $seriesPeriodSec;

            // Продвинуть правую границу до последнего HTF, который уже закрылся.
            while ($i + 1 < $n) {
                $htfTime = (int) ($rows[$i + 1]['time'] ?? 0);
                $htfClose = $htfTime + $htfPeriod;
                if ($htfClose > $knownUntil) {
                    break;
                }
                $i++;
            }
            $cursor[$tfKey] = $i;
            if ($i < 0) {
                $out[$tfKey] = [];
                continue;
            }
            $take = max(1, (int) ($takeByTf[$tfKey] ?? 50));
            $from = max(0, $i + 1 - $take);
            $out[$tfKey] = array_slice($rows, $from, $i + 1 - $from);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $partial
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float,is_partial:bool}
     */
    private function normalizePartialBar(array $partial): array
    {
        return [
            'time' => (int) ($partial['time'] ?? 0),
            'open' => (float) ($partial['open'] ?? 0),
            'high' => (float) ($partial['high'] ?? 0),
            'low' => (float) ($partial['low'] ?? 0),
            'close' => (float) ($partial['close'] ?? 0),
            'volume' => (float) ($partial['volume'] ?? 0),
            'is_partial' => true,
        ];
    }

    /**
     * M1 (или другой входной ТФ) плюс M5/M15/H1 из одной выборки S5.
     *
     * @param  array<int, int>  $tfToMaxBars
     * @return array{
     *     closed:list<array<string,mixed>>,
     *     partial:array<string,mixed>|null,
     *     series:list<array<string,mixed>>,
     *     mtf:array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     * }
     */
    public function seriesWithMtf(
        string $instrument,
        int $count,
        array $tfToMaxBars,
        bool $includePartial = true,
        int $staleAfterSec = 30,
        int $timeframe = 1,
    ): array {
        $map = $tfToMaxBars;
        $map[$timeframe] = $count;
        $bundle = $this->candleService->seriesBundle($instrument, $map);
        $rows = $bundle[(string) $timeframe] ?? [];
        $rows = self::withoutOpenLastBucket($rows, $timeframe);

        $closed = array_map(static fn (array $r) => [
            'time' => (int) ($r['time'] ?? 0),
            'open' => (float) ($r['open'] ?? 0),
            'high' => (float) ($r['high'] ?? 0),
            'low' => (float) ($r['low'] ?? 0),
            'close' => (float) ($r['close'] ?? 0),
            'volume' => (float) ($r['volume'] ?? 0),
            'is_partial' => false,
        ], $rows);

        $partial = null;
        if ($includePartial) {
            $partialCandidate = $this->currentCandleCache->getPartial($instrument);
            if ($partialCandidate && $this->currentCandleCache->isFresh($partialCandidate, $staleAfterSec)) {
                $lastClosedTime = ! empty($closed) ? (int) end($closed)['time'] : 0;
                if ((int) $partialCandidate['time'] > $lastClosedTime) {
                    $partial = $partialCandidate;
                }
            }
        }

        $series = $closed;
        if ($partial) {
            $series[] = $partial;
        }

        return [
            'closed' => $closed,
            'partial' => $partial,
            'series' => $series,
            // M5/M15/H1 — только закрытые бакеты. Иначе CRC/Strong Level подтверждают
            // rejection по формирующейся свече (тень ещё перерисуется).
            'mtf' => $this->normalizeMtfBundle($bundle),
        ];
    }

    /**
     * Агрегированные свечи для MTF-стратегий. Все ТФ — из одной выборки S5.
     *
     * @param  array<int, int>  $tfToMaxBars
     * @return array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     */
    public function mtfSeries(string $instrument, array $tfToMaxBars): array
    {
        return $this->normalizeMtfBundle($this->candleService->seriesBundle($instrument, $tfToMaxBars));
    }

    /**
     * Закрытые бары ТФ за [fromTs, toTs] для candle-replay backtest.
     * Partial не включаем: в истории «текущей» свечи нет — только закрытые бакеты.
     *
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float,is_partial:bool}>
     */
    public function closedSeriesInRange(
        string $instrument,
        int $timeframe,
        int $fromTs,
        int $toTs,
        ?int $maxBars = null,
    ): array {
        $rows = $this->candleService->seriesForDashboard(
            $instrument,
            $timeframe,
            $fromTs,
            $toTs + 1,
            $maxBars,
        );
        // На верхней границе периода «открытого» бакета уже нет: toTs в прошлом.
        // withoutOpenLastBucket всё равно безопасен, если toTs ≈ now.
        $rows = self::withoutOpenLastBucket($rows, $timeframe, $toTs);

        return array_values(array_map(static fn (array $r) => [
            'time' => (int) ($r['time'] ?? 0),
            'open' => (float) ($r['open'] ?? 0),
            'high' => (float) ($r['high'] ?? 0),
            'low' => (float) ($r['low'] ?? 0),
            'close' => (float) ($r['close'] ?? 0),
            'volume' => (float) ($r['volume'] ?? 0),
            'is_partial' => false,
        ], $rows));
    }

    /**
     * Нормализует OHLC и выкидывает ещё открытый последний бакет каждого ТФ.
     * Live seriesBundle всегда дорисовывает текущую M5/M15/H1 из уже пришедших S5 —
     * для подтверждения отскока нужна только закрытая старшая свеча.
     *
     * @param  array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>  $bundle
     * @return array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     */
    private function normalizeMtfBundle(array $bundle, ?int $now = null): array
    {
        $out = [];
        foreach ($bundle as $tf => $rows) {
            $tfId = (int) $tf;
            $closedRows = self::withoutOpenLastBucket($rows, $tfId, $now);
            $out[(string) $tf] = array_map(static fn (array $c) => [
                'time' => (int) $c['time'],
                'open' => (float) $c['open'],
                'high' => (float) $c['high'],
                'low' => (float) $c['low'],
                'close' => (float) $c['close'],
                'volume' => (float) ($c['volume'] ?? 0),
            ], $closedRows);
        }

        return $out;
    }
}
