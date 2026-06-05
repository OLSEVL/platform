<?php

namespace Tests\Unit;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Services\ProxyDiagnosticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyDiagnosticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_false_dead_filter_matches_dead_proxy_with_http_ok(): void
    {
        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@1.2.3.4:8080',
            'status' => ProxyEndpoint::STATUS_DEAD,
            'last_http_check_at' => now(),
            'last_http_check_result' => 'ok',
            'last_http_status' => 200,
            'last_http_latency_ms' => 150,
            'last_http_check_mode' => 'quick',
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $proxy->id,
        ]);

        $service = app(ProxyDiagnosticsService::class);
        $result = $service->buildPoolHealth('false_dead', 5);

        $this->assertCount(1, $result['rows']);
        $this->assertTrue($result['rows'][0]['is_false_dead']);
        $this->assertSame('ok', $result['rows'][0]['http']['layer']);
    }

    public function test_stale_payout_marks_payout_layer_warn_or_fail(): void
    {
        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@5.6.7.8:8080',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        PocketInstrument::query()->create([
            'instrument' => 'GBPUSD',
            'is_active' => true,
            'proxy_endpoint_id' => $proxy->id,
            'payout_is_stale' => true,
            'last_payout_event_at' => now()->subHours(2),
            'last_payout_at' => now()->subMinutes(1),
        ]);

        $service = app(ProxyDiagnosticsService::class);
        $result = $service->buildPoolHealth('problems', 5);

        $this->assertCount(1, $result['rows']);
        $this->assertContains($result['rows'][0]['payout']['layer'], ['warn', 'fail']);
    }

    public function test_persist_http_check_result_updates_proxy(): void
    {
        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://u:p@9.9.9.9:1',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
        ]);

        app(ProxyDiagnosticsService::class)->persistHttpCheckResult($proxy, 'ok', 200, 99, 'quick');

        $proxy->refresh();
        $this->assertSame('ok', $proxy->last_http_check_result);
        $this->assertSame(200, $proxy->last_http_status);
        $this->assertSame(99, $proxy->last_http_latency_ms);
        $this->assertSame('quick', $proxy->last_http_check_mode);
        $this->assertNotNull($proxy->last_http_check_at);
    }
}
