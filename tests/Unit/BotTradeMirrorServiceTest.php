<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
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

    public function test_mirror_skipped_when_bot_mirror_disabled(): void
    {
        Http::fake();

        // Профиль зеркала вкл., у бота — выкл.: place-order не должен вызываться.
        $trade = $this->createTradeWithSettings(mirrorEnabled: true, botMirrorEnabled: false);

        app(BotTradeMirrorService::class)->mirrorTrade($trade->fresh());

        $trade->refresh();
        $this->assertSame('skipped_bot_mirror_disabled', $trade->meta_json['mirror_status'] ?? null);
        Http::assertNothingSent();
    }

    public function test_mirror_default_bot_flag_is_disabled(): void
    {
        // Без ключа в config зеркало бота выключено — иначе все боты по умолчанию шли бы на PO.
        $bot = new Bot(['config' => []]);
        $this->assertFalse($bot->isMirrorTradesEnabled());

        $bot->config = ['mirror_trades_enabled' => true];
        $this->assertTrue($bot->isMirrorTradesEnabled());

        $bot->config = ['mirror_trades_enabled' => false];
        $this->assertFalse($bot->isMirrorTradesEnabled());
    }

    public function test_mirror_executes_via_proxy_when_enabled(): void
    {
        Http::fake([
            '*/api/place-order' => Http::response([
                'ok' => true,
                'confirmed' => true,
                'confirmed_via' => 'send',
                'order_id' => 'po-99',
                'status' => 'open',
            ]),
        ]);

        $trade = $this->createTradeWithSettings(mirrorEnabled: true);

        app(BotTradeMirrorService::class)->mirrorTrade($trade->fresh());

        $trade->refresh();
        $this->assertSame('executed', $trade->meta_json['mirror_status'] ?? null);
        $this->assertSame('send', $trade->meta_json['mirror_confirmed_via'] ?? null);
        $this->assertSame('10.0.0.5', $trade->meta_json['mirror_proxy_host'] ?? null);
        $this->assertSame('http://mirroruser:***@10.0.0.5:8080', $trade->meta_json['mirror_proxy_url'] ?? null);
        $this->assertFalse($trade->meta_json['mirror_proxy_direct'] ?? true);
        $this->assertEquals(4, $trade->meta_json['mirror_amount'] ?? null);
        $this->assertSame(1, $trade->meta_json['martingale_step_used'] ?? null);
        $this->assertSame('po-99', $trade->meta_json['pocket_order_id'] ?? null);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/api/place-order')
                && ($body['side'] ?? '') === 'CALL'
                && ($body['duration'] ?? 0) === 300
                && ($body['amount'] ?? 0) == 4
                && ($body['proxy_url'] ?? '') === 'http://mirroruser:pass@10.0.0.5:8080';
        });
    }

    public function test_mirror_records_direct_when_no_proxy_in_pool(): void
    {
        Http::fake([
            '*/api/place-order' => Http::response(['ok' => true, 'order_id' => 'po-1']),
        ]);

        $trade = $this->createTradeWithSettings(mirrorEnabled: true, withProxy: false);

        app(BotTradeMirrorService::class)->mirrorTrade($trade->fresh());

        $trade->refresh();
        $this->assertTrue($trade->meta_json['mirror_proxy_direct'] ?? false);
        $this->assertNull($trade->meta_json['mirror_proxy_host'] ?? null);
    }

    private function createTradeWithSettings(
        bool $mirrorEnabled,
        bool $waitPrevious = false,
        bool $withProxy = true,
        bool $botMirrorEnabled = true,
    ): BotTrade {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"tok","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 2,
            'order_amounts' => [2.0, 4.0],
            'martingale_step' => 1,
            'mirror_trades_enabled' => $mirrorEnabled,
            'mirror_wait_previous_settled' => $waitPrevious,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test',
            'type' => 'candle_reversal_confirm',
            'is_active' => true,
            'config' => [
                // Явно задаём per-bot флаг (по умолчанию false, если ключа нет).
                'mirror_trades_enabled' => $botMirrorEnabled,
            ],
        ]);

        $entry = now();

        if ($withProxy) {
            $proxy = ProxyEndpoint::query()->create([
                'url' => 'http://mirroruser:pass@10.0.0.5:8080',
                'status' => ProxyEndpoint::STATUS_ACTIVE,
            ]);
            PocketInstrument::query()->create([
                'instrument' => 'EURUSD_otc',
                'display_name' => 'EUR/USD OTC',
                'session_token' => 'tok',
                'uid' => 1,
                'is_demo' => true,
                'is_active' => true,
                'priority' => 1,
                'proxy_endpoint_id' => $proxy->id,
            ]);
        }

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

    public function test_mirror_records_proxy_failure_on_connect_returned_false(): void
    {
        Http::fake([
            '*/api/place-order' => Http::response([
                'ok' => false,
                'error' => 'Failed to connect to Pocket Option (connect returned false)',
            ], 502),
        ]);

        $trade = $this->createTradeWithSettings(mirrorEnabled: true);
        $proxy = ProxyEndpoint::query()->where('url', 'http://mirroruser:pass@10.0.0.5:8080')->first();
        $this->assertNotNull($proxy);
        $this->assertSame(0, (int) $proxy->failure_count);

        app(BotTradeMirrorService::class)->mirrorTrade($trade->fresh());

        $trade->refresh();
        $proxy->refresh();

        $this->assertSame('failed', $trade->meta_json['mirror_status'] ?? null);
        $this->assertSame($proxy->id, (int) ($trade->meta_json['mirror_proxy_endpoint_id'] ?? 0));
        $this->assertSame(1, (int) $proxy->failure_count);
        $this->assertStringContainsString('connect returned false', (string) $proxy->last_error);
        $this->assertNotNull($proxy->last_failed_at);
    }

    public function test_mirror_skips_when_previous_platform_trade_still_open(): void
    {
        Http::fake([
            '*/api/place-order' => Http::response([
                'ok' => true,
                'order_id' => 'po-1',
                'status' => 'open',
            ]),
        ]);

        $trade1 = $this->createTradeWithSettings(mirrorEnabled: true, waitPrevious: true);
        app(BotTradeMirrorService::class)->mirrorTrade($trade1->fresh());
        $trade1->refresh();
        $this->assertSame('executed', $trade1->meta_json['mirror_status'] ?? null);

        $bot = $trade1->bot;
        $entry = now();
        $trade2 = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => $entry,
            'expiry_time' => $entry->copy()->addSeconds(300),
            'entry_price' => 1.2,
            'meta_json' => ['expiry_sec_used' => 300],
        ]);

        app(BotTradeMirrorService::class)->mirrorTrade($trade2->fresh());
        $trade2->refresh();
        $this->assertSame('skipped_wait_previous_trade', $trade2->meta_json['mirror_status'] ?? null);
        // Только place-order первой сделки — вторая пропущена без запроса к PO.
        Http::assertSentCount(1);
    }

    public function test_mirror_skips_when_previous_expired_but_not_settled(): void
    {
        Http::fake([
            '*/api/place-order' => Http::response([
                'ok' => true,
                'order_id' => 'po-2',
                'status' => 'open',
            ]),
        ]);

        $trade1 = $this->createTradeWithSettings(mirrorEnabled: true, waitPrevious: true);
        app(BotTradeMirrorService::class)->mirrorTrade($trade1->fresh());
        $trade1->refresh();
        $this->assertSame('executed', $trade1->meta_json['mirror_status'] ?? null);

        // Expiry прошёл, но paper ещё open — шаг мартингейла ещё не обновлён → ждём settle.
        $trade1->expiry_time = now()->subSecond();
        $trade1->save();

        $bot = $trade1->bot;
        $entry = now();
        $trade2 = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => $entry,
            'expiry_time' => $entry->copy()->addSeconds(300),
            'entry_price' => 1.2,
            'meta_json' => ['expiry_sec_used' => 300],
        ]);

        app(BotTradeMirrorService::class)->mirrorTrade($trade2->fresh());
        $trade2->refresh();
        $this->assertSame('skipped_wait_previous_trade', $trade2->meta_json['mirror_status'] ?? null);
        Http::assertSentCount(1);
    }

    public function test_mirror_allows_when_previous_platform_trade_settled(): void
    {
        Http::fake([
            '*/api/place-order' => Http::response([
                'ok' => true,
                'order_id' => 'po-2',
                'status' => 'open',
            ]),
        ]);

        $trade1 = $this->createTradeWithSettings(mirrorEnabled: true, waitPrevious: true);
        app(BotTradeMirrorService::class)->mirrorTrade($trade1->fresh());
        $trade1->refresh();
        $this->assertSame('executed', $trade1->meta_json['mirror_status'] ?? null);

        // После settle блокировка снимается — следующая может идти на PO.
        $trade1->status = BotTrade::STATUS_SETTLED;
        $trade1->result = BotTrade::RESULT_LOSS;
        $trade1->expiry_time = now()->subSecond();
        $trade1->save();

        $bot = $trade1->bot;
        $entry = now();
        $trade2 = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => $entry,
            'expiry_time' => $entry->copy()->addSeconds(300),
            'entry_price' => 1.2,
            'meta_json' => ['expiry_sec_used' => 300],
        ]);

        app(BotTradeMirrorService::class)->mirrorTrade($trade2->fresh());
        $trade2->refresh();
        $this->assertSame('executed', $trade2->meta_json['mirror_status'] ?? null);
        Http::assertSentCount(2);
    }

    public function test_mirror_does_not_skip_when_wait_setting_disabled(): void
    {
        Http::fake([
            '*/api/place-order' => Http::response([
                'ok' => true,
                'order_id' => 'po-2',
                'status' => 'open',
            ]),
        ]);

        $trade1 = $this->createTradeWithSettings(mirrorEnabled: true, waitPrevious: false);
        app(BotTradeMirrorService::class)->mirrorTrade($trade1->fresh());

        $bot = $trade1->bot;
        $entry = now();
        $trade2 = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => $entry,
            'expiry_time' => $entry->copy()->addSeconds(300),
            'entry_price' => 1.2,
            'meta_json' => ['expiry_sec_used' => 300],
        ]);

        app(BotTradeMirrorService::class)->mirrorTrade($trade2->fresh());
        $trade2->refresh();
        $this->assertSame('executed', $trade2->meta_json['mirror_status'] ?? null);
        Http::assertSentCount(2);
    }
}
