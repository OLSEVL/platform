<?php

namespace Tests\Feature;

use App\Models\PocketInstrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IngestProxyHealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_proxy_health_connected_clears_ws_error(): void
    {
        $instrument = PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'last_ws_error' => 'connect_failed',
            'last_ws_error_at' => '2026-05-16 10:00:00',
        ]);

        $response = $this->postJson('/api/ingest-proxy-health', [
            'instrument' => 'EURUSD_otc',
            'event' => 'connected',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'event' => 'connected',
            ]);

        $instrument->refresh();
        $this->assertNotNull($instrument->last_ws_connected_at);
        $this->assertNull($instrument->last_ws_error);
        $this->assertNull($instrument->last_ws_error_at);
    }

    public function test_ingest_proxy_health_connect_failed_stores_error(): void
    {
        $instrument = PocketInstrument::query()->create([
            'instrument' => 'GBPUSD',
            'is_active' => true,
            'last_ws_connected_at' => '2026-05-16 09:00:00',
        ]);

        $response = $this->postJson('/api/ingest-proxy-health', [
            'instrument' => 'GBPUSD',
            'event' => 'connect_failed',
            'error' => 'Proxy tunnel refused',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'event' => 'connect_failed',
            ]);

        $instrument->refresh();
        $this->assertSame('Proxy tunnel refused', $instrument->last_ws_error);
        $this->assertNotNull($instrument->last_ws_error_at);
        $this->assertSame(
            Carbon::parse('2026-05-16 09:00:00')->utc()->format('Y-m-d H:i:s'),
            $instrument->last_ws_connected_at?->utc()->format('Y-m-d H:i:s')
        );
    }

    public function test_ingest_proxy_health_unknown_instrument_returns_404(): void
    {
        $this->postJson('/api/ingest-proxy-health', [
            'instrument' => 'MISSING',
            'event' => 'connected',
        ])->assertNotFound()
            ->assertJson(['ok' => false, 'error' => 'instrument_not_found']);
    }
}
