<?php

namespace Tests\Unit;

use App\Support\SweepRaidDemarkerFilter;
use PHPUnit\Framework\TestCase;

class SweepRaidDemarkerFilterTest extends TestCase
{
    public function test_disabled_filter_always_passes(): void
    {
        $this->assertTrue(SweepRaidDemarkerFilter::passes([], 'PUT', [
            'srl_demarker_enabled' => false,
        ]));
    }

    public function test_put_requires_exit_from_overbought_zone(): void
    {
        $candles = [];
        for ($i = 1; $i <= 8; $i++) {
            $p = 10.0 + $i * 0.01;
            $candles[] = $this->candle($i, $p, $p + 0.05, $p - 0.02, $p + 0.02);
        }
        // Резкий откат high — DeMarker должен упасть с верхней зоны.
        $candles[] = $this->candle(9, 10.08, 10.09, 9.5, 9.55);

        $cfg = [
            'srl_demarker_enabled' => true,
            'srl_demarker_period' => 1,
            'srl_demarker_oversold' => 10,
            'srl_demarker_overbought' => 50,
        ];

        $cross = SweepRaidDemarkerFilter::crossingOnLastBar($candles, 'PUT', $cfg);
        $this->assertNotNull($cross);
        $this->assertGreaterThanOrEqual(0.5, $cross['previous']);
        $this->assertLessThan(0.5, $cross['current']);
    }

    public function test_call_requires_exit_from_oversold_zone(): void
    {
        $candles = [];
        for ($i = 1; $i <= 8; $i++) {
            $p = 10.0 - $i * 0.01;
            $candles[] = $this->candle($i, $p, $p + 0.02, $p - 0.05, $p - 0.02);
        }
        $candles[] = $this->candle(9, 9.92, 10.5, 9.9, 10.45);

        $cfg = [
            'srl_demarker_enabled' => true,
            'srl_demarker_period' => 1,
            'srl_demarker_oversold' => 50,
            'srl_demarker_overbought' => 90,
        ];

        $cross = SweepRaidDemarkerFilter::crossingOnLastBar($candles, 'CALL', $cfg);
        $this->assertNotNull($cross);
        $this->assertLessThanOrEqual(0.5, $cross['previous']);
        $this->assertGreaterThan(0.5, $cross['current']);
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float,volume:float} */
    private function candle(int $time, float $open, float $high, float $low, float $close): array
    {
        return compact('time', 'open', 'high', 'low', 'close') + ['volume' => 1.0];
    }
}
