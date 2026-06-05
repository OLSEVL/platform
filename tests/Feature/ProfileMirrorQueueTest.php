<?php

namespace Tests\Feature;

use App\Jobs\MirrorBotTradeJob;
use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\User;
use App\Models\UserTradingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProfileMirrorQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_mirror_queue_data(): void
    {
        $this->getJson(route('profile.mirror-queue.data'))->assertUnauthorized();
    }

    public function test_user_sees_mirror_queue_snapshot(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"x","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'mirror_trades_enabled' => true,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Bot',
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
            'entry_price' => 1.05,
            'meta_json' => ['mirror_status' => 'executed', 'pocket_order_id' => 'abc'],
        ]);

        MirrorBotTradeJob::dispatch($trade->id + 1);

        $this->actingAs($user)
            ->get(route('profile.mirror-queue.index'))
            ->assertOk()
            ->assertSee('Очередь зеркалирования');

        $response = $this->actingAs($user)
            ->getJson(route('profile.mirror-queue.data'))
            ->assertOk()
            ->assertJsonStructure([
                'server_time',
                'summary' => ['jobs_pending', 'mirror_enabled', 'proxy_ok'],
                'pending_jobs',
                'trades',
            ]);

        $this->assertTrue($response->json('summary.mirror_enabled'));
        $this->assertNotEmpty($response->json('trades'));
    }
}
