<?php

namespace Tests\Unit;

use App\Services\Indicators\DemarkerIndicatorService;
use App\Services\Telegram\StrongTrendDemarkerChartBuilder;
use App\Services\Telegram\TelegramChartImageRenderer;
use Tests\TestCase;

class StrongTrendDemarkerChartBuilderTest extends TestCase
{
    public function test_builder_includes_demarker_oscillator_and_streak_zone(): void
    {
        $candles = $this->risingCandles(12);
        $builder = new StrongTrendDemarkerChartBuilder(new DemarkerIndicatorService);
        $chart = $builder->chartLevelsFromDecision([
            'side' => 'CALL',
            'trend' => 'up',
            'features' => [
                'streak_len' => 3,
                'demarker_period' => 4,
                'demarker_oversold' => 0.10,
                'demarker_overbought' => 0.90,
            ],
        ], $candles);

        $this->assertCount(1, $chart['zones']);
        $this->assertNotNull($chart['entry']);
        $this->assertSame('CALL', $chart['entry']['side']);
        $this->assertSame('DeMarker(4)', $chart['oscillator']['label']);
        $this->assertCount(12, $chart['oscillator']['values']);
        $this->assertEqualsWithDelta(0.10, $chart['oscillator']['oversold'], 1e-9);
        $this->assertEqualsWithDelta(0.90, $chart['oscillator']['overbought'], 1e-9);
    }

    public function test_zero_streak_skips_zone_but_keeps_oscillator(): void
    {
        $builder = new StrongTrendDemarkerChartBuilder(new DemarkerIndicatorService);
        $chart = $builder->chartLevelsFromDecision([
            'side' => 'PUT',
            'trend' => 'down',
            'features' => [
                'streak_len' => 0,
                'demarker_period' => 3,
                'demarker_oversold' => 0.10,
                'demarker_overbought' => 0.90,
            ],
        ], $this->risingCandles(8));

        $this->assertSame([], $chart['zones']);
        $this->assertSame([], $chart['levels']);
        $this->assertSame('DeMarker', $chart['entry']['label']);
        $this->assertSame('DeMarker(3)', $chart['oscillator']['label']);
    }

    public function test_renderer_draws_oscillator_pane_when_gd_available(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not installed');
        }

        $candles = $this->risingCandles(20);
        $builder = new StrongTrendDemarkerChartBuilder(new DemarkerIndicatorService);
        $chart = $builder->chartLevelsFromDecision([
            'side' => 'CALL',
            'trend' => 'up',
            'features' => [
                'streak_len' => 2,
                'demarker_period' => 4,
                'demarker_oversold' => 0.10,
                'demarker_overbought' => 0.90,
            ],
        ], $candles);

        $path = (new TelegramChartImageRenderer)->render('EURUSD_otc', $candles, $chart, [
            'timeframe_label' => 'S30',
        ]);
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $info = getimagesize($path);
        $this->assertIsArray($info);
        $this->assertGreaterThanOrEqual(600, $info[1]);
        @unlink($path);
    }

    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function risingCandles(int $count): array
    {
        $out = [];
        $price = 1.10;
        for ($i = 0; $i < $count; $i++) {
            $open = $price;
            $close = $price + 0.0004;
            $out[] = [
                'time' => $i * 30,
                'open' => $open,
                'high' => $close + 0.0002,
                'low' => $open - 0.0001,
                'close' => $close,
                'volume' => 1.0,
            ];
            $price = $close;
        }

        return $out;
    }
}
