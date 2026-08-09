<?php

namespace App\Services\Mirror;

use App\Models\BotTrade;
use App\Models\UserTradingSetting;
use App\Support\ManualTradeConfig;
use Illuminate\Support\Facades\DB;

/**
 * Сдвиг шага мартингейла после settle paper-сделки (только если зеркало на PO было executed).
 *
 * Результат для шага: предпочтительно итог PO (mirror_po_result), иначе paper.
 * Ручные сделки (source=manual) двигают шаг только при manual_advance_martingale=true.
 */
class UserMartingaleService
{
    public function advanceAfterSettledTrade(BotTrade $trade, string $result, string $mirrorStatusBeforeSettle): void
    {
        if ($mirrorStatusBeforeSettle !== 'executed') {
            return;
        }

        // Для зеркальных сделок шаг берём из PO-итога в meta (если уже записан).
        $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
        $poResult = strtolower((string) ($meta['mirror_po_result'] ?? ''));
        if (in_array($poResult, [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS, BotTrade::RESULT_DRAW], true)) {
            $result = $poResult;
        }

        if ($result === BotTrade::RESULT_DRAW) {
            return;
        }

        $trade->loadMissing('bot:id,user_id');
        $userId = (int) ($trade->bot?->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        // Ручной вход: по умолчанию не трогаем цепочку мартингейла ботов.
        if (($meta['source'] ?? null) === ManualTradeConfig::META_SOURCE) {
            $allowManual = UserTradingSetting::query()
                ->where('user_id', $userId)
                ->value('manual_advance_martingale');
            if (! (bool) $allowManual) {
                return;
            }
        }

        DB::transaction(function () use ($userId, $result): void {
            /** @var UserTradingSetting|null $settings */
            $settings = UserTradingSetting::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $settings) {
                return;
            }

            $amounts = $settings->orderAmountsList();
            if ($amounts === []) {
                return;
            }

            $step = max(0, (int) $settings->martingale_step);
            if ($result === BotTrade::RESULT_WIN) {
                $settings->martingale_step = 0;
            } elseif ($result === BotTrade::RESULT_LOSS) {
                $settings->martingale_step = ($step + 1) % count($amounts);
            }

            $settings->save();
        });
    }

    public function resetStepForUser(int $userId): void
    {
        UserTradingSetting::query()
            ->where('user_id', $userId)
            ->update(['martingale_step' => 0]);
    }
}
