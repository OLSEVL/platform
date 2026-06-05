<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\Bots\BotTradeMirrorService;
use App\Support\PocketSsidNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotTradeMirrorProfileOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mirror_uses_profile_ssid_not_instrument_token(): void
    {
        Http::fake([
            '*/api/place-order' => function ($request) {
                $body = $request->data();
                $ssid = (string) ($body['ssid'] ?? '');
                $this->assertStringContainsString('profile_session_xyz', $ssid);
                $this->assertStringNotContainsString('ingest_only_token', $ssid);

                return Http::response(['ok' => true, 'order_id' => 'ord-1', 'status' => 'active']);
            },
        ]);

        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => PocketSsidNormalizer::buildAuthString('profile_session_xyz', 99, true),
            'is_demo' => true,
            'order_amount' => 1,
            'mirror_trades_enabled' => true,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'session_token' => 'ingest_only_token',
            'uid' => '1',
            'is_demo' => true,
            'is_active' => true,
            'priority' => 1,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'T',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [],
        ]);

        $entry = Carbon::now('UTC');
        $trade = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => $entry,
            'expiry_time' => $entry->copy()->addSeconds(60),
            'entry_price' => 1.1,
            'meta_json' => ['expiry_sec_used' => 60],
        ]);

        app(BotTradeMirrorService::class)->mirrorTrade($trade->fresh());

        $trade->refresh();
        $this->assertSame('executed', $trade->meta_json['mirror_status'] ?? null);
        $this->assertSame('profile', $trade->meta_json['mirror_ssid_source'] ?? null);
    }
}
