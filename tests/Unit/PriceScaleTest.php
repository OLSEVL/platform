<?php

namespace Tests\Unit;

use App\Support\PriceScale;
use Tests\TestCase;

class PriceScaleTest extends TestCase
{
    public function test_micro_lbp_price_gets_ten_decimals(): void
    {
        $this->assertSame(10, PriceScale::decimalsForMagnitude(0.00000571));
        $this->assertEqualsWithDelta(0.0000057123, PriceScale::roundPrice(0.0000057123, 10), 1e-12);
    }

    public function test_resolve_from_candles_uses_max_abs_price(): void
    {
        $decimals = PriceScale::resolveFromCandles([
            ['open' => 0.0000057, 'high' => 0.0000058, 'low' => 0.0000056, 'close' => 0.0000057],
        ]);
        $this->assertSame(10, $decimals);
    }
}
