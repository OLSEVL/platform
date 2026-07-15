<?php

namespace Tests\Unit;

use App\Models\ProxyEndpoint;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Support\PocketMirrorProxy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PocketMirrorProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_random_from_user_pool_picks_only_selected_active(): void
    {
        $activeA = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.1:1000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $activeB = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.2:2000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.3:3000',
            'status' => ProxyEndpoint::STATUS_DEAD,
        ]);

        $user = User::factory()->create();
        $settings = UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'mirror_proxy_endpoint_ids' => [$activeA->id, $activeB->id],
        ]);

        $picked = [];
        for ($i = 0; $i < 20; $i++) {
            $url = PocketMirrorProxy::randomFromUserPool($settings);
            $this->assertNotNull($url);
            $picked[$url] = true;
            $this->assertTrue(in_array($url, [$activeA->url, $activeB->url], true));
        }

        $this->assertNotEmpty($picked);
    }

    public function test_resolve_for_mirror_falls_back_when_pool_empty(): void
    {
        $fallback = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.9:9000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $user = User::factory()->create();
        $settings = UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'mirror_proxy_endpoint_ids' => [],
        ]);

        $this->assertSame($fallback->url, PocketMirrorProxy::resolveForMirror($settings, null));
    }

    public function test_auto_picks_least_loaded_active(): void
    {
        $light = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.1:1000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $heavy = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.2:2000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.3:3000',
            'status' => ProxyEndpoint::STATUS_DEAD,
        ]);

        // Две пары на heavy → light должен выиграть в auto.
        \App\Models\PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $heavy->id,
            'session_token' => 't1',
            'uid' => '1',
        ]);
        \App\Models\PocketInstrument::query()->create([
            'instrument' => 'GBPUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $heavy->id,
            'session_token' => 't2',
            'uid' => '1',
        ]);

        $user = User::factory()->create();
        $settings = UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'mirror_proxy_auto' => true,
            'mirror_proxy_endpoint_ids' => [$heavy->id],
        ]);

        $this->assertSame($light->url, PocketMirrorProxy::resolveForMirror($settings, null));

        $settings->refresh();
        $this->assertSame((int) $light->id, (int) $settings->mirror_proxy_auto_last_endpoint_id);
        $this->assertNotNull($settings->mirror_proxy_auto_last_at);
        $this->assertSame(1, $settings->mirrorProxyAutoPickCounts()[$light->id] ?? 0);

        // Sticky: второй resolve не прыгает на другой active, даже если нагрузка изменилась.
        \App\Models\PocketInstrument::query()->create([
            'instrument' => 'USDJPY_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $light->id,
            'session_token' => 't3',
            'uid' => '1',
        ]);
        \App\Models\PocketInstrument::query()->create([
            'instrument' => 'AUDUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $light->id,
            'session_token' => 't4',
            'uid' => '1',
        ]);
        // Теперь heavy легче, но sticky всё ещё light.
        $this->assertSame($light->url, PocketMirrorProxy::resolveForMirror($settings->fresh(), null));
        $this->assertSame((int) $light->id, (int) $settings->fresh()->mirror_proxy_auto_last_endpoint_id);
    }

    public function test_sticky_skips_dead_and_repicks(): void
    {
        $a = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.1:1000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $b = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.2:2000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $user = User::factory()->create();
        $settings = UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'mirror_proxy_auto' => true,
            'mirror_proxy_auto_last_endpoint_id' => $a->id,
        ]);

        $this->assertSame($a->url, PocketMirrorProxy::resolveForMirror($settings, null));

        $a->status = ProxyEndpoint::STATUS_DEAD;
        $a->save();

        $url = PocketMirrorProxy::resolveForMirror($settings->fresh(), null);
        $this->assertSame($b->url, $url);
        $this->assertSame((int) $b->id, (int) $settings->fresh()->mirror_proxy_auto_last_endpoint_id);
    }

    public function test_peek_sticky_does_not_mutate(): void
    {
        $ep = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.1:1000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $user = User::factory()->create();
        $settings = UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'mirror_proxy_auto' => true,
            'mirror_proxy_auto_last_endpoint_id' => $ep->id,
        ]);

        $peek = PocketMirrorProxy::peekStickyEndpoint($settings);
        $this->assertNotNull($peek);
        $this->assertSame((int) $ep->id, (int) $peek['id']);
        $this->assertNull($settings->fresh()->mirror_proxy_auto_last_at);
    }

    public function test_least_loaded_active_ids_returns_min_load_set(): void
    {
        $a = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.1:1000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $b = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.2:2000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $heavy = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.3:3000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        \App\Models\PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $heavy->id,
            'session_token' => 't1',
            'uid' => '1',
        ]);

        $ids = PocketMirrorProxy::leastLoadedActiveIds();
        sort($ids);
        $expected = [(int) $a->id, (int) $b->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }
}
