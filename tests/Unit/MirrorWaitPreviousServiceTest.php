<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\Mirror\MirrorWaitPreviousService;
use App\Support\ManualTradeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MirrorWaitPreviousServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_while_previous_executed_still_open_even_after_expiry(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'T',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [],
        ]);

        $previous = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now()->subMinutes(2),
            'expiry_time' => now()->subSecond(),
            'entry_price' => 1.1,
            'meta_json' => ['mirror_status' => 'executed'],
        ]);

        $next = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now(),
            'expiry_time' => now()->addMinute(),
            'entry_price' => 1.2,
            'meta_json' => [],
        ]);

        $svc = app(MirrorWaitPreviousService::class);
        $this->assertTrue($svc->hasBlockingMirroredTrade($user->id, $next->id));

        $previous->update([
            'status' => BotTrade::STATUS_SETTLED,
            'result' => BotTrade::RESULT_LOSS,
        ]);

        $this->assertFalse($svc->hasBlockingMirroredTrade($user->id, $next->id));
    }

    public function test_manual_open_blocks_bots_when_respect_flag_on_even_without_profile_wait(): void
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'order_amounts' => [1.0],
            'order_amount' => 1,
            'mirror_trades_enabled' => true,
            'mirror_wait_previous_settled' => false,
            'manual_respect_wait_previous' => true,
        ]);

        $manualBot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => ManualTradeConfig::BOT_NAME,
            'type' => ManualTradeConfig::BOT_TYPE,
            'is_active' => false,
            'config' => ['mirror_trades_enabled' => false],
        ]);
        BotTrade::query()->create([
            'bot_id' => $manualBot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now(),
            'expiry_time' => now()->addMinutes(5),
            'entry_price' => 1.1,
            'meta_json' => [
                'source' => ManualTradeConfig::META_SOURCE,
                'mirror_status' => 'executed',
            ],
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'T',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [],
        ]);
        $next = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now(),
            'expiry_time' => now()->addMinute(),
            'entry_price' => 1.2,
            'meta_json' => [],
        ]);

        $settings = UserTradingSetting::query()->where('user_id', $user->id)->first();
        $svc = app(MirrorWaitPreviousService::class);
        $this->assertTrue($svc->shouldSkipMirror($settings, $user->id, $next->id));

        $settings->manual_respect_wait_previous = false;
        $settings->save();
        $this->assertFalse($svc->shouldSkipMirror($settings->fresh(), $user->id, $next->id));
    }
}
