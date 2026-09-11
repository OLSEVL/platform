<?php

namespace App\Services\Mirror;

use App\Models\BotTrade;
use App\Models\UserTradingSetting;
use App\Support\BotSessionPnl;
use App\Support\ManualTradeConfig;
use Illuminate\Support\Facades\DB;

/**
 * Сдвиг шага мартингейла после settle paper-сделки (только если зеркало на PO было executed).
 *
 * Результат для шага: предпочтительно итог PO (mirror_po_result), иначе paper.
 * Ручные сделки (source=manual) двигают шаг только при manual_advance_martingale=true.
 * Опционально: не поднимать ступень после LOSS, если день бота уже в плюсе (стрим kAyG3wJDPBU).
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

        $trade->loadMissing('bot');
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

        // День уже зелёный — не разгоняем перекрытие после минуса (автор на стриме).
        if ($result === BotTrade::RESULT_LOSS && $this->shouldSkipMartingaleWhenSessionGreen($trade)) {
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

    /**
     * Флаги в config бота: srl_ / eng_ skip_martingale_if_session_green.
     * PnL считаем без текущей сделки — «сессия была зелёной до этого минуса».
     */
    private function shouldSkipMartingaleWhenSessionGreen(BotTrade $trade): bool
    {
        $bot = $trade->bot;
        if ($bot === null) {
            return false;
        }
        $cfg = is_array($bot->config) ? $bot->config : [];
        $enabled = (bool) ($cfg['srl_skip_martingale_if_session_green'] ?? false)
            || (bool) ($cfg['eng_skip_martingale_if_session_green'] ?? false);
        if (! $enabled) {
            return false;
        }

        return BotSessionPnl::isSessionGreen($bot, null, (int) $trade->id);
    }

    public function resetStepForUser(int $userId): void
    {
        UserTradingSetting::query()
            ->where('user_id', $userId)
            ->update(['martingale_step' => 0]);
    }
}
