<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ExtensionSignalsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_supports_after_id_incremental_poll(): void
    {
        // Boot публикует в Redis — глушим, нам нужен только HTTP.
        Redis::shouldReceive('publish')->andReturn(1);

        $user = User::query()->create([
            'name' => 'Ext API',
            'email' => 'ext-api-'.uniqid('', true).'@example.test',
            'password' => bcrypt('secret'),
        ]);
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'API Bot',
            'type' => 'impulse_lvl_1min',
            'config' => [],
            'is_active' => true,
        ]);

        $older = BotSignal::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'confidence' => 0.5,
            'signal_at' => now()->subMinute(),
            'expiry_time' => now(),
            'reasons_json' => [],
            'decision_version' => 'v1',
            'dedupe_key' => 'old-'.uniqid(),
        ]);
        $newer = BotSignal::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'confidence' => 0.7,
            'signal_at' => now(),
            'expiry_time' => now()->addMinute(),
            'reasons_json' => [],
            'decision_version' => 'v1',
            'dedupe_key' => 'new-'.uniqid(),
        ]);

        $all = $this->getJson('/api/extension/signals?limit=10');
        $all->assertOk()->assertJsonPath('ok', true);
        $this->assertCount(2, $all->json('signals'));

        // Poll как в расширении: только id > уже известного.
        $delta = $this->getJson('/api/extension/signals?limit=10&after_id='.$older->id);
        $delta->assertOk();
        $ids = collect($delta->json('signals'))->pluck('signal_id')->all();
        $this->assertSame([(int) $newer->id], $ids);

        $empty = $this->getJson('/api/extension/signals?limit=10&after_id='.$newer->id);
        $empty->assertOk();
        $this->assertSame([], $empty->json('signals'));
    }
}
