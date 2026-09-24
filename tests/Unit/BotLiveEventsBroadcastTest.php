<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\User;
use App\Services\BotLiveEventsBroadcast;
use App\Support\BotScanProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Перехват publishRaw без мока Redis. */
class RecordingBotLiveEventsBroadcast extends BotLiveEventsBroadcast
{
    /** @var list<array<string, mixed>> */
    public array $published = [];

    protected function publishRaw(array $body): void
    {
        $this->published[] = $body;
    }
}

class BotLiveEventsBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_trade_opened_publishes_full_payload_immediately(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Bot',
            'type' => 'impulse_lvl_1min',
            'is_active' => true,
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
            'payout_snapshot' => 80,
            'meta_json' => [],
        ]);
        $trade->setRelation('bot', $bot);

        $svc = new RecordingBotLiveEventsBroadcast;
        $this->app->instance(BotLiveEventsBroadcast::class, $svc);
        $svc->tradeOpened($trade);

        $this->assertCount(1, $svc->published);
        $payload = $svc->published[0];
        $this->assertSame(BotLiveEventsBroadcast::EVENT_OPENED, $payload['event'] ?? null);
        $this->assertSame((int) $bot->id, (int) ($payload['bot_id'] ?? 0));
        $this->assertSame((int) $user->id, (int) ($payload['user_id'] ?? 0));
        $this->assertIsArray($payload['trade'] ?? null);
        $this->assertSame((int) $trade->id, (int) ($payload['trade']['id'] ?? 0));
        // Полный payload сразу (без отдельного ui_heavy / debounce).
        $this->assertIsArray($payload['stats'] ?? null);
        $this->assertArrayHasKey('open_instruments', $payload);
        $this->assertArrayHasKey('open_instruments_user', $payload);
        $this->assertIsArray($payload['open_trade_row'] ?? null);
    }

    public function test_each_trade_event_publishes_stats_without_debounce(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Full Push Bot',
            'type' => 'impulse_lvl_1min',
            'is_active' => true,
        ]);

        $makeTrade = function () use ($bot): BotTrade {
            $trade = BotTrade::query()->create([
                'bot_id' => $bot->id,
                'instrument' => 'EURUSD_otc',
                'side' => 'CALL',
                'status' => BotTrade::STATUS_OPEN,
                'result' => BotTrade::RESULT_PENDING,
                'entry_time' => now(),
                'expiry_time' => now()->addMinute(),
                'entry_price' => 1.1,
                'payout_snapshot' => 80,
                'meta_json' => [],
            ]);
            $trade->setRelation('bot', $bot);

            return $trade;
        };

        $svc = new RecordingBotLiveEventsBroadcast;
        $this->app->instance(BotLiveEventsBroadcast::class, $svc);
        $svc->tradeOpened($makeTrade());
        $svc->tradeOpened($makeTrade());

        $opened = collect($svc->published)->where('event', BotLiveEventsBroadcast::EVENT_OPENED);
        $this->assertCount(2, $opened);
        foreach ($opened as $payload) {
            $this->assertIsArray($payload['stats'] ?? null);
        }
    }

    public function test_card_from_trade_includes_mirror_fields(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Mirror Bot',
            'type' => 'impulse_lvl_1min',
            'is_active' => true,
        ]);
        $trade = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now(),
            'expiry_time' => now()->addSeconds(30),
            'entry_price' => 1.2,
            'payout_snapshot' => 92,
            'meta_json' => [
                'mirror_status' => 'executed',
                'mirrored_at' => '2026-09-24T12:00:00+00:00',
                'mirror_amount' => 5,
            ],
        ]);
        $trade->setRelation('bot', $bot);

        $card = BotLiveEventsBroadcast::cardFromTrade($trade);
        $this->assertSame('executed', $card['mirror_status']);
        $this->assertSame('GBPUSD_otc', $card['instrument']);
        $this->assertSame('Mirror Bot', $card['bot_name']);
    }

    public function test_publish_scan_on_every_call_without_throttle(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Scan Bot',
            'type' => 'impulse_lvl_1min',
            'is_active' => true,
        ]);

        $svc = new RecordingBotLiveEventsBroadcast;
        $this->app->instance(BotLiveEventsBroadcast::class, $svc);

        // beginScan → writeState → publishScan.
        BotScanProgress::beginScan((int) $bot->id, 2);

        $svc->publishScan((int) $bot->id);
        $svc->publishScan((int) $bot->id);
        $svc->publishScan((int) $bot->id);

        $scans = collect($svc->published)->where('event', BotLiveEventsBroadcast::EVENT_SCAN);
        // 1 из beginScan + 3 явных вызова — без throttle.
        $this->assertCount(4, $scans);

        BotScanProgress::endScan((int) $bot->id);
    }
}
