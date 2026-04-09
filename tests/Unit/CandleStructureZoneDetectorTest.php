<?php

namespace Tests\Unit;

use App\Services\CandleStructureZoneDetector;
use PHPUnit\Framework\TestCase;

class CandleStructureZoneDetectorTest extends TestCase
{
    public function test_bullish_order_block_active_until_close_inside_zone(): void
    {
        $t = 1_700_000_000;
        $candles = [
            ['time' => $t, 'open' => 1.0, 'high' => 1.02, 'low' => 0.99, 'close' => 1.01], // bull — not OB base
            ['time' => $t + 60, 'open' => 1.01, 'high' => 1.03, 'low' => 1.0, 'close' => 1.0], // bear (OB)
            ['time' => $t + 120, 'open' => 1.0, 'high' => 1.06, 'low' => 0.99, 'close' => 1.05], // bull impulse breaks high 1.03
            ['time' => $t + 180, 'open' => 1.05, 'high' => 1.07, 'low' => 1.04, 'close' => 1.06], // no mitigation
        ];

        $zones = (new CandleStructureZoneDetector)->activeZones($candles);
        $obs = array_values(array_filter($zones, fn ($z) => $z['type'] === 'order_block_bull'));
        $this->assertCount(1, $obs);
        $this->assertEqualsWithDelta(1.0, $obs[0]['price_low'], 1e-9);
        $this->assertEqualsWithDelta(1.03, $obs[0]['price_high'], 1e-9);
    }

    public function test_bullish_order_block_mitigated_when_close_below_ob_low(): void
    {
        $t = 1_700_000_000;
        $candles = [
            ['time' => $t, 'open' => 1.0, 'high' => 1.02, 'low' => 0.99, 'close' => 1.01],
            ['time' => $t + 60, 'open' => 1.01, 'high' => 1.03, 'low' => 1.0, 'close' => 1.0],
            ['time' => $t + 120, 'open' => 1.0, 'high' => 1.06, 'low' => 0.99, 'close' => 1.05],
            ['time' => $t + 180, 'open' => 1.05, 'high' => 1.06, 'low' => 0.97, 'close' => 0.98], // close < OB low 1.0
        ];

        $zones = (new CandleStructureZoneDetector)->activeZones($candles);
        $obs = array_values(array_filter($zones, fn ($z) => $z['type'] === 'order_block_bull'));
        $this->assertCount(0, $obs);
    }

    public function test_bullish_fvg_mitigated_when_single_candle_covers_gap(): void
    {
        $t = 1_700_000_000;
        $candles = [
            ['time' => $t, 'open' => 1.0, 'high' => 1.01, 'low' => 0.99, 'close' => 1.0],
            ['time' => $t + 60, 'open' => 1.0, 'high' => 1.02, 'low' => 0.995, 'close' => 1.01],
            ['time' => $t + 120, 'open' => 1.01, 'high' => 1.05, 'low' => 1.03, 'close' => 1.04], // low 1.03 > high A 1.01 → gap [1.01, 1.03]
            ['time' => $t + 180, 'open' => 1.04, 'high' => 1.06, 'low' => 1.0, 'close' => 1.02], // spans full gap
        ];

        $zones = (new CandleStructureZoneDetector)->activeZones($candles);
        $fvgs = array_values(array_filter($zones, fn ($z) => $z['type'] === 'imbalance_bull'));
        $this->assertCount(0, $fvgs);
    }
}
