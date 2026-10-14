<?php

namespace Tests\Unit;

use App\Services\Telegram\EngulfingLiquidityChartBuilder;
use App\Services\Telegram\ImpulseLvl1minTelegramChartRenderer;
use App\Services\Telegram\LevelBounceAlertPresenter;
use App\Support\EngulfingLiquidityConfig;
use Tests\TestCase;

class EngulfingTelegramChartTest extends TestCase
{
    public function test_telegram_chart_candles_clamped(): void
    {
        $this->assertSame(50, EngulfingLiquidityConfig::telegramChartCandles([]));
        $this->assertSame(20, EngulfingLiquidityConfig::telegramChartCandles(['telegram_chart_candles' => 5]));
        $this->assertSame(80, EngulfingLiquidityConfig::telegramChartCandles(['telegram_chart_candles' => 999]));
        $this->assertSame(40, EngulfingLiquidityConfig::telegramChartCandles(['telegram_chart_candles' => 40]));
    }

    public function test_telegram_nearest_level_settings(): void
    {
        $this->assertTrue(EngulfingLiquidityConfig::telegramShowNearestLevel([]));
        $this->assertFalse(EngulfingLiquidityConfig::telegramShowNearestLevel(['telegram_show_nearest_level' => false]));
        $this->assertSame(40, EngulfingLiquidityConfig::telegramLevelLookback([]));
        $this->assertSame(40, EngulfingLiquidityConfig::telegramLevelLookback(['eng_level_lookback' => 40]));
        $this->assertSame(60, EngulfingLiquidityConfig::telegramLevelLookback(['telegram_level_lookback' => 60]));
        $this->assertSame(40, EngulfingLiquidityConfig::telegramLevelLookback([
            'telegram_level_lookback' => 0,
            'eng_level_lookback' => 40,
        ]));
    }

    public function test_engulfing_caption_is_single_short_line_like_impulse(): void
    {
        $caption = app(LevelBounceAlertPresenter::class)->formatEngulfingCaption(
            'AEDCNY_otc',
            [
                'side' => 'CALL',
                'features' => ['engulf_kind' => 'classic'],
            ],
            89.0,
            new \DateTimeImmutable('2026-10-02 11:12:07', new \DateTimeZone('Europe/Kyiv')),
        );

        $this->assertStringContainsString('AEDCNY_otc', $caption);
        $this->assertStringContainsString('(89%)', $caption);
        $this->assertStringContainsString('▲', $caption);
        $this->assertStringContainsString('Поглощение классика', $caption);
        $this->assertStringNotContainsString('Engulf', $caption);
        $this->assertStringContainsString('11:12:07', $caption);
        $this->assertStringNotContainsString('Условия', $caption);
        $this->assertStringNotContainsString("\n", $caption);
    }

    public function test_overlay_lines_explain_chain_in_russian(): void
    {
        $lines = app(LevelBounceAlertPresenter::class)->engulfingOverlayLines(
            'AEDCNY_otc',
            [
                'side' => 'CALL',
                'features' => [
                    'engulf_kind' => 'classic',
                    'reaction_gap_bars' => 2,
                ],
            ],
            60,
            89.0,
        );

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('ВХОД CALL', $lines[0]);
        $this->assertStringContainsString('Поглощение', $lines[1]);
        $this->assertStringContainsString('Реакция', $lines[1]);
        $this->assertStringContainsString('вход на close', $lines[1]);
        $this->assertStringContainsString('класси', $lines[2]);
    }

    public function test_chart_builder_uses_russian_step_labels_and_entry_arrow(): void
    {
        $candles = [];
        $t = 1_700_000_000;
        for ($i = 0; $i < 10; $i++) {
            $candles[] = [
                'time' => $t + $i * 60,
                'open' => 100 + $i * 0.1,
                'high' => 100.3 + $i * 0.1,
                'low' => 99.8 + $i * 0.1,
                'close' => 100.1 + $i * 0.1,
            ];
        }

        $built = (new EngulfingLiquidityChartBuilder)->chartLevelsFromDecision([
            'side' => 'CALL',
            'features' => [
                'engulf_kind' => 'classic',
                'step1_time' => $t + 5 * 60,
                'step2_time' => $t + 7 * 60,
                'step3_time' => $t + 9 * 60,
                'event_time' => $t + 9 * 60,
                'entry_price' => 101.0,
                'reaction_gap_bars' => 2,
            ],
        ], $candles);

        $this->assertCount(3, $built['markers']);
        $this->assertStringContainsString('Поглощение', (string) $built['markers'][0]['text']);
        $this->assertStringContainsString('Реакция', (string) $built['markers'][1]['text']);
        $this->assertStringContainsString('ВХОД', (string) $built['markers'][2]['text']);
        $this->assertSame('up', $built['markers'][2]['arrow']);
        $this->assertSame('entry', $built['markers'][2]['size']);
        $this->assertNotEmpty($built['levels']);
    }

