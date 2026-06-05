<?php

namespace Tests\Feature;

use App\Models\PocketInstrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IngestPayoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_payout_fresh_event_updates_last_payout_event_at(): void
    {
        $instrument = PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'is_active' => true,
            'current_payout' => 70,
            'last_payout_event_at' => '2026-05-01 10:00:00',
            'payout_is_stale' => true,
        ]);

        $eventAt = '2026-05-16T12:00:00+00:00';
        $ingestAt = '2026-05-16T12:00:30+00:00';

        $response = $this->postJson('/api/ingest-payout', [
            'instrument' => 'EURUSD_otc',
            'current_payout' => 85,
            'payout_source' => 'payout_update',
            'last_payout_event_at' => $eventAt,
            'last_payout_at' => $ingestAt,
            'is_stale' => false,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'is_stale' => false,
                'payout_is_stale' => false,
            ]);

        $instrument->refresh();
        $this->assertEquals(85.0, (float) $instrument->current_payout);
        $this->assertFalse($instrument->payout_is_stale);
        $this->assertSame(
            Carbon::parse($eventAt)->utc()->format('Y-m-d H:i:s'),
            $instrument->last_payout_event_at?->utc()->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            Carbon::parse($ingestAt)->utc()->format('Y-m-d H:i:s'),
            $instrument->last_payout_at?->utc()->format('Y-m-d H:i:s')
        );
    }

    public function test_ingest_payout_stale_does_not_overwrite_last_payout_event_at(): void
    {
        $originalEvent = '2026-05-16 10:00:00';

        $instrument = PocketInstrument::query()->create([
            'instrument' => 'GBPUSD',
            'is_active' => true,
            'current_payout' => 80,
            'last_payout_event_at' => $originalEvent,
            'last_payout_at' => '2026-05-16 10:05:00',
            'payout_is_stale' => false,
        ]);

        $response = $this->postJson('/api/ingest-payout', [
            'instrument' => 'GBPUSD',
            'current_payout' => 80,
            'payout_source' => 'payout_update',
            'last_payout_event_at' => '2026-05-16T14:00:00+00:00',
            'last_payout_at' => '2026-05-16T14:00:30+00:00',
            'is_stale' => true,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'is_stale' => true,
                'payout_is_stale' => true,
            ]);

        $instrument->refresh();
        $this->assertTrue($instrument->payout_is_stale);
        $this->assertSame(
            Carbon::parse($originalEvent)->utc()->format('Y-m-d H:i:s'),
            $instrument->last_payout_event_at?->utc()->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-05-16 14:00:30',
            $instrument->last_payout_at?->utc()->format('Y-m-d H:i:s')
        );
    }
}
