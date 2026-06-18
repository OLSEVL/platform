<?php

namespace Tests\Unit;

use App\Services\CandleStructureZoneDetector;
use PHPUnit\Framework\TestCase;

class CandleStructureZoneDetectorTest extends TestCase
{
    public function test_returns_lux_order_blocks_from_integrated_detector(): void
    {
        $t = 1_700_000_000;
        $candles = [
            ['time' => $t, 'open' => 1.0, 'high' => 1.01, 'low' => 0.99, 'close' => 1.0],
            ['time' => $t + 60, 'open' => 1.0, 'high' => 1.02, 'low' => 0.995, 'close' => 1.01],
            ['time' => $t + 120, 'open' => 1.01, 'high' => 1.04, 'low' => 1.0, 'close' => 1.03],
            ['time' => $t + 180, 'open' => 1.03, 'high' => 1.035, 'low' => 1.02, 'close' => 1.025],
            ['time' => $t + 240, 'open' => 1.025, 'high' => 1.03, 'low' => 1.02, 'close' => 1.022],
            ['time' => $t + 300, 'open' => 1.022, 'high' => 1.028, 'low' => 1.02, 'close' => 1.024],
            ['time' => $t + 360, 'open' => 1.03, 'high' => 1.05, 'low' => 1.02, 'close' => 1.04],
            ['time' => $t + 420, 'open' => 1.04, 'high' => 1.06, 'low' => 1.035, 'close' => 1.055],
        ];

        $zones = (new CandleStructureZoneDetector)->activeZones($candles, [
            'length' => 3,
            'show_bull' => 3,
            'show_bear' => 0,
        ]);
        $obs = array_values(array_filter($zones, fn ($z) => str_starts_with($z['type'], 'order_block')));

        $this->assertNotEmpty($obs);
        $this->assertArrayHasKey('fill', $obs[0]);
    }

    public function test_bullish_fvg_mitigated_when_single_candle_covers_gap(): void
    {
        $t = 1_700_000_000;
        $candles = [
            ['time' => $t, 'open' => 1.0, 'high' => 1.01, 'low' => 0.99, 'close' => 1.0],
            ['time' => $t + 60, 'open' => 1.0, 'high' => 1.02, 'low' => 0.995, 'close' => 1.01],
            ['time' => $t + 120, 'open' => 1.01, 'high' => 1.05, 'low' => 1.03, 'close' => 1.04],
            ['time' => $t + 180, 'open' => 1.04, 'high' => 1.06, 'low' => 1.0, 'close' => 1.02],
        ];

        $zones = (new CandleStructureZoneDetector)->activeZones($candles);
        $fvgs = array_values(array_filter($zones, fn ($z) => $z['type'] === 'imbalance_bull'));
        $this->assertCount(0, $fvgs);
    }
}
