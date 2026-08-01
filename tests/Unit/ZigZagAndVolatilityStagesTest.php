<?php

namespace Tests\Unit;

use App\Services\Indicators\ZigZagIndicatorService;
use App\Services\Indicators\VolatilityAverageTwiceIndicatorService;
use App\Services\Indicators\VolatilityStagesAWIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-тесты портов ZigZag / VolatilityAverageTwice / VolatilityStagesAW.
 */
class ZigZagAndVolatilityStagesTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function syntheticRows(int $count = 200): array
    {
        $rows = [];
        $base = 100.0;
        for ($i = 0; $i < $count; $i++) {
            // Волны: синус + тренд → явные локальные экстремумы для ZigZag.
            $wave = sin($i / 8.0) * 3.0;
            $close = $base + $i * 0.05 + $wave;
            $open = $close - 0.1;
            $span = 0.4 + abs($wave) * 0.15;
            $high = max($open, $close) + $span;
            $low = min($open, $close) - $span * 0.7;
            $rows[] = [
                'time' => 1_700_000_000 + $i * 60,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => 0.0,
            ];
        }

        return $rows;
    }

    public function test_zigzag_produces_line_and_swings(): void
    {
        $rows = $this->syntheticRows(200);
        $svc = new ZigZagIndicatorService;
        $raw = $svc->computeSeries($rows, ['length' => 5]);

        $this->assertCount(200, $raw['line']);
        $this->assertCount(200, $raw['highs']);
        $this->assertCount(200, $raw['lows']);

        $swingCount = 0;
        foreach ($raw['zigzag'] as $v) {
            if (abs($v) > 1e-9) {
                $swingCount++;
            }
        }
        $this->assertGreaterThan(2, $swingCount, 'ожидали несколько свингов ZigZag');

        $linePts = 0;
        foreach ($raw['line'] as $v) {
            if (abs($v) > 1e-9) {
                $linePts++;
            }
        }
        $this->assertGreaterThan($swingCount, $linePts, 'линия должна заполнять промежутки между свингами');

        $out = $svc->compute($rows, ['length' => 5]);
        $this->assertArrayHasKey('line', $out['series']);
        $this->assertNotEmpty($out['series']['line']);
        $this->assertSame('#6495ed', $out['style']['color_line']);
    }

    public function test_volatility_average_twice_candle_mode(): void
    {
        $rows = $this->syntheticRows(80);
        $svc = new VolatilityAverageTwiceIndicatorService;
        $raw = $svc->computeSeries($rows, [
            'volatility_base_type' => 'Candle',
            'volatility_variable_type' => 'Percent',
            'sma_len_fast' => 3,
            'sma_len_slow' => 7,
        ]);

        $this->assertCount(80, $raw['volatility']);
        $this->assertGreaterThan(0.0, $raw['volatility'][50]);
        $this->assertGreaterThan(0.0, $raw['sma_fast'][50]);
        $this->assertGreaterThan(0.0, $raw['sma_slow'][50]);

        $out = $svc->compute($rows);
        $this->assertArrayHasKey('volatility', $out['series']);
        $this->assertArrayHasKey('sma_fast', $out['series']);
        $this->assertArrayHasKey('sma_slow', $out['series']);
    }

    public function test_volatility_stages_aw_regime3_stages_in_1_3(): void
    {
        $rows = $this->syntheticRows(120);
        $svc = new VolatilityStagesAWIndicatorService;
        $raw = $svc->computeSeries($rows, [
            'stages_regime' => '3',
            'volatility_base_type' => 'Candle',
            'sma_len_fast' => 3,
            'sma_len_slow' => 7,
            'channel_deviation' => 0.25,
        ]);

        $seen = [];
        foreach ($raw['stages'] as $s) {
            if ($s == 0.0) {
                continue;
            }
            $this->assertContains((int) $s, [1, 2, 3], "неожиданная стадия $s");
            $seen[(int) $s] = true;
        }
        $this->assertNotEmpty($seen, 'ожидали ненулевые стадии');

        $out = $svc->compute($rows);
        $this->assertArrayHasKey('stages', $out['series']);
        $this->assertArrayNotHasKey('volatility', $out['series']); // show_volatility=false по умолчанию
        $this->assertSame('#008000', $out['style']['color_stages']);
    }
}
