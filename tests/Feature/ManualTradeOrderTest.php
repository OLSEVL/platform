<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Support\ManualTradeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualTradeOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_order_creates_paper_and_places_on_po_without_mirror_flag(): void
    {
        Http::fake([
            '*/api/warm-client' => Http::response(['ok' => true, 'warm' => true]),
            '*/api/place-order' => Http::response([
                'ok' => true,
                'confirmed' => true,
                'confirmed_via' => 'send',
                'order_id' => 'manual-42',
                'status' => 'open',
            ]),
        ]);

        $user = User::factory()->create();
        // Зеркало профиля выкл. — ручной вход всё равно должен работать при валидном SSID.
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"tok","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0, 2.0],
            'martingale_step' => 0,
            'mirror_trades_enabled' => false,
            'mirror_wait_previous_settled' => false,
            'manual_respect_wait_previous' => false,
            'manual_advance_martingale' => false,
            'manual_amount_from_martingale' => false,
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
            'is_demo' => true,
            'current_payout' => 92,
        ]);

        $response = $this->actingAs($user)->postJson(route('trading.manual-order'), [
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'amount' => 5,
            'duration' => 300,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order_id', 'manual-42')
            ->assertJsonPath('side', 'CALL')
            ->assertJsonPath('amount', 5)
            ->assertJsonPath('is_demo', true);

        $this->assertNotNull($response->json('trade_id'));

        $bot = Bot::query()
            ->where('user_id', $user->id)
            ->where('type', ManualTradeConfig::BOT_TYPE)
            ->first();
        $this->assertNotNull($bot);
        $this->assertSame(ManualTradeConfig::BOT_NAME, $bot->name);
        $this->assertFalse((bool) $bot->is_active);

        $trade = BotTrade::query()->find((int) $response->json('trade_id'));
        $this->assertNotNull($trade);
        $this->assertSame($bot->id, $trade->bot_id);
        $this->assertSame('EURUSD_otc', $trade->instrument);
        $this->assertSame(BotTrade::STATUS_OPEN, $trade->status);
        $meta = $trade->meta_json;
        $this->assertSame(ManualTradeConfig::META_SOURCE, $meta['source'] ?? null);
        $this->assertSame('executed', $meta['mirror_status'] ?? null);
        $this->assertSame(5.0, (float) ($meta['mirror_amount'] ?? 0));
        $this->assertSame('manual-42', $meta['pocket_order_id'] ?? null);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/api/place-order')
                && ($body['instrument'] ?? '') === 'EURUSD_otc'
                && ($body['side'] ?? '') === 'CALL'
                && ($body['amount'] ?? 0) == 5
                && ($body['duration'] ?? 0) === 300
                && ($body['is_demo'] ?? null) === true;
        });
    }

    public function test_manual_order_respects_wait_previous_when_flag_on(): void
    {
        Http::fake();

        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"tok","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'martingale_step' => 0,
            'mirror_trades_enabled' => false,
            'manual_respect_wait_previous' => true,
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Bot',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [],
        ]);
        BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now(),
            'expiry_time' => now()->addMinutes(5),
            'entry_price' => 1.2,
            'meta_json' => ['mirror_status' => 'executed'],
        ]);

        $response = $this->actingAs($user)->postJson(route('trading.manual-order'), [
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'amount' => 1,
            'duration' => 60,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'wait_previous');
        Http::assertNothingSent();
    }

    public function test_manual_settings_update_flags(): void
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"tok","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0, 2.0],
            'martingale_step' => 1,
            'mirror_trades_enabled' => false,
            'manual_respect_wait_previous' => true,
            'manual_advance_martingale' => false,
            'manual_amount_from_martingale' => false,
        ]);

        $response = $this->actingAs($user)->putJson(route('trading.manual-settings'), [
            'manual_respect_wait_previous' => false,
            'manual_advance_martingale' => true,
            'manual_amount_from_martingale' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('manual_respect_wait_previous', false)
            ->assertJsonPath('manual_advance_martingale', true)
            ->assertJsonPath('manual_amount_from_martingale', true)
            ->assertJsonPath('martingale_amount', 2);

        $settings = UserTradingSetting::query()->where('user_id', $user->id)->first();
        $this->assertFalse((bool) $settings->manual_respect_wait_previous);
        $this->assertTrue((bool) $settings->manual_advance_martingale);
        $this->assertTrue((bool) $settings->manual_amount_from_martingale);
    }

    public function test_manual_order_uses_martingale_amount_when_flag_on(): void
    {
        Http::fake([
            '*/api/warm-client' => Http::response(['ok' => true, 'warm' => true]),
            '*/api/place-order' => Http::response([
                'ok' => true,
                'confirmed' => true,
                'confirmed_via' => 'send',
                'order_id' => 'mg-9',
                'status' => 'open',
            ]),
        ]);

        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"tok","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0, 3.5, 7.0],
            'martingale_step' => 1,
            'mirror_trades_enabled' => false,
            'manual_respect_wait_previous' => false,
            'manual_amount_from_martingale' => true,
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
        ]);

        // UI шлёт 99, сервер должен заменить на шаг МГ = 3.5.
        $response = $this->actingAs($user)->postJson(route('trading.manual-order'), [
            'instrument' => 'EURUSD_otc',
            'side' => 'PUT',
            'amount' => 99,
            'duration' => 60,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('amount', 3.5);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/api/place-order')
                && (float) ($body['amount'] ?? 0) === 3.5;
        });
    }

    public function test_manual_order_rejects_invalid_ssid(): void
    {
        Http::fake();

        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'martingale_step' => 0,
            'mirror_trades_enabled' => false,
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson(route('trading.manual-order'), [
            'instrument' => 'EURUSD_otc',
            'side' => 'PUT',
            'amount' => 1,
            'duration' => 60,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'ssid_invalid');
        Http::assertNothingSent();
    }

    public function test_dashboard_renders_manual_trade_panel_and_settings(): void
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"tok","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'martingale_step' => 0,
            'mirror_trades_enabled' => false,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="manual-trade-panel"', false);
        $response->assertSee('id="manual-trade-buy"', false);
        $response->assertSee('id="manual-trade-sell"', false);
        $response->assertSee('id="manual-trade-settings-btn"', false);
        $response->assertSee('id="manual-trade-settings-modal"', false);
        $response->assertSee('id="manual-trade-duration"', false);
        $response->assertSee('id="manual-trade-duration-popover"', false);
        $response->assertSee('data-dur-preset="3"', false);
        $response->assertSee('S3', false);
        $response->assertSee('H4', false);
        $response->assertDontSee('Своё…', false);
        $response->assertSee('Купить', false);
        $response->assertSee('Продать', false);
    }
}
