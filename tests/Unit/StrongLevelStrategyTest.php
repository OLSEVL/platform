<?php

namespace Tests\Unit;

use App\Services\Bots\Levels\StrongLevelAnalyzer;
use App\Services\Bots\Strategies\StrongLevelStrategy;
use PHPUnit\Framework\TestCase;

class StrongLevelStrategyTest extends TestCase
{
    public function test_returns_null_when_analyzer_finds_no_setup(): void
    {
        $analyzer = $this->createMock(StrongLevelAnalyzer::class);
        $analyzer->method('analyzeEntry')->willReturn(null);

        $strategy = new StrongLevelStrategy($analyzer);
        $rows = array_fill(0, 200, [
            'time' => 1,
            'open' => 1.0,
            'high' => 1.1,
            'low' => 0.9,
            'close' => 1.0,
            'volume' => 1.0,
        ]);

        $this->assertNull($strategy->analyze($rows, null, [
            'config' => ['min_closed_bars' => 200],
            'mtf' => ['5' => array_fill(0, 30, $rows[0])],
            'payout' => 85.0,
        ]));
    }

    public function test_returns_decision_when_analyzer_signals_entry(): void
    {
        $analyzer = $this->createMock(StrongLevelAnalyzer::class);
        $analyzer->method('analyzeEntry')->willReturn([
            'side' => 'CALL',
            'confidence' => 0.72,
            'expiry_sec' => 180,
            'reasons_short' => ['test'],
            'analysis_text' => 'strong_level test',
            'features' => ['level_price' => 1.2345],
            'decision_version' => 'strong_level_v1',
        ]);

        $strategy = new StrongLevelStrategy($analyzer);
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
        $this->assertSame(180, $decision['expiry_sec']);
        $this->assertSame(1.2345, $decision['features']['level_price']);
    }
}
