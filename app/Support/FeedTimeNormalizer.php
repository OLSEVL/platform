<?php

namespace App\Support;

/**
 * Нормализация timestamp partial-свечи от Pocket Options feed.
 *
 * PO иногда отдаёт время со сдвигом (часто +2 ч относительно UTC сервера).
 * Ingest (Python) может применить FEED_TIME_CORRECTION_SEC, но systemd unit
 * без EnvironmentFile оставляет correction=0 — тогда effective ≈ raw и график
 * получает «живую» свечу в будущем (скачок минутной свечи).
 */
final class FeedTimeNormalizer
{
    /** Базовый период S5 partial в ingest_stream.py (BASE_BAR_PERIOD_SEC). */
    public const S5_BAR_PERIOD_SEC = 5;

    public static function correctionSec(): int
    {
        return (int) config('services.feed.feed_time_correction_sec', 0);
    }

    public static function maxSkewSec(): int
    {
        return (int) config('services.feed.max_skew_sec', 300);
    }

    /**
     * Приводит partial к UTC-оси платформы: raw + correction, выравнивание по S5.
     *
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    public static function normalizePartial(array $partial, int $barPeriodSec = self::S5_BAR_PERIOD_SEC): array
    {
        $correction = self::correctionSec();
        $raw = (int) ($partial['raw_feed_time'] ?? 0);
        $effective = (int) ($partial['effective_feed_time'] ?? ($partial['time'] ?? 0));

        if ($correction === 0 || $raw <= 0) {
            return $partial;
        }

        $corrected = max(1, $raw + $correction);
        $bucketed = (int) (floor($corrected / $barPeriodSec) * $barPeriodSec);

        // Ingest уже применил ту же коррекцию — не дублируем.
        if (abs($effective - $bucketed) <= $barPeriodSec * 2) {
            return $partial;
        }

        $partial['effective_feed_time'] = $bucketed;
        $partial['time'] = $bucketed;

        return $partial;
    }

    public static function isSkewedVsServer(int $feedUnix, int $serverUnix): bool
    {
        if ($feedUnix <= 0 || $serverUnix <= 0) {
            return true;
        }

        return abs($feedUnix - $serverUnix) > self::maxSkewSec();
    }

    /**
     * Нормализует timestamp S5-бара из БД, записанного ingest без FEED_TIME_CORRECTION_SEC.
     * Такие бары лежат «в будущем» (+|correction| с) и при агрегации M1 дают «гигантскую» свечу.
     */
    public static function normalizeStoredUnixTime(int $storedUnix, ?int $serverUnix = null): int
    {
        $serverUnix = $serverUnix ?? time();
        if ($storedUnix <= 0) {
            return $storedUnix;
        }

        $correction = self::correctionSec();
        if ($correction === 0 || $storedUnix <= $serverUnix + self::maxSkewSec()) {
            return $storedUnix;
        }

        return max(1, $storedUnix + $correction);
    }
}
