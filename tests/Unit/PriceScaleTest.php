<?php

namespace Tests\Unit;

use App\Support\PriceScale;
use Tests\TestCase;

class PriceScaleTest extends TestCase
{
    public function test_micro_prices_get_eight_decimals(): void
    {
        // LBP и NGN: единый формат 8 знаков (ранее LBP давал 10, NGN — 6 и схлопывался).
        $this->assertSame(8, PriceScale::decimalsForMagnitude(0.00000571));
        $this->assertSame(8, PriceScale::decimalsForMagnitude(0.00070976));
        $this->assertSame(8, PriceScale::decimalsForMagnitude(0.009999));
        $this->assertEqualsWithDelta(0.00070976, PriceScale::roundPrice(0.0007097612, 8), 1e-12);
    }

    public function test_round_market_preserves_micro_ao_diffs(): void
    {
        // Типичная разность EMA на NGN (~2e-7) не должна схлопываться в 0.
        $this->assertSame(0.00000021, PriceScale::roundMarket(0.000000214));
        $this->assertSame(8, PriceScale::MARKET_DECIMALS);
    }

    public function test_regular_fx_keeps_five_decimals(): void
    {
        $this->assertSame(5, PriceScale::decimalsForMagnitude(1.08543));
        $this->assertSame(5, PriceScale::decimalsForMagnitude(0.01));
    }

    public function test_resolve_from_candles_uses_max_abs_price(): void
    {
        $decimals = PriceScale::resolveFromCandles([
            ['open' => 0.0007096, 'high' => 0.0007098, 'low' => 0.0007095, 'close' => 0.0007097],
        ]);
        $this->assertSame(8, $decimals);
        $this->assertSame(
            ['precision' => 8, 'min_move' => 1e-8],
            PriceScale::chartFormat($decimals)
        );
    }
}
