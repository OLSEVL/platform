<?php

namespace Tests\Unit;

use App\Support\FeedTimeNormalizer;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FeedTimeNormalizerTest extends TestCase
{
    public function test_applies_correction_when_effective_matches_raw_feed(): void
    {
        Config::set('services.feed.feed_time_correction_sec', -7200);
        $raw = 1_780_352_859;
        $partial = [
            'time' => $raw,
            'raw_feed_time' => $raw,
            'effective_feed_time' => $raw - 4,
        ];

        $out = FeedTimeNormalizer::normalizePartial($partial);

        $this->assertSame(1_780_345_655, $out['effective_feed_time']);
        $this->assertSame(1_780_345_655, $out['time']);
    }

    public function test_skips_double_correction_when_ingest_already_normalized(): void
    {
        Config::set('services.feed.feed_time_correction_sec', -7200);
        $raw = 1_780_352_859;
        $already = 1_780_345_655;
        $partial = [
            'time' => $already,
            'raw_feed_time' => $raw,
            'effective_feed_time' => $already,
        ];

        $out = FeedTimeNormalizer::normalizePartial($partial);

        $this->assertSame($already, $out['effective_feed_time']);
    }

    public function test_detects_skew_vs_server(): void
    {
        Config::set('services.feed.max_skew_sec', 300);
        $server = 1_780_345_660;

        $this->assertTrue(FeedTimeNormalizer::isSkewedVsServer(1_780_352_820, $server));
        $this->assertFalse(FeedTimeNormalizer::isSkewedVsServer(1_780_345_680, $server));
    }

    public function test_normalize_stored_unix_time_shifts_future_duplicate_stream(): void
    {
        Config::set('services.feed.feed_time_correction_sec', -7200);
        $server = 1_780_349_300;
        $stored = 1_780_356_500;

        $this->assertSame(1_780_349_300, FeedTimeNormalizer::normalizeStoredUnixTime($stored, $server));
    }
}
