<?php

namespace Tests\Unit;

use App\Services\PlatformProcessMonitorService;
use App\Support\ArtisanProcessFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformProcessMonitorTest extends TestCase
{
    use RefreshDatabase;
    public function test_parse_elapsed_to_seconds(): void
    {
        $this->assertSame(45, ArtisanProcessFinder::parseElapsedToSeconds('45'));
        $this->assertSame(125, ArtisanProcessFinder::parseElapsedToSeconds('2:05'));
        $this->assertSame(3725, ArtisanProcessFinder::parseElapsedToSeconds('1:02:05'));
        $this->assertSame(90125, ArtisanProcessFinder::parseElapsedToSeconds('1-01:02:05'));
        $this->assertNull(ArtisanProcessFinder::parseElapsedToSeconds(''));
    }

    public function test_parse_queue_list_from_cmdline(): void
    {
        $combined = '/usr/bin/php artisan queue:work database --queue=backtest,mirror,default,telegram-analyst --sleep=1';
        $this->assertSame(
            ['backtest', 'mirror', 'default', 'telegram-analyst'],
            ArtisanProcessFinder::parseQueueListFromCmdline($combined),
        );

        $telegramOnly = '/usr/bin/php8.3 artisan queue:work --queue=telegram-analyst --timeout=600';
        $this->assertSame(['telegram-analyst'], ArtisanProcessFinder::parseQueueListFromCmdline($telegramOnly));

        $noQueue = '/usr/bin/php artisan queue:work database --sleep=1';
        $this->assertNull(ArtisanProcessFinder::parseQueueListFromCmdline($noQueue));
    }

    public function test_queue_work_handles_default_mirror_queues(): void
    {
        $combined = '/usr/bin/php artisan queue:work database --queue=backtest,mirror,default,telegram-analyst';
        $this->assertTrue(ArtisanProcessFinder::queueWorkHandlesAnyOf($combined, ['default', 'mirror']));

        $telegramOnly = '/usr/bin/php8.3 artisan queue:work --queue=telegram-analyst --timeout=600';
        $this->assertFalse(ArtisanProcessFinder::queueWorkHandlesAnyOf($telegramOnly, ['default', 'mirror']));

        $defaultOnly = '/usr/bin/php artisan queue:work --queue=default';
        $this->assertTrue(ArtisanProcessFinder::queueWorkHandlesAnyOf($defaultOnly, ['default', 'mirror']));

        $implicitDefault = '/usr/bin/php artisan queue:work database --sleep=1';
        $this->assertTrue(ArtisanProcessFinder::queueWorkHandlesAnyOf($implicitDefault, ['default', 'mirror']));
    }

    public function test_default_queue_worker_detection_ignores_telegram_only_legacy_worker(): void
    {
        $legacyTelegram = '/usr/bin/php artisan queue:work --timeout=600 --sleep=1';
        $this->assertTrue(ArtisanProcessFinder::queueWorkHandlesAnyOf($legacyTelegram, ['default', 'mirror']));

        $isDefaultWorker = static function (string $cmd): bool {
            if (! ArtisanProcessFinder::queueWorkHandlesAnyOf($cmd, ['default', 'mirror'])) {
                return false;
            }

            if (str_contains($cmd, '--timeout='.\App\Services\Telegram\TelegramAnalystProcessManager::QUEUE_TIMEOUT_SEC)
                && ! str_contains($cmd, '--queue=')) {
                return false;
            }

            return true;
        };

        $this->assertFalse($isDefaultWorker($legacyTelegram));
        $this->assertTrue($isDefaultWorker('/usr/bin/php artisan queue:work --queue=backtest,mirror,default,telegram-analyst'));
    }

    public function test_merge_severity_picks_worst(): void
    {
        $this->assertSame('warning', PlatformProcessMonitorService::mergeSeverity('ok', 'warning'));
        $this->assertSame('error', PlatformProcessMonitorService::mergeSeverity('warning', 'error'));
        $this->assertSame('ok', PlatformProcessMonitorService::mergeSeverity('ok', 'stopped'));
        $this->assertSame('error', PlatformProcessMonitorService::mergeSeverity('ok', 'error'));
    }

    public function test_snapshot_returns_expected_top_level_keys(): void
    {
        $snapshot = app(PlatformProcessMonitorService::class)->snapshot();

        $this->assertTrue($snapshot['ok']);
        $this->assertArrayHasKey('processes', $snapshot);
        $this->assertArrayHasKey('queues', $snapshot);
        $this->assertArrayHasKey('ingest', $snapshot);
        $this->assertNotEmpty($snapshot['processes']);

        $keys = array_column($snapshot['processes'], 'key');
        $this->assertContains('bots_scan', $keys);
        $this->assertContains('pocket_options_proxy', $keys);
    }
}
