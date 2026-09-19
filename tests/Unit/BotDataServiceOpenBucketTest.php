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

    public function test_it_drops_forming_m5_bucket(): void
    {
        $m5Open = 1_704_067_200;
        $now = $m5Open + 230;
        $rows = [
            ['time' => $m5Open - 300, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 0],
            ['time' => $m5Open, 'open' => 2.0, 'high' => 2.2, 'low' => 1.8, 'close' => 2.1, 'volume' => 0],
        ];

        $out = BotDataService::withoutOpenLastBucket($rows, 5, $now);

        $this->assertCount(1, $out);
        $this->assertSame($m5Open - 300, $out[0]['time']);
    }

    public function test_slice_closed_mtf_excludes_complete_m5_mid_period(): void
    {
        $m5Noon = 1_704_067_200;
        $m5Prev = $m5Noon - 300;
        $series = [
            '5' => [
                $this->bar($m5Prev, 1.10),
                $this->bar($m5Noon, 1.20),
            ],
        ];
        $take = ['5' => 20];
        $cursor = ['5' => -1];
        $m1At1202 = $m5Noon + 120;

        $out = BotDataService::sliceClosedMtfUntil($series, $take, $cursor, $m1At1202, 60);

        $this->assertCount(1, $out['5']);
        $this->assertSame($m5Prev, $out['5'][0]['time']);
        $this->assertSame(0, $cursor['5']);
    }

    public function test_slice_closed_mtf_includes_m5_on_its_closing_minute(): void
    {
        $m5Noon = 1_704_067_200;
        $m5Prev = $m5Noon - 300;
        $series = [
            '5' => [
                $this->bar($m5Prev, 1.10),
                $this->bar($m5Noon, 1.20),
            ],
        ];
        $take = ['5' => 20];
        $cursor = ['5' => -1];
        $m1LastOfPrevM5 = $m5Noon - 60;

        $out = BotDataService::sliceClosedMtfUntil($series, $take, $cursor, $m1LastOfPrevM5, 60);

        $this->assertCount(1, $out['5']);
        $this->assertSame($m5Prev, $out['5'][0]['time']);
    }

    public function test_slice_closed_mtf_includes_m5_on_last_minute_of_that_m5(): void
    {
        $m5Noon = 1_704_067_200;
        $m5Prev = $m5Noon - 300;
        $series = [
            '5' => [
                $this->bar($m5Prev, 1.10),
                $this->bar($m5Noon, 1.20),
            ],
        ];
        $take = ['5' => 20];
        $cursor = ['5' => -1];
        $m1At1204 = $m5Noon + 240;

        $out = BotDataService::sliceClosedMtfUntil($series, $take, $cursor, $m1At1204, 60);

        $this->assertCount(2, $out['5']);
        $this->assertSame($m5Noon, $out['5'][1]['time']);
    }

    public function test_slice_closed_mtf_excludes_forming_h1_at_half_hour(): void
    {
        $h1Noon = 1_704_067_200;
        $h1Prev = $h1Noon - 3600;
        $series = [
            '60' => [
                $this->bar($h1Prev, 1.10),
                $this->bar($h1Noon, 1.20),
            ],
        ];
        $take = ['60' => 12];
        $cursor = ['60' => -1];
        $m1At1230 = $h1Noon + 1800;

        $out = BotDataService::sliceClosedMtfUntil($series, $take, $cursor, $m1At1230, 60);

        $this->assertCount(1, $out['60']);
        $this->assertSame($h1Prev, $out['60'][0]['time']);
    }

    /**
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float}
     */
    private function bar(int $time, float $close): array
    {
        return [
            'time' => $time,
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => 1.0,
        ];
    }
}
