<?php

namespace Tests\Unit;

use App\Services\Bots\Levels\CandleReversalLevelAnalyzer;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;
use PHPUnit\Framework\TestCase;

class CandleReversalLevelAnalyzerTest extends TestCase
{
    public function test_returns_null_when_both_confirm_blocks_disabled(): void
    {
        $pa = $this->createMock(PriceActionDgtIndicatorService::class);
        $smc = $this->createMock(SmcFlowIndicatorService::class);

        $analyzer = new CandleReversalLevelAnalyzer($pa, $smc);
        $bar = ['time' => 1, 'open' => 1.0, 'high' => 1.1, 'low' => 0.9, 'close' => 1.0, 'volume' => 1.0];
        $bars = array_fill(0, 220, $bar);

        $this->assertNull($analyzer->analyzeEntry(
            $bars,
            null,
            [
                'min_closed_bars' => 200,
                'm5_rejection_enabled' => false,
                'm1_reversal_enabled' => false,
            ],
            ['5' => array_fill(0, 30, $bar)],
            [],
            [],
            85.0,
        ));
    }
}
