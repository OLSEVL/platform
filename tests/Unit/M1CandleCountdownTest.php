<?php

namespace Tests\Unit;

use App\Support\M1CandleCountdown;
use PHPUnit\Framework\TestCase;

class M1CandleCountdownTest extends TestCase
{
    public function test_aligned_clocks_uses_raw_seconds(): void
    {
        $open = 1_000_000_000;
        $this->assertSame(45, M1CandleCountdown::secondsToClose($open, $open + 15));
    }

    public function test_past_close_returns_zero(): void
    {
        $open = 1_000_000_000;
        $this->assertSame(0, M1CandleCountdown::secondsToClose($open, $open + 70));
    }

    public function test_large_skew_uses_modulo_sixty(): void
    {
        $open = 1_776_005_760;
        $server = 1_775_998_573;
        $raw = ($open + 60) - $server;
        $this->assertGreaterThan(60, $raw);
        $expected = $raw % 60;
        $this->assertSame($expected, M1CandleCountdown::secondsToClose($open, $server));
    }
}
