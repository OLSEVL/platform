<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\SweepRaidLiquidityStrategy;
use App\Services\Telegram\SweepRaidLiquidityChartBuilder;
use App\Services\Telegram\TelegramChartImageRenderer;
use Tests\TestCase;

class SweepRaidLiquidityChartBuilderTest extends TestCase
{
    public function test_builder_includes_indicator_markers_and_entry_reason(): void
    {
        $candles = $this->highSweepCandles();
        $decision = (new SweepRaidLiquidityStrategy)->analyze($candles, null, [
            'config' => [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => true,
                'srl_enter_pullback' => false,
                'expiry_sec' => 60,
            ],
        ]);
        $this->assertIsArray($decision);

        $chart = (new SweepRaidLiquidityChartBuilder)
            ->chartLevelsFromDecision($decision, $candles);

        $this->assertNotNull($chart['entry']);
        $this->assertSame('PUT', $chart['entry']['side']);
        $this->assertSame(4, $chart['entry']['time']);
        $this->assertStringContainsString('свип', $chart['subtitle']);
        $this->assertStringContainsString('Вход PUT', $chart['subtitle']);
        $this->assertSame('Вход PUT', $chart['entry']['label']);
        $this->assertStringContainsString('тенью', $chart['subtitle']);
        $this->assertCount(1, $chart['segments']);
        $this->assertCount(2, $chart['markers']);

        $texts = array_map(static fn (array $m): string => (string) $m['text'], $chart['markers']);
        $this->assertTrue(collect($texts)->contains(fn (string $text): bool => str_contains($text, 'SWEEP H')));
        $this->assertTrue(collect($texts)->contains(fn (string $text): bool => $text === 'FH'));
    }

    public function test_pullback_subtitle_mentions_first_red_after_low_takedown(): void
    {
        $candles = [
            $this->candle(1, 10, 10.2, 9.8, 10.1),
            $this->candle(2, 10.1, 10.2, 9.0, 9.5),
            $this->candle(3, 9.5, 10.0, 9.3, 9.8),
            $this->candle(4, 9.8, 9.9, 8.8, 9.3),
            $this->candle(5, 9.3, 9.6, 9.1, 9.4),
            $this->candle(6, 9.5, 9.6, 9.2, 9.3),
        ];
        $decision = (new SweepRaidLiquidityStrategy)->analyze($candles, null, [
            'config' => [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => false,
                'srl_enter_pullback' => true,
                'expiry_sec' => 60,
            ],
        ]);
        $this->assertIsArray($decision);

        $chart = (new SweepRaidLiquidityChartBuilder)
            ->chartLevelsFromDecision($decision, $candles);

        $this->assertSame('CALL', $chart['entry']['side']);
        $this->assertStringContainsString('откатная', $chart['subtitle']);
        $this->assertStringContainsString('свип', $chart['subtitle']);
        $this->assertStringContainsString('лоу', $chart['subtitle']);
        $this->assertCount(3, $chart['markers']);
        $pb = array_filter($chart['markers'], static fn (array $m): bool => str_contains((string) $m['text'], 'PB'));
        $this->assertNotEmpty($pb);
    }

    public function test_renderer_draws_png_with_markers_when_gd_available(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not installed');
        }

        $candles = $this->highSweepCandles();
        $decision = (new SweepRaidLiquidityStrategy)->analyze($candles, null, [
            'config' => [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => true,
                'srl_enter_pullback' => false,
                'expiry_sec' => 60,
            ],
        ]);
        $chart = (new SweepRaidLiquidityChartBuilder)
            ->chartLevelsFromDecision($decision, $candles);

        $path = (new TelegramChartImageRenderer)->render('AEDCNY_otc', $candles, $chart, [
            'timeframe_label' => 'M1',
        ]);
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $info = getimagesize($path);
        $this->assertIsArray($info);
        $this->assertSame('image/png', $info['mime']);
        @unlink($path);
    }

    public function test_builder_ignores_older_setups_on_the_same_series(): void
    {
        $candles = [];
        // Старый свип хая: не должен попасть на алерт текущего входа.
        $candles[] = $this->candle(1, 10.0, 10.2, 9.8, 10.1);
        $candles[] = $this->candle(2, 10.1, 12.0, 10.0, 11.5);
        $candles[] = $this->candle(3, 11.5, 11.6, 10.2, 10.4);
        $candles[] = $this->candle(4, 10.4, 12.3, 10.3, 11.2);
        for ($i = 5; $i <= 20; $i++) {
            $p = 10.2 + ($i * 0.01);
            $candles[] = $this->candle($i, $p, $p + 0.05, $p - 0.04, $p + 0.02);
        }
        $candles[] = $this->candle(21, 10.4, 10.5, 10.3, 10.42);
        $candles[] = $this->candle(22, 10.42, 11.0, 10.4, 10.8);
        $candles[] = $this->candle(23, 10.8, 10.9, 10.5, 10.6);
        $candles[] = $this->candle(24, 10.6, 11.15, 10.55, 10.75);

        $decision = (new SweepRaidLiquidityStrategy)->analyze($candles, null, [
            'config' => [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => true,
                'srl_enter_pullback' => false,
                'expiry_sec' => 60,
            ],
        ]);
        $this->assertIsArray($decision);
        $chart = (new SweepRaidLiquidityChartBuilder)->chartLevelsFromDecision($decision, $candles);

        $this->assertCount(2, $chart['markers']);
        $this->assertSame(22, $chart['markers'][0]['time']);
        $this->assertSame(24, $chart['markers'][1]['time']);
        $this->assertSame(24, $chart['entry']['time']);
    }

    public function test_renderer_skips_markers_before_visible_window(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not installed');
        }

        $candles = [];
        for ($i = 1; $i <= 30; $i++) {
            $p = 10.0 + $i * 0.01;
            $candles[] = $this->candle($i, $p, $p + 0.02, $p - 0.01, $p + 0.005);
        }
        $path = (new TelegramChartImageRenderer)->render('EURUSD_otc', $candles, [
            'levels' => [],
            'zones' => [],
            'entry' => ['price' => 10.3, 'side' => 'PUT', 'time' => 30, 'label' => 'Вход PUT'],
            'segments' => [],
            'markers' => [
                ['time' => 1, 'price' => 10.01, 'text' => 'OLD LEFT', 'color' => '#ef4444', 'position' => 'above'],
                ['time' => 30, 'price' => 10.3, 'text' => 'NOW', 'color' => '#22c55e', 'position' => 'above'],
            ],
            'subtitle' => 'only current',
            'legend' => '',
        ], [
            'max_bars' => 10,
            'timeframe_label' => 'M1',
        ]);
        $this->assertNotNull($path);
        @unlink($path);
    }

    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function highSweepCandles(): array
    {
        return [
            $this->candle(1, 10.0, 10.2, 9.8, 10.1),
            $this->candle(2, 10.1, 11.0, 10.0, 10.8),
            $this->candle(3, 10.8, 10.9, 10.1, 10.3),
            $this->candle(4, 10.3, 11.2, 10.2, 10.7),
        ];
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float,volume:float} */
    private function candle(int $time, float $open, float $high, float $low, float $close): array
    {
        return compact('time', 'open', 'high', 'low', 'close') + ['volume' => 1.0];
    }
}
