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

    public function test_next_dispatch_unix_aligns_to_thirty_second_boundary(): void
    {
        // 1_700_000_010 % 30 === 0 — граница :00/:30.
        $this->assertSame(0, 1_700_000_010 % 30);
        $this->assertSame(1_700_000_010, BotScanCoordinator::nextDispatchUnix(1_700_000_010));
        $this->assertSame(1_700_000_040, BotScanCoordinator::nextDispatchUnix(1_700_000_011));
        $this->assertSame(1_700_000_040, BotScanCoordinator::nextDispatchUnix(1_700_000_039));
        $this->assertSame(1_700_000_040, BotScanCoordinator::nextDispatchUnix(1_700_000_040));
        $this->assertSame(1_700_000_070, BotScanCoordinator::nextDispatchUnix(1_700_000_041));
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

    public function test_normalize_scan_interval_defaults_to_m1(): void
    {
        $this->assertSame(60, BotScanCoordinator::normalizeScanIntervalSec(null));
        $this->assertSame(60, BotScanCoordinator::normalizeScanIntervalSec(90));
        $this->assertSame(60, BotScanCoordinator::normalizeScanIntervalSec(60));
        $this->assertSame(30, BotScanCoordinator::normalizeScanIntervalSec(30));
    }

    public function test_missing_config_key_means_m1_interval(): void
    {
        $bot = new \App\Models\Bot;
        $bot->forceFill(['id' => 9901, 'config' => []]);
        $this->assertSame(60, BotScanCoordinator::scanIntervalSecForBot($bot));

        $bot->forceFill(['id' => 9901, 'config' => ['scan_interval_sec' => 30]]);
        $this->assertSame(30, BotScanCoordinator::scanIntervalSecForBot($bot));
    }

    public function test_m1_bot_is_not_due_until_sixty_seconds_after_last_start(): void
    {
        $bot = new \App\Models\Bot;
        $bot->forceFill(['id' => 9902, 'config' => ['scan_interval_sec' => 60]]);
        \App\Support\BotScanProgress::forgetLastStarted(9902);

        $this->assertTrue(BotScanCoordinator::isDueToSpawn($bot, 1_700_000_000));

        \App\Support\BotScanProgress::markStarted(9902, 1_700_000_000);
        $this->assertFalse(BotScanCoordinator::isDueToSpawn($bot, 1_700_000_030));
        $this->assertTrue(BotScanCoordinator::isDueToSpawn($bot, 1_700_000_060));
        $this->assertSame(1_700_000_060, BotScanCoordinator::nextDueUnix($bot, 1_700_000_030));

        \App\Support\BotScanProgress::forgetLastStarted(9902);
    }

    public function test_s30_bot_is_due_every_thirty_seconds(): void
    {
        $bot = new \App\Models\Bot;
        $bot->forceFill(['id' => 9903, 'config' => ['scan_interval_sec' => 30]]);
        \App\Support\BotScanProgress::forgetLastStarted(9903);
        \App\Support\BotScanProgress::markStarted(9903, 1_700_000_000);

        $this->assertFalse(BotScanCoordinator::isDueToSpawn($bot, 1_700_000_029));
        $this->assertTrue(BotScanCoordinator::isDueToSpawn($bot, 1_700_000_030));

        \App\Support\BotScanProgress::forgetLastStarted(9903);
    }
}
