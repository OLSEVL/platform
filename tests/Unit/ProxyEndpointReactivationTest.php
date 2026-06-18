<?php

namespace Tests\Unit;

use App\Models\ProxyEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyEndpointReactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reactivate_from_dead_clears_errors_and_counter(): void
    {
        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://u:p@1.2.3.4:8080',
            'status' => ProxyEndpoint::STATUS_DEAD,
            'failure_count' => 17,
            'last_error' => 'connect_failed: timeout',
            'last_failed_at' => now()->subHour(),
        ]);

        $this->assertTrue($proxy->reactivateFromDead());

        $proxy->refresh();
        $this->assertSame(ProxyEndpoint::STATUS_ACTIVE, $proxy->status);
        $this->assertSame(0, $proxy->failure_count);
        $this->assertNull($proxy->last_error);
        $this->assertNull($proxy->last_failed_at);
    }

    public function test_reactivate_from_dead_is_noop_for_active_proxy(): void
    {
        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://u:p@1.2.3.4:8080',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 5,
            'last_error' => 'still here',
        ]);

        $this->assertFalse($proxy->reactivateFromDead());

        $proxy->refresh();
        $this->assertSame(5, $proxy->failure_count);
        $this->assertSame('still here', $proxy->last_error);
    }

    public function test_bulk_dead_to_active_reset_attributes_zeroes_failure_count(): void
    {
        ProxyEndpoint::query()->create([
            'url' => 'http://d1:1@1.1.1.1:1',
            'status' => ProxyEndpoint::STATUS_DEAD,
            'failure_count' => 10,
            'last_error' => 'err-1',
        ]);
        ProxyEndpoint::query()->create([
            'url' => 'http://d2:1@2.2.2.2:2',
            'status' => ProxyEndpoint::STATUS_DEAD,
            'failure_count' => 3,
            'last_error' => 'err-2',
        ]);

        ProxyEndpoint::query()
            ->where('status', ProxyEndpoint::STATUS_DEAD)
            ->update(ProxyEndpoint::deadToActiveResetAttributes());

        $this->assertSame(
            0,
            ProxyEndpoint::query()->where('failure_count', '>', 0)->count(),
        );
        $this->assertSame(
            2,
            ProxyEndpoint::query()->where('status', ProxyEndpoint::STATUS_ACTIVE)->count(),
        );
    }
}
