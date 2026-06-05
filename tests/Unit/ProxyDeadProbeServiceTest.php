<?php

namespace Tests\Unit;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Services\ProxyDeadProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProxyDeadProbeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pick_next_dead_proxy_rotates_cursor(): void
    {
        $dead1 = ProxyEndpoint::query()->create([
            'url' => 'http://d1:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_DEAD,
        ]);
        $dead2 = ProxyEndpoint::query()->create([
            'url' => 'http://d2:1@2.2.2.2:2',
            'status' => ProxyEndpoint::STATUS_DEAD,
        ]);

        $probe = app(ProxyDeadProbeService::class);

        $first = $probe->pickNextProbeProxy();
        $this->assertNotNull($first);
        $probe->advanceCursor($first->id);

        $second = $probe->pickNextProbeProxy();
        $this->assertSame($dead2->id, $second?->id);

        $probe->advanceCursor($dead2->id);
        $wrapped = $probe->pickNextProbeProxy();
        $this->assertSame($dead1->id, $wrapped?->id);
    }

    public function test_pick_next_probe_proxy_in_all_mode_includes_active(): void
    {
        $active = ProxyEndpoint::query()->create([
            'url' => 'http://a:1@3.3.3.3:3',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        ProxyEndpoint::query()->create([
            'url' => 'http://d:1@4.4.4.4:4',
            'status' => ProxyEndpoint::STATUS_DEAD,
        ]);

        DB::table('app_settings')->insert([
            ['key' => ProxyDeadProbeService::SETTING_AUTO_TEST_ALL_ENABLED, 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $probe = app(ProxyDeadProbeService::class);
        $first = $probe->pickNextProbeProxy();

        $this->assertNotNull($first);
        $this->assertSame($active->id, $first->id);
    }

    public function test_run_single_probe_fails_and_restores_instrument_proxy(): void
    {
        $activeProxy = ProxyEndpoint::query()->create([
            'url' => 'http://a:1@9.9.9.9:9',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $deadProxy = ProxyEndpoint::query()->create([
            'url' => 'http://dead:1@8.8.8.8:8',
            'status' => ProxyEndpoint::STATUS_DEAD,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $activeProxy->id,
        ]);

        DB::table('app_settings')->insert([
            ['key' => ProxyDeadProbeService::SETTING_AUTO_TEST_ENABLED, 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => ProxyDeadProbeService::SETTING_PROBE_INSTRUMENT, 'value' => 'EURUSD_otc', 'created_at' => now(), 'updated_at' => now()],
            ['key' => ProxyDeadProbeService::SETTING_PROBE_WINDOW_SEC, 'value' => '5', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $probe = app(ProxyDeadProbeService::class);
        $result = $probe->runSingleProbe($deadProxy);

        $this->assertSame('fail', $result['status']);
        $this->assertSame('po_not_healthy', $result['reason']);
        $this->assertSame(ProxyEndpoint::STATUS_DEAD, $deadProxy->fresh()->status);
        $this->assertSame($activeProxy->id, PocketInstrument::query()->where('instrument', 'EURUSD_otc')->value('proxy_endpoint_id'));
        $this->assertNull($probe->getActiveLock());
    }

    public function test_run_single_probe_succeeds_when_instrument_already_healthy_on_target(): void
    {
        $activeProxy = ProxyEndpoint::query()->create([
            'url' => 'http://a:1@9.9.9.9:9',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $activeProxy->id,
            'last_payout_at' => now(),
            'last_payout_event_at' => now(),
            'payout_is_stale' => false,
            'last_ws_connected_at' => now(),
        ]);

        DB::table('app_settings')->insert([
            ['key' => ProxyDeadProbeService::SETTING_AUTO_TEST_ALL_ENABLED, 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => ProxyDeadProbeService::SETTING_PROBE_INSTRUMENT, 'value' => 'EURUSD_otc', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $probe = app(ProxyDeadProbeService::class);
        $result = $probe->runSingleProbe($activeProxy);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('probe_already_healthy_on_proxy', $result['reason']);
        $this->assertNull($probe->getActiveLock());
    }

    public function test_run_single_probe_succeeds_when_other_instruments_healthy_on_target(): void
    {
        $homeProxy = ProxyEndpoint::query()->create([
            'url' => 'http://h:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $targetProxy = ProxyEndpoint::query()->create([
            'url' => 'http://t:1@2.2.2.2:2',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $homeProxy->id,
            'last_payout_at' => now(),
            'last_payout_event_at' => now(),
            'payout_is_stale' => false,
            'last_ws_connected_at' => now(),
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'GBPUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $targetProxy->id,
            'last_payout_at' => now(),
            'last_payout_event_at' => now(),
            'payout_is_stale' => false,
            'last_ws_connected_at' => now(),
        ]);

        DB::table('app_settings')->insert([
            ['key' => ProxyDeadProbeService::SETTING_AUTO_TEST_ALL_ENABLED, 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => ProxyDeadProbeService::SETTING_PROBE_INSTRUMENT, 'value' => 'EURUSD_otc', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $probe = app(ProxyDeadProbeService::class);
        $result = $probe->runSingleProbe($targetProxy);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('proxy_instruments_already_healthy', $result['reason']);
        $this->assertSame($homeProxy->id, PocketInstrument::query()->where('instrument', 'EURUSD_otc')->value('proxy_endpoint_id'));
    }

    public function test_po_ok_ignores_stale_flag_when_event_timestamp_is_fresh(): void
    {
        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://a:1@9.9.9.9:9',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $proxy->id,
            'last_payout_event_at' => now(),
            'payout_is_stale' => true,
        ]);

        DB::table('app_settings')->insert([
            ['key' => ProxyDeadProbeService::SETTING_AUTO_TEST_ALL_ENABLED, 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => ProxyDeadProbeService::SETTING_PROBE_INSTRUMENT, 'value' => 'EURUSD_otc', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $probe = app(ProxyDeadProbeService::class);
        $result = $probe->runSingleProbe($proxy);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('probe_already_healthy_on_proxy', $result['reason']);
    }

    public function test_probe_lock_blocks_rotation_service(): void
    {
        $dead = ProxyEndpoint::query()->create([
            'url' => 'http://d:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_DEAD,
        ]);
        $ready = ProxyEndpoint::query()->create([
            'url' => 'http://r:1@2.2.2.2:2',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'X_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $dead->id,
        ]);

        DB::table('app_settings')->updateOrInsert(
            ['key' => ProxyDeadProbeService::SETTING_PROBE_LOCK],
            [
                'value' => json_encode([
                    'instrument_id' => $instrument->id,
                    'instrument' => 'X_otc',
                    'original_proxy_endpoint_id' => $ready->id,
                    'testing_proxy_id' => $dead->id,
                    'started_at' => now()->toIso8601String(),
                ]),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $rotation = app(\App\Services\ProxyRotationService::class);
        $rotation->setAutoRotationEnabled(true);

        $after = $rotation->ensureInstrumentHasReadyProxy($instrument->fresh());

        $this->assertSame($dead->id, $after?->id);
        $this->assertSame($dead->id, $instrument->fresh()->proxy_endpoint_id);
    }
}
