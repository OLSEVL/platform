<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Support\BotScanProgress;
use App\Support\ChartLiveConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Полный push UI-событий бота: карточка сделки + stats + open/signal + scan.
 * Redis bot:events → chart_live_hub → браузер (без частого HTTP).
 *
 * Частота как до load-fix: каждый publishTrade — полный payload сразу;
 * publishScan — на каждый writeState (без throttle).
 */
class BotLiveEventsBroadcast
{
    public const EVENT_OPENED = 'opened';

    public const EVENT_MIRRORED = 'mirrored';

    public const EVENT_SETTLED = 'settled';

    public const EVENT_SCAN = 'scan';

    /**
     * Карточка в том же формате, что BotController::liveTradesData.
     *
     * @return array<string, mixed>
     */
    public static function cardFromTrade(BotTrade $trade): array
    {
        $trade->loadMissing('bot');
        $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
        $mirroredAt = isset($meta['mirrored_at']) ? (string) $meta['mirrored_at'] : null;
        if ($mirroredAt !== null && strlen($mirroredAt) > 19) {
            try {
                $mirroredAt = Carbon::parse($mirroredAt)->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $mirroredAt = substr($mirroredAt, 0, 19);
            }
        }

        return [
            'id' => $trade->id,
            'bot_id' => (int) $trade->bot_id,
            'entry_time' => optional($trade->entry_time)->format('Y-m-d H:i:s'),
            'created_at' => optional($trade->created_at)->format('Y-m-d H:i:s'),
            'mirrored_at' => $mirroredAt,
            'bot_name' => $trade->bot?->name ?? '—',
            'instrument' => $trade->instrument,
            'side' => $trade->side,
            'status' => $trade->status,
            'result' => $trade->result ?? 'pending',
            'paper_result' => $meta['paper_result'] ?? ($trade->result ?? 'pending'),
            'mirror_po_result' => isset($meta['mirror_po_result']) ? (string) $meta['mirror_po_result'] : null,
            'mirror_po_result_source' => isset($meta['mirror_po_result_source'])
                ? (string) $meta['mirror_po_result_source']
                : null,
            'mirror_po_profit' => isset($meta['mirror_po_profit']) && is_numeric($meta['mirror_po_profit'])
                ? (float) $meta['mirror_po_profit']
                : null,
            'mirror_status' => isset($meta['mirror_status']) ? (string) $meta['mirror_status'] : null,
            'entry_price' => $trade->entry_price !== null ? number_format((float) $trade->entry_price, 5, '.', '') : null,
            'entry_amount' => BotTrade::formatMirrorAmountLabel(BotTrade::mirrorAmountFromMeta($meta)),
            'expiry_price' => $trade->expiry_price !== null ? number_format((float) $trade->expiry_price, 5, '.', '') : null,
            'expiry_time' => optional($trade->expiry_time)->format('Y-m-d H:i:s'),
            'settled_at' => optional($trade->settled_at)->format('Y-m-d H:i:s'),
            'expiry_ts' => optional($trade->expiry_time)?->timestamp,
            'pnl' => $trade->pnl !== null ? number_format((float) $trade->pnl, 4, '.', '') : null,
        ];
    }

    public function publishTrade(BotTrade $trade, string $event, ?array $tradeCard = null): void
    {
        $trade->loadMissing(['bot', 'signal']);
        $bot = $trade->bot;
        $botId = (int) $trade->bot_id;
        $userId = (int) ($bot?->user_id ?? 0);
        if ($botId <= 0 || $event === '') {
            return;
        }

        $card = $tradeCard ?? self::cardFromTrade($trade);
        $instrument = (string) ($card['instrument'] ?? $trade->instrument);

        $body = [
            'bot_id' => $botId,
            'user_id' => $userId,
            'event' => $event,
            'trade_id' => (int) ($card['id'] ?? $trade->id),
            'instrument' => $instrument,
            'trade' => $card,
            'updated_at' => time(),
        ];

        // Полные UI-блоки сразу — бот/dashboard не делают GET после события.
        if ($bot instanceof Bot) {
            try {
                $body['stats'] = [
                    'all' => BotLiveUiPayload::statsAllTime($bot, $instrument),
                    'hour' => BotLiveUiPayload::statsLastHour($bot, $instrument),
                    'instrument' => $instrument,
                ];
                $body['open_instruments'] = BotLiveUiPayload::openInstrumentsMap($botId);
                $body['open_instruments_user'] = BotLiveUiPayload::openInstrumentsMapForUser($userId);
                if ($trade->status === BotTrade::STATUS_OPEN) {
                    $body['open_trade_row'] = BotLiveUiPayload::openTradeRow($trade);
                } else {
                    $body['open_trade_row'] = null;
                    $body['closed_trade_id'] = (int) $trade->id;
                }
                $signal = $trade->signal;
                if ($signal) {
                    if (! $signal->relationLoaded('trade')) {
                        $signal->setRelation('trade', $trade);
                    }
                    $body['signal'] = BotLiveUiPayload::signalRow($signal);
                }
            } catch (Throwable) {
                // Карточка сделки важнее вспомогательных блоков.
            }
        }

        $this->publishRaw($body);
    }

    /** Прогресс scan → точки в сайдбаре бота без GET /instruments/data. */
    public function publishScan(int $botId, ?float $minPayout = null): void
    {
        if ($botId <= 0) {
            return;
        }

        try {
            $bot = Bot::query()->find($botId);
            if (! $bot) {
                return;
            }
            $snap = BotScanProgress::snapshot($botId, $minPayout);
            $this->publishRaw([
                'bot_id' => $botId,
                'user_id' => (int) $bot->user_id,
                'event' => self::EVENT_SCAN,
                'scan' => $snap,
                'updated_at' => time(),
            ]);
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function publishRaw(array $body): void
    {
        try {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            Redis::publish(ChartLiveConfig::REDIS_BOT_EVENTS_CHANNEL, $json);
        } catch (Throwable) {
            // Redis недоступен — UI на редком HTTP heal.
        }
    }

    public function tradeOpened(BotTrade $trade): void
    {
        $this->publishTrade($trade, self::EVENT_OPENED);
    }

    public function tradeMirrored(BotTrade $trade): void
    {
        $this->publishTrade($trade, self::EVENT_MIRRORED);
    }

    public function tradeSettled(BotTrade $trade): void
    {
        $this->publishTrade($trade, self::EVENT_SETTLED);
    }
}
