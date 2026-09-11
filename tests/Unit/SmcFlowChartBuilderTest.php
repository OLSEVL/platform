<?php

namespace Tests\Unit;

use App\Services\Telegram\SmcFlowChartBuilder;
use PHPUnit\Framework\TestCase;

class SmcFlowChartBuilderTest extends TestCase
{
    public function test_builds_ut_3br_markers_and_nearest_ob_zone(): void
    {
        $builder = new SmcFlowChartBuilder;
        $candles = [
            ['time' => 100, 'open' => 1.0, 'high' => 1.1, 'low' => 0.9, 'close' => 1.02, 'volume' => 1],
            ['time' => 160, 'open' => 1.02, 'high' => 1.12, 'low' => 1.00, 'close' => 1.08, 'volume' => 1],
            ['time' => 220, 'open' => 1.08, 'high' => 1.15, 'low' => 1.05, 'close' => 1.10, 'volume' => 1],
        ];

        $chart = $builder->chartLevelsFromDecision([
            'side' => 'CALL',
            'features' => [
                'signal_context' => [
                    'ut' => ['side' => 'CALL', 'fresh' => true, 'bar_time' => 220],
                    'three_br' => ['bull_confirm_time' => 160, 'bear_confirm_time' => null],
                    'ob' => [
                        'bull_zones' => [
                            ['top' => 1.20, 'btm' => 1.05, 'left_time' => 100],
                            ['top' => 2.00, 'btm' => 1.90, 'left_time' => 40],
                        ],
                        'bear_zones' => [],
                    ],
                ],
                'entry_diagnosis' => [
                    'ob_ok' => true,
                    'three_br_source' => 'confirm',
                ],
            ],
        ], $candles);

        $this->assertSame('CALL', $chart['entry']['side']);
        $this->assertSame(220, $chart['entry']['time']);
        $this->assertCount(1, $chart['zones']);
        $this->assertEqualsWithDelta(1.05, $chart['zones'][0]['price_low'], 1e-8);
        $this->assertEqualsWithDelta(1.20, $chart['zones'][0]['price_high'], 1e-8);

        $texts = array_column($chart['markers'], 'text');
        $this->assertContains('UT BUY', $texts);
        $this->assertContains('3BR ?', $texts);
        $this->assertStringContainsString('OB', $chart['subtitle']);
    }
}
