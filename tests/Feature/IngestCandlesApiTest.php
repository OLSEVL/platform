<?php

namespace Tests\Feature;

use App\Models\InstrumentCandle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestCandlesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_candles_accepts_payload_matching_python_ingest_stream(): void
    {
        $payload = [
            'instrument' => 'EURUSD_otc',
            'timeframe' => 1,
            'candles' => [
                [
                    'time' => 1_700_000_000,
                    'open' => 1.1001,
                    'high' => 1.1005,
                    'low' => 1.0999,
                    'close' => 1.1003,
                    'volume' => 42.5,
                ],
            ],
        ];

        $response = $this->postJson('/api/ingest-candles', $payload);

        $response->assertOk()
            ->assertJson(['ok' => true, 'count' => 1]);

        $this->assertDatabaseHas('candles', [
            'instrument' => 'EURUSD_otc',
            'timeframe' => 1,
            'time' => 1_700_000_000,
        ]);

        $row = InstrumentCandle::query()
            ->where('instrument', 'EURUSD_otc')
            ->where('time', 1_700_000_000)
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals(42.5, $row->volume);
    }

    public function test_ingest_candles_allows_omitted_volume_field(): void
    {
        $payload = [
            'instrument' => 'EURUSD',
            'timeframe' => 1,
            'candles' => [
                [
                    'time' => 1_700_000_060,
                    'open' => 1.0,
                    'high' => 1.0,
                    'low' => 1.0,
                    'close' => 1.0,
                ],
            ],
        ];

        $this->postJson('/api/ingest-candles', $payload)->assertOk();

        $this->assertDatabaseHas('candles', [
            'instrument' => 'EURUSD',
            'time' => 1_700_000_060,
            'volume' => 0,
        ]);
    }

    public function test_ingest_candles_accepts_s5_timeframe_payload(): void
    {
        $payload = [
            'instrument' => 'GBPUSD_otc',
            // 4005 = S5. Храним его как базовый точный ряд, из которого далее строятся S15/S30.
            'timeframe' => 4005,
            'candles' => [
                [
                    'time' => 1_700_000_005,
                    'open' => 1.24501,
                    'high' => 1.24520,
                    'low' => 1.24495,
                    'close' => 1.24510,
                    'volume' => 9.0,
                ],
            ],
        ];

        $response = $this->postJson('/api/ingest-candles', $payload);
        $response->assertOk()->assertJson(['ok' => true, 'count' => 1]);

        $this->assertDatabaseHas('candles', [
            'instrument' => 'GBPUSD_otc',
            'timeframe' => 4005,
            'time' => 1_700_000_005,
        ]);
    }
}
