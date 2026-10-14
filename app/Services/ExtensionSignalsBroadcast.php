<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\PocketInstrument;
use App\Support\ExtensionLiveConfig;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Публикация торговых сигналов в Redis → chart_live_hub → Chrome-расширение.
 * Источник: создание BotSignal (все боты).
 */
class ExtensionSignalsBroadcast
{
    /**
     * Публикация из сохранённого BotSignal (основной путь).
     */
    public function publishFromSignal(BotSignal $signal): void
    {
        $signal->loadMissing('bot');
        $bot = $signal->bot;
        if (! $bot instanceof Bot) {
            return;
        }

        $expirySec = null;
        if ($signal->signal_at && $signal->expiry_time) {
            $expirySec = max(0, $signal->signal_at->diffInSeconds($signal->expiry_time, false));
            if ($expirySec <= 0) {
                $expirySec = null;
            }
        }

        $this->publish(
            $bot,
            (string) $signal->instrument,
            [
                'side' => (string) $signal->side,
                'confidence' => $signal->confidence,
                'reasons_short' => is_array($signal->reasons_json) ? $signal->reasons_json : [],
            ],
            $signal->payout_snapshot !== null ? (float) $signal->payout_snapshot : null,
            $expirySec,
            (int) $signal->id,
            $signal->signal_at?->getTimestamp(),
        );
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    public function publish(
        Bot $bot,
        string $instrument,
        array $decision,
        ?float $payout = null,
        ?int $expirySec = null,
        ?int $signalId = null,
        ?int $ts = null,
    ): void {
        $instrument = trim($instrument);
        $side = strtoupper(trim((string) ($decision['side'] ?? '')));
        if ($instrument === '' || ! in_array($side, ['CALL', 'PUT'], true)) {
            return;
        }

        $confidence = $decision['confidence'] ?? ($decision['score'] ?? null);
        $reasons = $decision['reasons_short'] ?? ($decision['reasons'] ?? []);
        if (! is_array($reasons)) {
            $reasons = [];
        }
        // Короткая строка для таблицы в плавающем окне (без HTML).
        $reasonParts = [];
        foreach (array_slice($reasons, 0, 3) as $r) {
            $t = trim((string) $r);
            if ($t !== '') {
                $reasonParts[] = $t;
            }
        }

        // display_name нужен content-script на PO (ищет «EUR/USD OTC», не EURUSD_otc).
        $displayName = null;
        try {
            $displayName = PocketInstrument::query()
                ->where('instrument', $instrument)
                ->value('display_name');
            $displayName = is_string($displayName) ? trim($displayName) : null;
            if ($displayName === '') {
                $displayName = null;
            }
        } catch (Throwable) {
            $displayName = null;
        }

        $body = [
            'op' => 'signal',
            'signal_id' => $signalId,
            'bot_id' => (int) $bot->id,
            'bot_name' => (string) ($bot->name ?? ''),
            'bot_type' => (string) ($bot->type ?? ''),
            'instrument' => $instrument,
            'display_name' => $displayName,
            'side' => strtolower($side),
            'confidence' => is_numeric($confidence) ? round((float) $confidence, 4) : null,
            'payout' => $payout !== null ? round((float) $payout, 2) : null,
            'expiry_sec' => $expirySec !== null && $expirySec > 0 ? (int) $expirySec : null,
            'reason' => $reasonParts !== [] ? implode('; ', $reasonParts) : null,
            'ts' => $ts !== null && $ts > 0 ? $ts : time(),
        ];

        try {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            Redis::publish(ExtensionLiveConfig::REDIS_CHANNEL, $json);
        } catch (Throwable) {
            // Redis недоступен — расширение подхватит следующий сигнал.
        }
    }
}
