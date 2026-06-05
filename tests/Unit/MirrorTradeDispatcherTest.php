<?php

namespace Tests\Unit;

use App\Jobs\MirrorBotTradeJob;
use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\Bots\BotTradeMirrorService;
use App\Services\Mirror\MirrorTradeDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MirrorTradeDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_dispatch_calls_mirror_service_immediately(): void
    {
        config(['services.mirror.dispatch' => 'sync']);

        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"x","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'mirror_trades_enabled' => false,
        ]);
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'B',
            'type' => 'candle_reversal_confirm',
            'is_active' => true,
            'config' => [],
        ]);
        $trade = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now(),
            'expiry_time' => now()->addMinute(),
            'entry_price' => 1.1,
        ]);

        $mock = $this->mock(BotTradeMirrorService::class);
        $mock->shouldReceive('mirrorTrade')->once();

        app(MirrorTradeDispatcher::class)->dispatch($trade->id);
    }

    public function test_queue_dispatch_pushes_job(): void
    {
        Bus::fake();
        config(['services.mirror.dispatch' => 'queue']);

        app(MirrorTradeDispatcher::class)->dispatch(99);

        Bus::assertDispatched(MirrorBotTradeJob::class, fn ($job) => $job->botTradeId === 99);
    }
}
