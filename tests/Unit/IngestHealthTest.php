<?php

namespace Tests\Unit;

use App\Models\PocketInstrument;
use App\Support\IngestHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_should_mark_dead_for_stale_po_ignores_payout_is_stale_flag(): void
    {
        $instrument = PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'last_payout_event_at' => now()->subMinutes(5),
            'last_payout_at' => now(),
            'payout_is_stale' => true,
        ]);

        $this->assertTrue(IngestHealth::isPayoutEventStale($instrument, 30));
        $this->assertFalse(IngestHealth::shouldMarkProxyDeadForStalePoEvent($instrument, 30));
    }

    public function test_should_mark_dead_when_event_and_post_are_old(): void
    {
        $instrument = PocketInstrument::query()->create([
            'instrument' => 'GBPUSD_otc',
            'is_active' => true,
            'last_payout_event_at' => now()->subMinutes(45),
            'last_payout_at' => now()->subMinutes(10),
            'payout_is_stale' => false,
        ]);

        $this->assertTrue(IngestHealth::shouldMarkProxyDeadForStalePoEvent($instrument, 30));
    }

    public function test_should_not_mark_dead_when_post_fresh_but_event_old(): void
    {
        $instrument = PocketInstrument::query()->create([
            'instrument' => 'USDJPY_otc',
            'is_active' => true,
            'last_payout_event_at' => now()->subHours(2),
            'last_payout_at' => now(),
            'payout_is_stale' => false,
        ]);

        $this->assertTrue(IngestHealth::isPayoutEventStale($instrument, 30));
        $this->assertFalse(IngestHealth::shouldMarkProxyDeadForStalePoEvent($instrument, 30));
    }
}
