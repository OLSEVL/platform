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
