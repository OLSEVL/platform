<?php

namespace Tests\Unit;

use App\Services\Bots\BotDataService;
use App\Services\CandleService;
use PHPUnit\Framework\TestCase;

class BotDataServiceOpenBucketTest extends TestCase
{
    public function test_it_drops_still_open_s30_bucket(): void
    {
        $closedStart = 1_700_000_000;
        $openStart = $closedStart + 30;
        $now = $openStart + 12;
        $rows = [
            ['time' => $closedStart, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 0],
            ['time' => $openStart, 'open' => 2.0, 'high' => 2.0, 'low' => 2.0, 'close' => 2.0, 'volume' => 0],
        ];

        $out = BotDataService::withoutOpenLastBucket($rows, CandleService::TF_S30, $now);

        $this->assertCount(1, $out);
        $this->assertSame($closedStart, $out[0]['time']);
    }

    public function test_it_keeps_s30_bucket_after_period_elapsed(): void
    {
        $start = 1_700_000_000;
        $now = $start + 30;
        $rows = [
            ['time' => $start, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 0],
        ];

        $out = BotDataService::withoutOpenLastBucket($rows, CandleService::TF_S30, $now);

        $this->assertCount(1, $out);
        $this->assertSame($start, $out[0]['time']);
    }
}
