<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\Mirror\UserMartingaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserMartingaleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_loss_advances_step_win_resets(): void
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"x","uid":1,"isDemo":1,"platform":2}]',
            'order_amounts' => [1.0, 2.0, 4.0],
            'martingale_step' => 0,
            'order_amount' => 1,
            'mirror_trades_enabled' => true,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'T',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [],
        ]);

        $trade = $this->makeTrade($bot->id, ['mirror_status' => 'executed']);
        app(UserMartingaleService::class)->advanceAfterSettledTrade($trade, BotTrade::RESULT_LOSS, 'executed');
        $this->assertSame(1, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));

        $trade2 = $this->makeTrade($bot->id, ['mirror_status' => 'executed']);
        app(UserMartingaleService::class)->advanceAfterSettledTrade($trade2, BotTrade::RESULT_WIN, 'executed');
        $this->assertSame(0, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));
    }

    public function test_draw_and_non_executed_mirror_do_not_advance(): void
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"x","uid":1,"isDemo":1,"platform":2}]',
            'order_amounts' => [1.0, 2.0],
            'martingale_step' => 1,
            'order_amount' => 1,
            'mirror_trades_enabled' => true,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'T',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [],
        ]);

        $trade = $this->makeTrade($bot->id, ['mirror_status' => 'executed']);
        app(UserMartingaleService::class)->advanceAfterSettledTrade($trade, BotTrade::RESULT_DRAW, 'executed');
        $this->assertSame(1, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));

        $trade2 = $this->makeTrade($bot->id, ['mirror_status' => 'failed', 'mirror_amount' => 2]);
        app(UserMartingaleService::class)->advanceAfterSettledTrade($trade2, BotTrade::RESULT_LOSS, 'failed');
        $this->assertSame(1, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));
    }

    /** @param  array<string, mixed>  $meta */
    private function makeTrade(int $botId, array $meta): BotTrade
    {
        $entry = now();

        return BotTrade::query()->create([
            'bot_id' => $botId,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_SETTLED,
            'result' => BotTrade::RESULT_LOSS,
            'entry_time' => $entry,
            'expiry_time' => $entry->copy()->addMinute(),
            'entry_price' => 1.0,
            'meta_json' => $meta,
        ]);
    }
}
