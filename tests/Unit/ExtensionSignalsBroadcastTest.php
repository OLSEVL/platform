<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\User;
use App\Services\ExtensionSignalsBroadcast;
use App\Support\ExtensionLiveConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ExtensionSignalsBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_signal_payload_to_redis_channel(): void
    {
        $user = User::query()->create([
            'name' => 'Ext User',
            'email' => 'ext-signals-'.uniqid('', true).'@example.test',
            'password' => bcrypt('secret'),
        ]);
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Impulse',
            'type' => 'impulse_lvl_1min',
            'config' => [],
            'is_active' => true,
        ]);

        $published = [];
        Redis::shouldReceive('publish')
            ->once()
            ->andReturnUsing(function (string $channel, string $json) use (&$published) {
                $published['channel'] = $channel;
                $published['body'] = json_decode($json, true);

                return 1;
            });

        app(ExtensionSignalsBroadcast::class)->publish(
            $bot,
            'EURUSD_otc',
            [
                'side' => 'CALL',
                'confidence' => 0.81,
                'reasons_short' => ['bounce', 'engulf'],
            ],
            92.0,
            60,
        );

        $this->assertSame(ExtensionLiveConfig::REDIS_CHANNEL, $published['channel']);
        $body = $published['body'];
        $this->assertSame('signal', $body['op']);
        $this->assertSame('EURUSD_otc', $body['instrument']);
        $this->assertSame('call', $body['side']);
        $this->assertSame(0.81, $body['confidence']);
        $this->assertEquals(92.0, $body['payout']);
        $this->assertSame(60, $body['expiry_sec']);
        $this->assertSame((int) $bot->id, $body['bot_id']);
        $this->assertSame('Test Impulse', $body['bot_name']);
        $this->assertStringContainsString('bounce', (string) $body['reason']);
    }

    public function test_bot_signal_created_publishes_to_extension_channel(): void
    {
        $user = User::query()->create([
            'name' => 'Ext User 3',
            'email' => 'ext-signals3-'.uniqid('', true).'@example.test',
            'password' => bcrypt('secret'),
        ]);
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Created Bot',
            'type' => 'impulse_lvl_1min',
            'config' => [],
            'is_active' => true,
        ]);

        $published = [];
        Redis::shouldReceive('publish')
            ->once()
            ->andReturnUsing(function (string $channel, string $json) use (&$published) {
                $published['channel'] = $channel;
                $published['body'] = json_decode($json, true);

                return 1;
            });

        $signal = \App\Models\BotSignal::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'confidence' => 0.55,
            'payout_snapshot' => 80,
            'signal_at' => now(),
            'expiry_time' => now()->addMinute(),
            'reasons_json' => ['level'],
            'decision_version' => 'v1',
            'dedupe_key' => 'test-'.uniqid(),
        ]);

        $this->assertSame(ExtensionLiveConfig::REDIS_CHANNEL, $published['channel']);
        $this->assertSame('put', $published['body']['side']);
        $this->assertSame('GBPUSD_otc', $published['body']['instrument']);
        $this->assertSame((int) $signal->id, $published['body']['signal_id']);
    }

    public function test_skips_invalid_side(): void
    {
        $user = User::query()->create([
            'name' => 'Ext User 2',
            'email' => 'ext-signals2-'.uniqid('', true).'@example.test',
            'password' => bcrypt('secret'),
        ]);
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Skip Bot',
            'type' => 'impulse_lvl_1min',
            'config' => [],
            'is_active' => true,
        ]);

        Redis::shouldReceive('publish')->never();

        app(ExtensionSignalsBroadcast::class)->publish(
            $bot,
            'EURUSD_otc',
            ['side' => 'HOLD'],
            null,
            null,
        );
    }
}
