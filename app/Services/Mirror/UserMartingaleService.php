<?php

namespace App\Services\Mirror;

use App\Models\BotTrade;
use App\Models\UserTradingSetting;
use Illuminate\Support\Facades\DB;

/**
 * Сдвиг шага мартингейла после settle paper-сделки (только если зеркало на PO было executed).
 */
class UserMartingaleService
{
    public function advanceAfterSettledTrade(BotTrade $trade, string $result, string $mirrorStatusBeforeSettle): void
    {
        if ($mirrorStatusBeforeSettle !== 'executed') {
            return;
        }

        if ($result === BotTrade::RESULT_DRAW) {
            return;
        }

        $trade->loadMissing('bot:id,user_id');
        $userId = (int) ($trade->bot?->user_id ?? 0);
        if ($userId <= 0) {
            return;
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
