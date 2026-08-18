<?php

namespace Tests\Unit;

use App\Support\FeedTimeNormalizer;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class IngestCurrentLightTest extends TestCase
{
    public function test_normalize_partial_matches_feed_time_normalizer_when_correction_needed(): void
    {
        Config::set('services.feed.feed_time_correction_sec', -7200);
        require_once base_path('bootstrap/ingest-current-light.php');

        $partial = [
            'time' => 1_780_352_859,
            'raw_feed_time' => 1_780_352_859,
            'effective_feed_time' => 1_780_352_855,
        ];

        $laravel = FeedTimeNormalizer::normalizePartial($partial);
        $light = ingest_current_light_normalize_partial($partial, -7200);

        $this->assertSame($laravel['effective_feed_time'], $light['effective_feed_time']);
        $this->assertSame($laravel['time'], $light['time']);
    }

    public function test_normalize_partial_skips_double_correction_like_laravel(): void
    {
        Config::set('services.feed.feed_time_correction_sec', -7200);
        require_once base_path('bootstrap/ingest-current-light.php');

        $already = 1_780_345_655;
        $partial = [
            'time' => $already,
            'raw_feed_time' => 1_780_352_859,
            'effective_feed_time' => $already,
        ];

        $laravel = FeedTimeNormalizer::normalizePartial($partial);
        $light = ingest_current_light_normalize_partial($partial, -7200);

        $this->assertSame($laravel['effective_feed_time'], $light['effective_feed_time']);
        $this->assertSame($already, $light['time']);
    }
}
