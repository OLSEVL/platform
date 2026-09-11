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

    public function test_po_result_in_meta_overrides_paper_for_step(): void
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

        // Paper win, но PO loss → шаг мартингейла растёт.
        $trade = $this->makeTrade($bot->id, [
            'mirror_status' => 'executed',
            'mirror_po_result' => BotTrade::RESULT_LOSS,
            'paper_result' => BotTrade::RESULT_WIN,
        ]);
        app(UserMartingaleService::class)->advanceAfterSettledTrade($trade, BotTrade::RESULT_WIN, 'executed');
        $this->assertSame(1, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));
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

    public function test_manual_source_skips_martingale_unless_flag_on(): void
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"x","uid":1,"isDemo":1,"platform":2}]',
            'order_amounts' => [1.0, 2.0, 4.0],
            'martingale_step' => 0,
            'order_amount' => 1,
            'mirror_trades_enabled' => true,
            'manual_advance_martingale' => false,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Ручная / Manual',
            'type' => 'manual_dashboard',
            'is_active' => false,
            'config' => ['mirror_trades_enabled' => false],
        ]);

        $trade = $this->makeTrade($bot->id, [
            'source' => 'manual',
            'mirror_status' => 'executed',
        ]);
        app(UserMartingaleService::class)->advanceAfterSettledTrade($trade, BotTrade::RESULT_LOSS, 'executed');
        $this->assertSame(0, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));

        UserTradingSetting::query()->where('user_id', $user->id)->update(['manual_advance_martingale' => true]);
        $trade2 = $this->makeTrade($bot->id, [
            'source' => 'manual',
            'mirror_status' => 'executed',
        ]);
        app(UserMartingaleService::class)->advanceAfterSettledTrade($trade2, BotTrade::RESULT_LOSS, 'executed');
        $this->assertSame(1, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));
    }

    public function test_skips_martingale_step_when_session_already_green(): void
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
            'name' => 'SRL',
            'type' => 'sweep_raid_liquidity',
            'is_active' => true,
            'config' => ['srl_skip_martingale_if_session_green' => true],
        ]);

        // День уже в плюсе (исключая текущий минус).
        BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_SETTLED,
            'result' => BotTrade::RESULT_WIN,
            'entry_time' => now()->subMinutes(10),
            'expiry_time' => now()->subMinutes(9),
            'entry_price' => 1.0,
            'pnl' => 0.8,
            'meta_json' => [],
        ]);

        $loss = $this->makeTrade($bot->id, ['mirror_status' => 'executed']);
        $loss->update(['pnl' => -1.0, 'result' => BotTrade::RESULT_LOSS]);

        app(UserMartingaleService::class)->advanceAfterSettledTrade($loss->fresh(), BotTrade::RESULT_LOSS, 'executed');
        $this->assertSame(0, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));
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
