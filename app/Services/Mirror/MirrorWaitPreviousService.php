<?php

namespace App\Services\Mirror;

use App\Models\BotTrade;
use App\Models\UserTradingSetting;
use App\Services\PocketOptionsProxyClient;
use Illuminate\Support\Facades\Log;

/**
 * Мартингейл на PO: при включённой настройке не открываем новый ордер,
 * пока на Pocket Option есть активная сделка (проверка через proxy API).
 */
class MirrorWaitPreviousService
{
    public const SKIP_STATUS = 'skipped_wait_previous_trade';

    public function __construct(
        private readonly PocketOptionsProxyClient $proxy,
    ) {}

    /**
     * Пропуск, если в настройках включено «одна сделка на PO» и на PO ещё есть активный ордер.
     */
    public function shouldSkipMirror(
        UserTradingSetting $settings,
        int $userId,
        int $tradeId,
        ?string $ssid = null,
        ?string $proxyUrl = null,
    ): bool {
        if (! (bool) $settings->mirror_wait_previous_settled) {
            return false;
        }

        if ($ssid !== null && trim($ssid) !== '') {
            $po = $this->proxy->getActiveOrders($ssid, (bool) $settings->is_demo, $proxyUrl);
            if ($po['ok'] ?? false) {
                return (int) ($po['count'] ?? 0) > 0;
            }

            Log::warning('mirror_po_active_orders_check_failed', [
                'user_id' => $userId,
                'trade_id' => $tradeId,
                'error' => $po['error'] ?? 'unknown',
            ]);
        }

        // Fallback: если proxy/PO недоступен — ориентируемся на paper-сделки платформы.
        return $this->hasBlockingMirroredTrade($userId, $tradeId);
    }

    /**
     * Другая открытая paper-сделка с уже отправленным ордером на PO (запасной критерий).
     */
    public function hasBlockingMirroredTrade(int $userId, int $exceptTradeId): bool
    {
        return BotTrade::query()
            ->where('id', '!=', $exceptTradeId)
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->where('meta_json->mirror_status', 'executed')
            ->whereHas('bot', fn ($q) => $q->where('user_id', $userId))
            ->exists();
    }
}
