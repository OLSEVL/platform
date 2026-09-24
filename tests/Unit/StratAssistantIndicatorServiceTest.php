<?php

namespace Tests\Unit;

use App\Services\Indicators\StratAssistantIndicatorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StratAssistantIndicatorServiceTest extends TestCase
{
    #[Test]
    public function test_classifies_inside_two_up_two_down_outside(): void
    {
        $this->assertSame('1', StratAssistantIndicatorService::classifyBar(
            ['high' => 10.0, 'low' => 9.0],
            ['high' => 11.0, 'low' => 8.0],
        ));
        $this->assertSame('2u', StratAssistantIndicatorService::classifyBar(
            ['high' => 12.0, 'low' => 9.5],
            ['high' => 11.0, 'low' => 8.0],
        ));
        $this->assertSame('2d', StratAssistantIndicatorService::classifyBar(
            ['high' => 10.5, 'low' => 7.0],
            ['high' => 11.0, 'low' => 8.0],
        ));
        $this->assertSame('3', StratAssistantIndicatorService::classifyBar(
            ['high' => 12.0, 'low' => 7.0],
            ['high' => 11.0, 'low' => 8.0],
        ));
    }

    #[Test]
    public function test_detects_two_two_reversal_and_three_one_two(): void
    {
        $svc = new StratAssistantIndicatorService;
        // Бары: эталон, 2u, 2d (22R down), затем 3, 1, 2u (312 up).
        $rows = [
            $this->bar(1, 10, 11, 9, 10.5),
            $this->bar(2, 10.5, 12, 10, 11.5), // 2u
            $this->bar(3, 11.0, 11.8, 9.5, 9.8), // 2d → 22R
            $this->bar(4, 9.8, 13, 8, 12), // 3
            $this->bar(5, 11.5, 12.5, 9, 11), // 1
            $this->bar(6, 11.2, 13.5, 10, 13), // 2u → 312
        ];
        $out = $svc->compute($rows);
        $texts = array_column($out['markers'], 'text');
        $this->assertContains('22R', $texts);
        $this->assertContains('312', $texts);
        $this->assertGreaterThan(0, $out['meta']['combos']);
    }

    #[Test]
    public function test_compute_5000_bars_stays_linear(): void
    {
        $rows = [];
        $price = 100.0;
        for ($i = 0; $i < 5000; $i++) {
            $price += (($i % 7) - 3) * 0.02;
            $high = $price + 0.15 + (($i % 5) * 0.01);
            $low = $price - 0.15 - (($i % 4) * 0.01);
            $rows[] = $this->bar($i + 1, $price, $high, $low, $price + 0.01);
        }
        $svc = new StratAssistantIndicatorService;
        $t0 = hrtime(true);
        $out = $svc->compute($rows);
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertCount(5000, $rows);
        $this->assertSame(4999, $out['meta']['typed']);
        $this->assertLessThan(80.0, $ms, 'Strat compute 5000 bars should stay well under 80ms, got '.$ms);
    }

    /**
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float}
     */
    private function bar(int $t, float $o, float $h, float $l, float $c): array
    {
        return ['time' => $t, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'volume' => 1.0];
    }
}
