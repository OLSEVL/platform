<?php

namespace App\Services;

use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Границы истории S5-свечей (min_ts / max_ts) без полного скана таблицы candles.
 *
 * Раньше GET списка бэктестов делал MIN/MAX по timeframe — на десятках миллионов
 * строк это ~десятки секунд, потому что индекс (instrument, timeframe, time)
 * не начинается с timeframe.
 *
 * Кэш живёт долго: ingest только расширяет max (и min при докачке прошлого),
 * очистка истории пересчитывает края по индексу на каждый инструмент.
 */
class CandleRangeCache
{
    public const CACHE_KEY = 'candles:s5:range';

    public const LOCK_KEY = 'candles:s5:range:lock';

    /**
     * @return array{min_ts: int|null, max_ts: int|null}
     */
    public function s5Range(): array
    {
        $cached = $this->read();
        if ($cached !== null) {
            return $cached;
        }

        return $this->rebuild();
    }

    /**
     * Новые S5-бары: подвинуть края, если кэш уже прогрет.
     *
     * Пустой кэш специально не заполняем одним батчем — иначе «вся история»
     * сжалась бы до последних секунд до первого полного пересчёта.
     */
    public function expandS5(int $minTs, int $maxTs): void
    {
        if ($minTs <= 0 || $maxTs <= 0) {
            return;
        }

        try {
            $cached = $this->read();
            if ($cached === null) {
                return;
            }

            $min = $cached['min_ts'] === null ? $minTs : min((int) $cached['min_ts'], $minTs);
            $max = $cached['max_ts'] === null ? $maxTs : max((int) $cached['max_ts'], $maxTs);
            $this->write($min, $max);
        } catch (\Throwable) {
            // Ingest идёт от www-data, artisan — от пользователя o: file-cache
            // может быть read-only. Свечи уже записаны — диапазон не должен ронять 500.
        }
    }

    /**
     * После delete свечей min может вырасти (или таблица опустеть) — только полный пересчёт.
     *
     * @return array{min_ts: int|null, max_ts: int|null}
     */
    public function rebuildAfterCleanup(): array
    {
        return $this->rebuild();
    }

    /**
     * @return array{min_ts: int|null, max_ts: int|null}
     */
    public function rebuild(): array
    {
        try {
            return Cache::lock(self::LOCK_KEY, 60)->block(45, function (): array {
                $range = $this->computeS5RangeFromIndex();
                $this->write($range['min_ts'], $range['max_ts']);

                return $range;
            });
        } catch (LockTimeoutException) {
            return $this->read() ?? ['min_ts' => null, 'max_ts' => null];
        } catch (\Throwable) {
            return $this->read() ?? ['min_ts' => null, 'max_ts' => null];
        }
    }

    /**
     * @return array{min_ts: int|null, max_ts: int|null}|null  null = ключа ещё нет
     */
    public function read(): ?array
    {
        try {
            $raw = Cache::get(self::CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($raw) || ! array_key_exists('min_ts', $raw) || ! array_key_exists('max_ts', $raw)) {
            return null;
        }

        return [
            'min_ts' => $raw['min_ts'] !== null ? (int) $raw['min_ts'] : null,
            'max_ts' => $raw['max_ts'] !== null ? (int) $raw['max_ts'] : null,
        ];
    }

    /**
     * Число строк candles без COUNT(*) по 40+ млн записей.
     *
     * MySQL/MariaDB: InnoDB TABLE_ROWS (оценка, может отличаться на несколько %).
     * SQLite/тесты: точный COUNT — таблица маленькая.
     */
    public function approximateRowCount(): int
    {
        $table = (new InstrumentCandle)->getTable();
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = DB::selectOne(
                'SELECT TABLE_ROWS AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );

            return max(0, (int) ($row->c ?? 0));
        }

        return (int) InstrumentCandle::query()->count();
    }

    public function rowCountIsApproximate(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Края диапазона через уникальный индекс (instrument, timeframe, time):
     * равенство по первым двум колонкам + MIN/MAX по time — seek, не full scan.
     *
     * @return array{min_ts: int|null, max_ts: int|null}
     */
    private function computeS5RangeFromIndex(): array
    {
        $tf = CandleService::TF_S5;
        $min = null;
        $max = null;

        foreach ($this->instrumentCodes() as $instrument) {
            $row = InstrumentCandle::query()
                ->where('instrument', $instrument)
                ->where('timeframe', $tf)
                ->selectRaw('MIN(time) as min_ts, MAX(time) as max_ts')
                ->first();
            if ($row === null || $row->min_ts === null || $row->max_ts === null) {
                continue;
            }
            $lo = (int) $row->min_ts;
            $hi = (int) $row->max_ts;
            $min = $min === null ? $lo : min($min, $lo);
            $max = $max === null ? $hi : max($max, $hi);
        }

        return ['min_ts' => $min, 'max_ts' => $max];
    }

    /**
     * Список пар из справочника, без DISTINCT по 43 млн свечей.
     *
     * @return list<string>
     */
    private function instrumentCodes(): array
    {
        if (! Schema::hasTable('pocket_instruments')) {
            return [];
        }

        return PocketInstrument::query()
            ->orderBy('instrument')
            ->pluck('instrument')
            ->map(fn ($code) => (string) $code)
            ->all();
    }

    private function write(?int $minTs, ?int $maxTs): void
    {
        try {
            Cache::forever(self::CACHE_KEY, [
                'min_ts' => $minTs,
                'max_ts' => $maxTs,
            ]);
        } catch (\Throwable) {
            // см. expandS5 — права file-cache не должны ломать запись свечей.
        }
    }
}
