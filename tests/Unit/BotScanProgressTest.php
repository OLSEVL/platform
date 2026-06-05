<?php

namespace Tests\Unit;

use App\Support\BotScanProgress;
use Tests\TestCase;

class BotScanProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BotScanProgress::endScan(4242);
        BotScanProgress::endScan(5252);
        BotScanProgress::endScan(6262);
    }

    public function test_dot_colors_during_scan_cycle(): void
    {
        $botId = 4242;

        BotScanProgress::beginScan($botId, 3);
        $this->assertSame(3, BotScanProgress::remainingCount($botId));
        $this->assertSame('gray', BotScanProgress::dotColor($botId, 'EURUSD_otc'));

        BotScanProgress::setCurrentInstrument($botId, 'EURUSD_otc');
        $this->assertSame(3, BotScanProgress::remainingCount($botId));
        $this->assertSame('red', BotScanProgress::dotColor($botId, 'EURUSD_otc'));
        $this->assertSame('gray', BotScanProgress::dotColor($botId, 'GBPUSD_otc'));

        BotScanProgress::finishInstrument($botId, 'EURUSD_otc');
        $this->assertSame(2, BotScanProgress::remainingCount($botId));
        $this->assertSame('green', BotScanProgress::dotColor($botId, 'EURUSD_otc'));
        $this->assertSame('gray', BotScanProgress::dotColor($botId, 'GBPUSD_otc'));

        BotScanProgress::endScan($botId);
        $this->assertNull(BotScanProgress::remainingCount($botId));
        $this->assertSame('gray', BotScanProgress::dotColor($botId, 'EURUSD_otc'));
    }

    public function test_ineligible_payout_returns_no_dot(): void
    {
        $botId = 6262;
        BotScanProgress::endScan($botId);

        BotScanProgress::beginScan($botId, 2);
        $this->assertSame('none', BotScanProgress::dotColor($botId, 'LOW_otc', 70.0, 80.0));
        $this->assertSame('gray', BotScanProgress::dotColor($botId, 'HIGH_otc', 85.0, 80.0));

        BotScanProgress::setCurrentInstrument($botId, 'HIGH_otc');
        $this->assertSame('red', BotScanProgress::dotColor($botId, 'HIGH_otc', 85.0, 80.0));
        $this->assertSame('none', BotScanProgress::dotColor($botId, 'LOW_otc', 70.0, 80.0));

        BotScanProgress::endScan($botId);
    }

    public function test_stale_end_scan_does_not_clear_newer_run(): void
    {
        $botId = 5252;

        $oldRunId = BotScanProgress::beginScan($botId, 5);
        BotScanProgress::setCurrentInstrument($botId, 'EURUSD_otc');

        BotScanProgress::beginScan($botId, 5);
        $this->assertTrue(BotScanProgress::isScanActive($botId));
        $this->assertSame(5, BotScanProgress::remainingCount($botId));

        BotScanProgress::endScan($botId, $oldRunId);
        $this->assertTrue(BotScanProgress::isScanActive($botId));
        $this->assertSame(5, BotScanProgress::remainingCount($botId));

        BotScanProgress::endScan($botId);
        $this->assertNull(BotScanProgress::remainingCount($botId));
    }
}
