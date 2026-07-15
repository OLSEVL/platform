<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserTradingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfileTradingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_profile_trading(): void
    {
        $this->get(route('profile.trading.edit'))->assertRedirect(route('login'));
    }

    public function test_user_can_view_and_update_trading_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.trading.edit'))
            ->assertOk()
            ->assertSee('Включить зеркалирование')
            ->assertSee('Прокси зеркала');

        $ssid = '42["auth",{"session":"abc","uid":1,"isDemo":1,"platform":2}]';

        $this->actingAs($user)
            ->put(route('profile.trading.update'), [
                'pocket_ssid' => $ssid,
                'order_amounts' => '5.5, 10, 20',
                'is_demo' => '1',
                'mirror_trades_enabled' => '1',
                'mirror_wait_previous_settled' => '1',
            ])
            ->assertRedirect(route('profile.trading.edit'))
            ->assertSessionHas('status');

        $page = $this->actingAs($user)->get(route('profile.trading.edit'));
        $page->assertOk();
        $this->assertStringContainsString(
            '"session":"abc"',
            html_entity_decode($page->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );

        $settings = UserTradingSetting::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($settings);
        $this->assertTrue($settings->mirror_trades_enabled);
        $this->assertEquals(5.5, $settings->order_amount);
        $this->assertSame([5.5, 10.0, 20.0], $settings->orderAmountsList());
        $this->assertSame(0, $settings->martingale_step);
        $this->assertTrue($settings->mirror_wait_previous_settled);
        $this->assertStringContainsString('42["auth"', (string) $settings->pocket_ssid);
        $this->assertTrue($settings->canMirrorTrades());
    }

    public function test_user_can_manage_mirror_proxy_pool(): void
    {
        $user = User::factory()->create();
        $proxyA = \App\Models\ProxyEndpoint::query()->create([
            'url' => 'http://u:p@127.0.0.1:7001',
            'status' => \App\Models\ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 2,
        ]);
        \App\Models\ProxyEndpoint::query()->create([
            'url' => 'http://u:p@127.0.0.1:7002',
            'status' => \App\Models\ProxyEndpoint::STATUS_DEAD,
        ]);

        $this->actingAs($user)
            ->get(route('profile.mirror-proxies.edit'))
            ->assertOk()
            ->assertSee('Прокси для зеркала')
            ->assertSee('Авто: минимальная нагрузка')
            ->assertSee('127.0.0.1')
            ->assertDontSee(':7002');

        $this->actingAs($user)
            ->put(route('profile.mirror-proxies.update'), [
                'mirror_proxy_endpoint_ids' => [$proxyA->id],
            ])
            ->assertRedirect(route('profile.mirror-proxies.edit'))
            ->assertSessionHas('status');

        $settings = UserTradingSetting::query()->where('user_id', $user->id)->first();
        $this->assertSame([(int) $proxyA->id], $settings?->mirrorProxyEndpointIds());
        $this->assertFalse((bool) $settings?->mirror_proxy_auto);

        $this->actingAs($user)
            ->put(route('profile.mirror-proxies.update'), [
                'mirror_proxy_auto' => '1',
            ])
            ->assertRedirect(route('profile.mirror-proxies.edit'));

        $settings->refresh();
        $this->assertTrue((bool) $settings->mirror_proxy_auto);
        // Ручной пул не затирается при включении авто (disabled checkboxes).
        $this->assertSame([(int) $proxyA->id], $settings->mirrorProxyEndpointIds());

        // После включения авто в колонке видны кандидаты min-нагрузки.
        $this->actingAs($user)
            ->get(route('profile.mirror-proxies.edit'))
            ->assertOk()
            ->assertSee('title="Сейчас в пуле минимальной нагрузки"', false);

        // Симулируем auto-выбор — на странице должна появиться метка «последний».
        \App\Support\PocketMirrorProxy::rememberAutoPick($settings, (int) $proxyA->id);
        $this->actingAs($user)
            ->get(route('profile.mirror-proxies.edit'))
            ->assertOk()
            ->assertSee('последний')
            ->assertSee('Sticky / последний авто-выбор');
    }

    public function test_reset_martingale_button(): void
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"a","uid":1,"isDemo":1,"platform":2}]',
            'order_amounts' => [1.0, 2.0],
            'martingale_step' => 1,
            'order_amount' => 1,
            'mirror_trades_enabled' => true,
        ]);

        $this->actingAs($user)
            ->post(route('profile.trading.reset-martingale'))
            ->assertRedirect(route('profile.trading.edit'));

        $this->assertSame(0, (int) UserTradingSetting::query()->where('user_id', $user->id)->value('martingale_step'));
    }

    public function test_ssid_test_button_endpoint_checks_textarea_value(): void
    {
        Http::fake([
            '*/api/test-auth' => Http::response(['ok' => true]),
        ]);

        $user = User::factory()->create();
        $ssid = '42["auth",{"session":"livecheck","uid":99,"isDemo":1,"platform":2}]';

        $this->actingAs($user)
            ->postJson(route('profile.trading.test-connection'), [
                'pocket_ssid' => $ssid,
                'is_demo' => true,
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'format_ok' => true]);

        Http::assertSent(function ($request) use ($ssid) {
            $body = $request->data();

            return str_contains($request->url(), '/api/test-auth')
                && str_contains((string) ($body['ssid'] ?? ''), 'livecheck');
        });
    }

    public function test_ssid_test_rejects_invalid_format(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('profile.trading.test-connection'), [
                'pocket_ssid' => 'too short',
                'is_demo' => true,
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'format_ok' => false]);
    }
}
