<?php

namespace Tests\Unit;

use App\Services\Bots\Levels\CandleReversalLevelAnalyzer;
use App\Services\Bots\Strategies\CandleReversalLevelStrategy;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;
use PHPUnit\Framework\TestCase;

class CandleReversalLevelStrategyTest extends TestCase
{
    public function test_returns_null_when_analyzer_finds_no_setup(): void
    {
        $analyzer = $this->createMock(CandleReversalLevelAnalyzer::class);
        $analyzer->method('analyzeEntry')->willReturn(null);

        $strategy = new CandleReversalLevelStrategy($analyzer);
        $bar = [
            'time' => 1,
            'open' => 1.0,
            'high' => 1.1,
            'low' => 0.9,
            'close' => 1.0,
            'volume' => 1.0,
        ];
        $rows = array_fill(0, 200, $bar);

        $this->assertNull($strategy->analyze($rows, null, [
            'config' => ['min_closed_bars' => 200],
            'mtf' => ['5' => array_fill(0, 30, $bar)],
            'payout' => 85.0,
        ]));
    }

    public function test_returns_decision_when_analyzer_signals_bounce(): void
    {
        $analyzer = $this->createMock(CandleReversalLevelAnalyzer::class);
        $analyzer->method('analyzeEntry')->willReturn([
            'side' => 'CALL',
            'confidence' => 0.72,
            'expiry_sec' => 300,
            'reasons_short' => ['test'],
            'analysis_text' => 'candle_reversal_level test',
            'features' => ['level_price' => 1.2345],
            'decision_version' => 'candle_reversal_confirm_v3_level_bounce',
        ]);

        $strategy = new CandleReversalLevelStrategy($analyzer);
        $bar = [
            'time' => 1,
            'open' => 1.0,
            'high' => 1.1,
            'low' => 0.9,
            'close' => 1.0,
            'volume' => 1.0,
        ];
        $rows = array_fill(0, 200, $bar);

        $decision = $strategy->analyze($rows, null, [
            'config' => [],
            'mtf' => ['5' => array_fill(0, 30, $bar)],
            'payout' => 85.0,
        ]);

        $this->assertIsArray($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame(300, $decision['expiry_sec']);
        $this->assertSame(1.2345, $decision['features']['level_price']);
    }
}
