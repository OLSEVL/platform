<?php

namespace Tests\Unit;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Services\ProxyRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyRotationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_dead_rotation_does_not_leave_proxy_dead_when_pool_exhausted(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setMaxInstrumentsPerProxy(1);

        $proxyA = ProxyEndpoint::query()->create([
            'url' => 'http://a:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $proxyB = ProxyEndpoint::query()->create([
            'url' => 'http://b:1@2.2.2.2:2',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $proxyA->id,
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'GBPUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $proxyB->id,
        ]);

        $toRotate = PocketInstrument::query()->create([
            'instrument' => 'USDJPY',
            'is_active' => true,
            'proxy_endpoint_id' => $proxyA->id,
        ]);

        $result = $rotation->rotateInstrumentProxy($toRotate, true);

        $this->assertNull($result);

        $proxyA->refresh();
        $this->assertSame(ProxyEndpoint::STATUS_ACTIVE, $proxyA->status);
        $this->assertNotSame('manual_mark_dead', $proxyA->last_error);

        $toRotate->refresh();
        $this->assertSame($proxyA->id, $toRotate->proxy_endpoint_id);
    }

    public function test_mark_dead_rotation_succeeds_when_free_slot_exists(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setMaxInstrumentsPerProxy(2);

        $proxyA = ProxyEndpoint::query()->create([
            'url' => 'http://a:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $proxyB = ProxyEndpoint::query()->create([
            'url' => 'http://b:1@2.2.2.2:2',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $proxyA->id,
        ]);

        $toRotate = PocketInstrument::query()->create([
            'instrument' => 'GBPUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $proxyA->id,
        ]);

        $replacement = $rotation->rotateInstrumentProxy($toRotate, true);

        $this->assertNotNull($replacement);
        $this->assertContains($replacement->id, [$proxyA->id, $proxyB->id]);
        $this->assertNotSame($proxyA->id, $replacement->id);

        $proxyA->refresh();
        $this->assertSame(ProxyEndpoint::STATUS_DEAD, $proxyA->status);

        $toRotate->refresh();
        $this->assertSame($replacement->id, $toRotate->proxy_endpoint_id);
    }

    public function test_ensure_rotates_off_dead_proxy(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setMaxInstrumentsPerProxy(2);

        $deadProxy = ProxyEndpoint::query()->create([
            'url' => 'http://bad:1@9.9.9.9:9',
            'status' => ProxyEndpoint::STATUS_DEAD,
        ]);
        $activeProxy = ProxyEndpoint::query()->create([
            'url' => 'http://good:1@8.8.8.8:8',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'EURJPY_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $deadProxy->id,
        ]);

        $rotation->setAutoRotationEnabled(true);
        $result = $rotation->ensureInstrumentHasReadyProxy($instrument->fresh());

        $this->assertNotNull($result);
        $this->assertSame($activeProxy->id, $result->id);
        $this->assertSame($activeProxy->id, $instrument->fresh()->proxy_endpoint_id);
    }

    public function test_mark_proxy_failure_moves_to_dead_when_streak_exceeded(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setAutoRotationEnabled(true);
        $rotation->setDeadStreakThreshold(3);

        $badProxy = ProxyEndpoint::query()->create([
            'url' => 'http://bad:1@9.9.9.9:9',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $goodProxy = ProxyEndpoint::query()->create([
            'url' => 'http://good:1@8.8.8.8:8',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $badProxy->id,
        ]);

        $rotation->markProxyFailure($instrument, 'connect_failed', 3);

        $badProxy->refresh();
        $instrument->refresh();

        $this->assertSame(ProxyEndpoint::STATUS_DEAD, $badProxy->status);
        $this->assertSame($goodProxy->id, $instrument->proxy_endpoint_id);
    }

    public function test_mark_proxy_endpoint_failure_from_mirror_marks_dead_by_failure_count(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setAutoRotationEnabled(true);
        $rotation->setDeadStreakThreshold(3);
        $rotation->setMaxInstrumentsPerProxy(2);

        $badProxy = ProxyEndpoint::query()->create([
            'url' => 'http://mirror-bad:1@7.7.7.7:7',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 0,
        ]);
        $goodProxy = ProxyEndpoint::query()->create([
            'url' => 'http://mirror-good:1@6.6.6.6:6',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'GBPUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $badProxy->id,
        ]);

        // Без instrument-streak: dead по накопленному failure_count (как у зеркала).
        $rotation->markProxyEndpointFailure($badProxy, 'Failed to connect to Pocket Option (connect returned false)', null, null, [
            'source' => 'mirror',
        ]);
        $this->assertSame(ProxyEndpoint::STATUS_ACTIVE, $badProxy->fresh()->status);
        $this->assertSame(1, (int) $badProxy->fresh()->failure_count);
        $this->assertStringContainsString('connect returned false', (string) $badProxy->fresh()->last_error);

        $rotation->markProxyEndpointFailure($badProxy->fresh(), 'Failed to connect to Pocket Option (connect returned false)', null, null, [
            'source' => 'mirror',
        ]);
        $rotation->markProxyEndpointFailure($badProxy->fresh(), 'Failed to connect to Pocket Option (connect returned false)', null, null, [
            'source' => 'mirror',
        ]);

        $badProxy->refresh();
        $instrument->refresh();

        $this->assertSame(ProxyEndpoint::STATUS_DEAD, $badProxy->status);
        $this->assertSame(3, (int) $badProxy->failure_count);
        $this->assertSame($goodProxy->id, $instrument->proxy_endpoint_id);
        $this->assertSame('mirror', $badProxy->dead_reason_log[0]['meta']['source'] ?? null);
    }

    public function test_mark_proxy_dead_manually_from_settings(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setMaxInstrumentsPerProxy(2);

        $badProxy = ProxyEndpoint::query()->create([
            'url' => 'http://manual-dead:1@5.5.5.5:5',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $goodProxy = ProxyEndpoint::query()->create([
            'url' => 'http://manual-good:1@4.4.4.4:4',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'USDJPY_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $badProxy->id,
        ]);

        $result = $rotation->markProxyDeadManually($badProxy);

        $this->assertFalse($result['already_dead']);
        $this->assertSame(1, $result['rotated']);
        $this->assertSame(0, $result['instruments_left']);
        $this->assertSame(ProxyEndpoint::STATUS_DEAD, $badProxy->fresh()->status);
        $this->assertSame('manual_mark_dead', $badProxy->fresh()->last_error);
        $this->assertSame($goodProxy->id, $instrument->fresh()->proxy_endpoint_id);
        $this->assertSame('settings_manual', $badProxy->fresh()->dead_reason_log[0]['meta']['source'] ?? null);
    }

    public function test_handle_stale_po_proxy_marks_dead_and_rotates_when_enabled(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setAutoRotationEnabled(true);
        $rotation->setDeadOnPoStaleEnabled(true);
        $rotation->setDeadOnPoStaleMinutes(30);
        $rotation->setMaxInstrumentsPerProxy(2);

        $staleProxy = ProxyEndpoint::query()->create([
            'url' => 'http://stale:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);
        $freshProxy = ProxyEndpoint::query()->create([
            'url' => 'http://fresh:1@2.2.2.2:2',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $staleProxy->id,
            'last_payout_event_at' => now()->subMinutes(45),
            'last_payout_at' => now()->subMinutes(45),
        ]);

        $replacement = $rotation->handleStalePoProxy($instrument->fresh());

        $staleProxy->refresh();
        $instrument->refresh();

        $this->assertSame(ProxyEndpoint::STATUS_DEAD, $staleProxy->status);
        $this->assertSame('po_stale', $staleProxy->last_error);
        $this->assertNotNull($replacement);
        $this->assertSame($freshProxy->id, $instrument->proxy_endpoint_id);

        $log = $staleProxy->fresh()->dead_reason_log;
        $this->assertIsArray($log);
        $this->assertSame('po_stale', $log[0]['reason'] ?? null);
        $this->assertSame($instrument->id, $log[0]['instrument_id'] ?? null);
    }

    public function test_handle_stale_po_proxy_ignores_payout_is_stale_flag_when_event_fresh(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setAutoRotationEnabled(true);
        $rotation->setDeadOnPoStaleEnabled(true);
        $rotation->setDeadOnPoStaleMinutes(30);

        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://ok:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'AUDUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $proxy->id,
            'last_payout_event_at' => now()->subMinutes(2),
            'last_payout_at' => now(),
            'payout_is_stale' => true,
        ]);

        $rotation->handleStalePoProxy($instrument->fresh());

        $this->assertSame(ProxyEndpoint::STATUS_ACTIVE, $proxy->fresh()->status);
    }

    public function test_handle_stale_po_proxy_noop_when_post_ingest_fresh_but_event_old(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setAutoRotationEnabled(true);
        $rotation->setDeadOnPoStaleEnabled(true);
        $rotation->setDeadOnPoStaleMinutes(30);

        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://ok:1@2.2.2.2:2',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'NZDUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $proxy->id,
            'last_payout_event_at' => now()->subHours(2),
            'last_payout_at' => now(),
            'payout_is_stale' => false,
        ]);

        $rotation->handleStalePoProxy($instrument->fresh());

        $this->assertSame(ProxyEndpoint::STATUS_ACTIVE, $proxy->fresh()->status);
    }

    public function test_handle_stale_po_proxy_noop_when_disabled(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setAutoRotationEnabled(true);
        $rotation->setDeadOnPoStaleEnabled(false);
        $rotation->setDeadOnPoStaleMinutes(30);

        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://stale:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'GBPUSD_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $proxy->id,
            'last_payout_event_at' => now()->subHours(2),
        ]);

        $rotation->handleStalePoProxy($instrument->fresh());

        $proxy->refresh();
        $instrument->refresh();

        $this->assertSame(ProxyEndpoint::STATUS_ACTIVE, $proxy->status);
        $this->assertSame($proxy->id, $instrument->proxy_endpoint_id);
    }

    public function test_handle_stale_po_proxy_noop_when_po_is_fresh(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setAutoRotationEnabled(true);
        $rotation->setDeadOnPoStaleEnabled(true);
        $rotation->setDeadOnPoStaleMinutes(30);

        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://ok:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'USDJPY_otc',
            'is_active' => true,
            'proxy_endpoint_id' => $proxy->id,
            'last_payout_event_at' => now()->subMinutes(5),
        ]);

        $rotation->handleStalePoProxy($instrument->fresh());

        $proxy->refresh();
        $instrument->refresh();

        $this->assertSame(ProxyEndpoint::STATUS_ACTIVE, $proxy->status);
        $this->assertSame($proxy->id, $instrument->proxy_endpoint_id);
    }

    public function test_pool_capacity_reports_exhausted_when_no_free_slots(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setMaxInstrumentsPerProxy(1);

        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://x:1@3.3.3.3:3',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $proxy->id,
        ]);

        $summary = $rotation->getPoolCapacitySummary();

        $this->assertTrue($summary['is_exhausted']);
        $this->assertSame(0, $summary['free_slots']);
        $this->assertSame(1, $summary['total_slots']);
    }

    public function test_replacement_prefers_proxy_with_fewer_failures_over_empty_slot(): void
    {
        $rotation = app(ProxyRotationService::class);
        $rotation->setMaxInstrumentsPerProxy(2);

        $badProxy = ProxyEndpoint::query()->create([
            'url' => 'http://bad:1@9.9.9.9:9',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 0,
        ]);
        $cleanButBusyProxy = ProxyEndpoint::query()->create([
            'url' => 'http://clean:1@8.8.8.8:8',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 0,
        ]);
        $emptyButNoisyProxy = ProxyEndpoint::query()->create([
            'url' => 'http://noisy:1@7.7.7.7:7',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 15,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $cleanButBusyProxy->id,
        ]);

        $toRotate = PocketInstrument::query()->create([
            'instrument' => 'GBPUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $badProxy->id,
        ]);

        $replacement = $rotation->rotateInstrumentProxy($toRotate, false);

        $this->assertNotNull($replacement);
        // Пустой слот на noisy-прокси, но у clean меньше ошибок — выбираем clean.
        $this->assertSame($cleanButBusyProxy->id, $replacement->id);
        $this->assertSame($cleanButBusyProxy->id, $toRotate->fresh()->proxy_endpoint_id);
    }
}
