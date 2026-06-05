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
}
