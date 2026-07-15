<?php

namespace Tests\Unit;

use App\Support\BotScanCoordinator;
use Tests\TestCase;

class BotScanCoordinatorTest extends TestCase
{
    public function test_parse_bot_id_from_cmdline(): void
    {
        $this->assertSame(
            11,
            BotScanCoordinator::parseBotIdFromCmdline('/usr/bin/php8.3 artisan bots:scan --bot_id=11')
        );
        $this->assertNull(
            BotScanCoordinator::parseBotIdFromCmdline('/usr/bin/php8.3 artisan bots:scan')
        );
    }

    public function test_cmdline_matches_scan_per_bot(): void
    {
        $cmd11 = '/usr/bin/php8.3 artisan bots:scan --bot_id=11';
        $cmd16 = '/usr/bin/php8.3 artisan bots:scan --bot_id=16';
        $cmdAll = '/usr/bin/php8.3 artisan bots:scan';
        $cmdDispatch = '/usr/bin/php8.3 artisan bots:scan-dispatch';

        $this->assertTrue(BotScanCoordinator::cmdlineMatchesScan($cmd11, 11));
        $this->assertFalse(BotScanCoordinator::cmdlineMatchesScan($cmd11, 16));
        $this->assertTrue(BotScanCoordinator::cmdlineMatchesScan($cmd16, 16));
        $this->assertFalse(BotScanCoordinator::cmdlineMatchesScan($cmd16, 11));
        $this->assertTrue(BotScanCoordinator::cmdlineMatchesScan($cmdAll, null));
        // null = все scan, включая per-bot (нужно watchdog'у для reap).
        $this->assertTrue(BotScanCoordinator::cmdlineMatchesScan($cmd11, null));
        $this->assertFalse(BotScanCoordinator::cmdlineMatchesScan($cmdDispatch, null));
    }

    public function test_bot_lock_name(): void
    {
        $this->assertSame('bots:scan:bot:11', BotScanCoordinator::botLockName(11));
    }

    public function test_log_path_for_bot(): void
    {
        $path = BotScanCoordinator::logPathForBot(11);
        $this->assertStringEndsWith('bots-scan-11.log', $path);
    }

    public function test_scan_max_elapsed_is_between_one_and_two_hours(): void
    {
        // Дефолтный константный порог выше нормального цикла (~минуты), но << суток.
        $this->assertGreaterThanOrEqual(3600, BotScanCoordinator::SCAN_MAX_ELAPSED_SEC);
        $this->assertLessThanOrEqual(7200, BotScanCoordinator::SCAN_MAX_ELAPSED_SEC);
        $this->assertContains(BotScanCoordinator::SCAN_MAX_ELAPSED_SEC, BotScanCoordinator::ALLOWED_WATCHDOG_MAX_ELAPSED_SEC);
    }

    public function test_scan_max_elapsed_label(): void
    {
        $this->assertSame('60 мин', BotScanCoordinator::scanMaxElapsedLabel(3600));
        $this->assertSame('15 мин', BotScanCoordinator::scanMaxElapsedLabel(900));
    }
}
