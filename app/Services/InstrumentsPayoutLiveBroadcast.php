<?php

namespace App\Services;

use App\Support\ChartLiveConfig;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Публикует смену payout в Redis → chart_live_hub → браузерный WS.
 * Сайдбар применяет % без частого GET /instruments/data.
 */
class InstrumentsPayoutLiveBroadcast
{
    /**
     * @param  array{instrument: string, current_payout: float|int|null, payout_is_stale?: bool, payout_source?: string|null}  $payload
     */
    public function publish(array $payload): void
    {
        $instrument = trim((string) ($payload['instrument'] ?? ''));
        if ($instrument === '') {
            return;
        }

        $payout = $payload['current_payout'] ?? null;
        $body = [
            'instrument' => $instrument,
            'current_payout' => $payout === null ? null : (float) $payout,
            'payout_is_stale' => (bool) ($payload['payout_is_stale'] ?? false),
            'payout_source' => $payload['payout_source'] ?? null,
            'updated_at' => time(),
        ];

        try {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            // Laravel Redis добавит REDIS_PREFIX; hub подписывается на prefix+channel.
            Redis::publish(ChartLiveConfig::REDIS_PAYOUT_CHANNEL, $json);
        } catch (Throwable) {
            // Redis может быть недоступен — UI останется на медленном HTTP fallback.
        }
    }
}
