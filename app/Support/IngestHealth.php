<?php

namespace App\Support;

use App\Models\PocketInstrument;
use Illuminate\Support\Carbon;

/**
 * Общие правила «свежести» payout/ingest для UI, ботов и диагностики прокси.
 */
final class IngestHealth
{
    public const INGEST_SILENT_MINUTES = 2;

    public const WS_RECENT_CONNECTED_MINUTES = 15;

    public const WS_RECENT_FAIL_MINUTES = 15;

    public static function isPayoutEventStale(PocketInstrument $instrument, int $staleMinutes, ?Carbon $now = null): bool
    {
        $now = $now ?? now();

        if ($instrument->payout_is_stale) {
            return true;
        }

        $eventAt = $instrument->last_payout_event_at ?? $instrument->last_payout_at;

        return $eventAt !== null && $eventAt->lt($now->copy()->subMinutes(max(1, $staleMinutes)));
    }

    /**
     * Нужно ли переводить прокси в dead из-за stale PO (handleStalePoProxy).
     * В отличие от isPayoutEventStale: игнорируем payout_is_stale и не dead-им,
     * если POST ingest свежий, а устарел только last_payout_event_at.
     */
    public static function shouldMarkProxyDeadForStalePoEvent(
        PocketInstrument $instrument,
        int $staleMinutes,
        ?Carbon $now = null,
    ): bool {
        $now = $now ?? now();
        $staleMinutes = max(1, $staleMinutes);
        $eventAt = $instrument->last_payout_event_at;

        if ($eventAt !== null) {
            if ($eventAt->gte($now->copy()->subMinutes($staleMinutes))) {
                return false;
            }

            // Событие PO старое, но ingest шлёт heartbeat — не списываем прокси только из‑за event.
            if (! self::isIngestSilent($instrument, $now)) {
                return false;
            }

            return true;
        }

        $ingestAt = $instrument->last_payout_at;
        if ($ingestAt === null) {
            return false;
        }

        return $ingestAt->lt($now->copy()->subMinutes($staleMinutes));
    }

    public static function isIngestSilent(PocketInstrument $instrument, ?Carbon $now = null): bool
    {
        $now = $now ?? now();
        $ingestAt = $instrument->last_payout_at;

        return $ingestAt === null
            || $ingestAt->lt($now->copy()->subMinutes(self::INGEST_SILENT_MINUTES));
    }

    public static function isWsRecentlyConnected(PocketInstrument $instrument, ?Carbon $now = null): bool
    {
        $now = $now ?? now();
        $connectedAt = $instrument->last_ws_connected_at;

        return $connectedAt !== null
            && $connectedAt->gte($now->copy()->subMinutes(self::WS_RECENT_CONNECTED_MINUTES));
    }

    public static function isWsRecentlyFailed(PocketInstrument $instrument, ?Carbon $now = null): bool
    {
        $now = $now ?? now();
        $failedAt = $instrument->last_ws_error_at;

        return $failedAt !== null
            && $failedAt->gte($now->copy()->subMinutes(self::WS_RECENT_FAIL_MINUTES));
    }
}
