<?php

namespace Tests\Feature;

use App\Models\PocketInstrument;
use App\Models\User;
use App\Models\UserTradingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualTradeOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_order_places_on_po_without_mirror_flag(): void
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
        // Зеркало выключено — ручной вход всё равно должен работать при валидном SSID.
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"tok","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0, 2.0],
            'martingale_step' => 0,
            'mirror_trades_enabled' => false,
            'mirror_wait_previous_settled' => false,
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

    public function test_dashboard_renders_manual_trade_panel(): void
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
        $response->assertSee('Купить', false);
        $response->assertSee('Продать', false);
    }
}
