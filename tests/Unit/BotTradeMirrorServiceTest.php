<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\Bots\BotTradeMirrorService;
use App\Services\PocketOptionsProxyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotTradeMirrorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mirror_skipped_when_disabled(): void
    {
        $trade = $this->createTradeWithSettings(mirrorEnabled: false);

        app(BotTradeMirrorService::class)->mirrorTrade($trade->fresh());

        $trade->refresh();
        $this->assertSame('skipped_disabled', $trade->meta_json['mirror_status'] ?? null);
    }

    public function test_mirror_executes_via_proxy_when_enabled(): void
    {
        Http::fake([
            '*/api/place-order' => Http::response([
                'ok' => true,
                'order_id' => 'po-99',
                'status' => 'open',
            ]),
        ]);

        $trade = $this->createTradeWithSettings(mirrorEnabled: true);

        app(BotTradeMirrorService::class)->mirrorTrade($trade->fresh());

        $trade->refresh();
        $this->assertSame('executed', $trade->meta_json['mirror_status'] ?? null);
        $this->assertEquals(4, $trade->meta_json['mirror_amount'] ?? null);
        $this->assertSame(1, $trade->meta_json['martingale_step_used'] ?? null);
        $this->assertSame('po-99', $trade->meta_json['pocket_order_id'] ?? null);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/api/place-order')
                && ($body['side'] ?? '') === 'CALL'
                && ($body['duration'] ?? 0) === 300
                && ($body['amount'] ?? 0) == 4;
        });
    }

    private function createTradeWithSettings(bool $mirrorEnabled): BotTrade
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"tok","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 2,
            'order_amounts' => [2.0, 4.0],
            'martingale_step' => 1,
            'mirror_trades_enabled' => $mirrorEnabled,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test',
            'type' => 'candle_reversal_confirm',
            'is_active' => true,
            'config' => [],
        ]);

        $entry = now();

        return BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => $entry,
            'expiry_time' => $entry->copy()->addSeconds(300),
            'entry_price' => 1.1,
            'meta_json' => ['expiry_sec_used' => 300],
        ]);
    }
}