    public function test_nearest_important_level_prefers_fl_below_for_call(): void
    {
        // Синтетика: явный FL на 100.0, вход выше — CALL должен взять FL.
        $candles = [];
        $t = 1_700_000_000;
        for ($i = 0; $i < 20; $i++) {
            $base = 100.5 + ($i * 0.02);
            $candles[] = [
                'time' => $t + $i * 60,
                'open' => $base,
                'high' => $base + 0.15,
                'low' => $base - 0.15,
                'close' => $base + 0.05,
            ];
        }
        // Фрактал low на i=10: соседи выше low.
        $candles[9]['low'] = 100.40;
        $candles[10]['low'] = 100.00;
        $candles[10]['high'] = 100.20;
        $candles[11]['low'] = 100.35;

        $found = (new \App\Services\Indicators\EngulfingDetector)->nearestImportantLevel(
            $candles,
            15,
            'CALL',
            40,
            100.60,
        );

        $this->assertNotNull($found);
        $this->assertSame('FL', $found['type']);
        $this->assertEqualsWithDelta(100.0, $found['price'], 0.001);
    }

    public function test_impulse_style_renderer_draws_engulf_chart_with_overlay_and_outline(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('gd required');
        }

        $candles = [];
        $t = 1_700_000_000;
        for ($i = 0; $i < 60; $i++) {
            $o = 100 + ($i % 5) * 0.1;
            $c = $o + (($i % 2 === 0) ? 0.2 : -0.15);
            $candles[] = [
                'time' => $t + $i * 60,
                'open' => $o,
                'high' => max($o, $c) + 0.1,
                'low' => min($o, $c) - 0.1,
                'close' => $c,
            ];
        }

        $decision = [
            'side' => 'CALL',
            'features' => [
                'engulf_kind' => 'classic',
                'step1_time' => $t + 50 * 60,
                'step2_time' => $t + 55 * 60,
                'step3_time' => $t + 59 * 60,
                'event_time' => $t + 59 * 60,
                'entry_price' => (float) $candles[59]['close'],
                'reaction_gap_bars' => 2,
                'level_price' => 100.05,
                'level_type' => 'FL',
            ],
        ];
        $built = (new EngulfingLiquidityChartBuilder)->chartLevelsFromDecision(
            $decision,
            $candles,
            [],
            ['telegram_show_nearest_level' => true, 'eng_level_lookback' => 40],
        );
        $levelLabels = array_map(
            static fn (array $l): string => (string) ($l['label'] ?? ''),
            $built['levels'],
        );
        $this->assertTrue(
            (bool) array_filter($levelLabels, static fn (string $l): bool => str_contains($l, 'FL')),
            'expected FL level line on chart'
        );
        $overlay = app(LevelBounceAlertPresenter::class)->engulfingOverlayLines(
            'AEDCNY_otc',
            $decision,
            60,
            89.0,
        );

        $path = (new ImpulseLvl1minTelegramChartRenderer)->render($candles, [], [
            'max_bars' => 50,
            'signal_time' => (int) $decision['features']['event_time'],
            'outline_last_bar' => true,
            'right_pad_bars' => 3,
            'overlay_lines' => $overlay,
            'markers' => $built['markers'],
            'price_levels' => $built['levels'],
        ]);

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertGreaterThan(1000, filesize($path));
        $sample = storage_path('app/tmp/telegram-charts/engulfing-single-msg-sample.png');
        @mkdir(dirname($sample), 0775, true);
        @copy($path, $sample);
        @unlink($path);
        $this->assertFileExists($sample);
    }
}
