<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use Illuminate\Support\Facades\DB;

/**
 * Полезные payload’ы для WS bot_event (stats / open-trade row / signal / open map).
 * Дублирует поля HTTP API бота, чтобы UI не делал GET после каждого события.
 */
class BotLiveUiPayload
{
    /**
     * @return array{total: int, open: int, wins: int, losses: int, winrate: float|null, instrument_winrate: float|null}
     */
    public static function statsAllTime(Bot $bot, ?string $instrument = null): array
    {
        $raw = $bot->trades()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) as losses
            ', [
                BotTrade::STATUS_OPEN,
                BotTrade::RESULT_WIN,
                BotTrade::RESULT_LOSS,
            ])
            ->first();

        $wins = (int) ($raw?->wins ?? 0);
        $losses = (int) ($raw?->losses ?? 0);
        $decisive = $wins + $losses;

        return [
            'total' => (int) ($raw?->total ?? 0),
            'open' => (int) ($raw?->open ?? 0),
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => $decisive > 0 ? round(($wins * 100) / $decisive, 2) : null,
            'instrument_winrate' => self::instrumentWinrateAllTime($bot, $instrument),
        ];
    }

    /**
     * @return array{total: int, open: int, wins: int, losses: int, winrate: float|null, instrument_winrate: float|null}
     */
    public static function statsLastHour(Bot $bot, ?string $instrument = null): array
    {
        $since = now()->subHour();
        $total = (int) $bot->trades()->where('entry_time', '>=', $since)->count();
        $open = (int) $bot->trades()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->count();
        $wins = (int) $bot->trades()
            ->where('result', BotTrade::RESULT_WIN)
            ->where('settled_at', '>=', $since)
            ->count();
        $losses = (int) $bot->trades()
            ->where('result', BotTrade::RESULT_LOSS)
            ->where('settled_at', '>=', $since)
            ->count();
        $decisive = $wins + $losses;

        return [
            'total' => $total,
            'open' => $open,
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => $decisive > 0 ? round(($wins * 100) / $decisive, 2) : null,
            'instrument_winrate' => self::instrumentWinrateLastHour($bot, $instrument, $since),
        ];
    }

    private static function instrumentWinrateAllTime(Bot $bot, ?string $instrument): ?float
    {
        if ($instrument === null || $instrument === '') {
            return null;
        }
        $wins = (int) $bot->trades()
            ->where('instrument', $instrument)
            ->where('result', BotTrade::RESULT_WIN)
            ->count();
        $losses = (int) $bot->trades()
            ->where('instrument', $instrument)
            ->where('result', BotTrade::RESULT_LOSS)
            ->count();
        $decisive = $wins + $losses;

        return $decisive > 0 ? round(($wins * 100) / $decisive, 2) : null;
    }

    private static function instrumentWinrateLastHour(Bot $bot, ?string $instrument, $since): ?float
    {
        if ($instrument === null || $instrument === '') {
            return null;
        }
        $wins = (int) $bot->trades()
            ->where('instrument', $instrument)
            ->where('result', BotTrade::RESULT_WIN)
            ->where('settled_at', '>=', $since)
            ->count();
        $losses = (int) $bot->trades()
            ->where('instrument', $instrument)
            ->where('result', BotTrade::RESULT_LOSS)
            ->where('settled_at', '>=', $since)
            ->count();
        $decisive = $wins + $losses;

        return $decisive > 0 ? round(($wins * 100) / $decisive, 2) : null;
    }

    /**
     * Карта открытых сделок бота: instrument → nearest expiry unix.
     *
     * @return array<string, int>
     */
    public static function openInstrumentsMap(int $botId): array
    {
        return DB::table('bot_trades')
            ->where('bot_id', $botId)
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->selectRaw('instrument, MIN(expiry_time) as min_expiry')
            ->groupBy('instrument')
            ->pluck('min_expiry', 'instrument')
            ->map(static fn ($v) => \Carbon\Carbon::parse($v)->timestamp)
            ->all();
    }

    /**
     * Карта открытых сделок пользователя (dashboard сайдбар).
     *
     * @return array<string, int>
     */
    public static function openInstrumentsMapForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return DB::table('bot_trades as t')
            ->join('bots as b', 'b.id', '=', 't.bot_id')
            ->where('b.user_id', $userId)
            ->where('t.status', BotTrade::STATUS_OPEN)
            ->where('t.expiry_time', '>', now())
            ->selectRaw('t.instrument, MIN(t.expiry_time) as min_expiry')
            ->groupBy('t.instrument')
            ->pluck('min_expiry', 'instrument')
            ->map(static fn ($v) => \Carbon\Carbon::parse($v)->timestamp)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function openTradeRow(BotTrade $t): array
    {
        $sig = $t->relationLoaded('signal') ? $t->signal : $t->signal()->first();
        $confidence = $sig !== null ? (float) $sig->confidence : null;
        $reasons = is_array($sig?->reasons_json) ? $sig->reasons_json : [];
        $reasons = array_values(array_filter(array_map(
            static fn ($x) => is_string($x) ? $x : (is_scalar($x) ? (string) $x : ''),
            $reasons
        ), static fn ($s) => $s !== ''));

        $pnl = $t->pnl;
        $statusRu = match ($t->status) {
            BotTrade::STATUS_OPEN => 'открыта',
            BotTrade::STATUS_SETTLED => 'закрыта',
            default => (string) $t->status,
        };

        return [
            'id' => $t->id,
            'instrument' => $t->instrument,
            'side' => $t->side,
            'status' => $t->status,
            'confidence' => $confidence,
            'confidence_fmt' => $confidence !== null ? number_format($confidence, 2, '.', '') : '—',
            'signal_at_fmt' => optional($sig?->signal_at)->format('Y-m-d H:i:s') ?: optional($t->entry_time)->format('Y-m-d H:i:s'),
            'entry_at_fmt' => optional($t->entry_time)->format('Y-m-d H:i:s'),
            'trade_result' => $t->result,
            'trade_status' => $t->status,
            'status_ru' => $statusRu,
            'state_line' => (string) ($t->result ?? BotTrade::RESULT_PENDING).' '.$statusRu,
            'pnl' => $pnl !== null ? (float) $pnl : null,
            'pnl_display' => $pnl !== null ? number_format((float) $pnl, 2, '.', '') : null,
            'analysis_text' => (string) ($sig?->analysis_text ?? ''),
            'reasons_short' => $reasons,
            'entry_price' => $t->entry_price !== null ? number_format((float) $t->entry_price, 5, '.', '') : null,
            'entry_ts' => optional($t->entry_time)?->timestamp,
            'expiry_ts' => optional($t->expiry_time)?->timestamp,
            'amount' => BotTrade::mirrorAmountFromMeta(is_array($t->meta_json) ? $t->meta_json : null),
            'amount_label' => $t->mirrorEntryAmountLabel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function signalRow(BotSignal $s): array
    {
        $trade = $s->relationLoaded('trade') ? $s->trade : $s->trade;

        return [
            'id' => (int) $s->id,
            'instrument' => (string) $s->instrument,
            'side' => (string) $s->side,
            'signal_at' => optional($s->signal_at)->toIso8601String(),
            'expiry_time' => optional($s->expiry_time)->toIso8601String(),
            'result' => $trade ? (string) ($trade->result ?? '') : '',
            'trade' => $trade ? [
                'id' => (int) $trade->id,
                'expiry_time' => optional($trade->expiry_time)->toIso8601String(),
                'result' => (string) ($trade->result ?? ''),
                'entry_price' => $trade->entry_price !== null ? (float) $trade->entry_price : null,
                'expiry_price' => $trade->expiry_price !== null ? (float) $trade->expiry_price : null,
            ] : null,
        ];
    }
}
