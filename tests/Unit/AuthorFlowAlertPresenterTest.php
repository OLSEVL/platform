<?php

namespace Tests\Unit;

use App\Services\CandleService;
use App\Services\Telegram\AuthorFlowAlertPresenter;
use App\Support\BotInstrumentMarketScope;
use PHPUnit\Framework\TestCase;

class AuthorFlowAlertPresenterTest extends TestCase
{
    public function test_overlay_uses_russian_labels_for_all_eight_blocks(): void
    {
        $p = new AuthorFlowAlertPresenter;
        $decision = [
            'side' => 'CALL',
            'confidence' => 0.73,
            'features' => [
                'setup' => 'engulf_D',
                'reason_ru' => 'Классическое 3-шаговое поглощение',
                'engulf_kind' => 'classic',
                'structure_bias' => 'up',
                'structure_aligned' => true,
                'open_liquidity' => true,
                'priority' => 20,
                'score' => 0.73,
                'level_price' => 1.2345,
                'level_type' => 'FL',
                'step1_time' => 100,
                'step2_time' => 115,
                'step3_time' => 130,
                'event_time' => 130,
                'entry_price' => 1.24,
                'suppressed' => [
                    ['setup' => 'sweep', 'side' => 'PUT', 'priority' => 40, 'score' => 0.5],
                ],
            ],
        ];
        $config = [
            'timeframe' => CandleService::TF_S15,
            'market_scope' => BotInstrumentMarketScope::OTC,
        ];

        $lines = $p->overlayLines('EURUSD_otc', $decision, 15, 90.0, $config);
        $joined = implode("\n", $lines);

        $this->assertStringContainsString('ВХОД вверх', $joined);
        $this->assertStringContainsString('Поглощение', $joined);
        $this->assertStringNotContainsString('engulf_D', $joined);
        $this->assertStringContainsString('ТФ S15', $joined);
        $this->assertStringContainsString('только OTC', $joined);
        $this->assertStringContainsString('Структура: рост', $joined);
        $this->assertStringContainsString('по структуре', $joined);
        $this->assertStringContainsString('ликвидность: открыта', $joined);
        $this->assertStringContainsString('Оценка 0.73', $joined);
        $this->assertStringContainsString('приоритет 20', $joined);
        $this->assertStringContainsString('классика', $joined);
        $this->assertStringContainsString('поддержка (FL)', $joined);
        $this->assertStringContainsString('Мимо: Свип', $joined);
    }

    public function test_engulf_markers_use_russian_step_labels(): void
    {
        $p = new AuthorFlowAlertPresenter;
        $candles = [
            ['time' => 100, 'open' => 1.0, 'high' => 1.1, 'low' => 0.9, 'close' => 1.05, 'volume' => 1],
            ['time' => 115, 'open' => 1.05, 'high' => 1.12, 'low' => 1.0, 'close' => 1.02, 'volume' => 1],
            ['time' => 130, 'open' => 1.02, 'high' => 1.2, 'low' => 1.01, 'close' => 1.18, 'volume' => 1],
        ];
        $markers = $p->chartMarkers([
            'side' => 'CALL',
            'features' => [
                'setup' => 'engulf_D',
                'engulf_kind' => 'same_color',
                'step1_time' => 100,
                'step2_time' => 115,
                'step3_time' => 130,
                'entry_price' => 1.18,
                'reaction_gap_bars' => 1,
            ],
        ], $candles);

        $texts = array_column($markers, 'text');
        $this->assertContains('① Поглощение', $texts);
        $this->assertContains('② Реакция', $texts);
        $this->assertContains('③ Вход', $texts);
        $this->assertStringContainsString('одного цвета', implode(' ', $markers[0]['lines'] ?? []));
    }

    public function test_setup_ru_mapping(): void
    {
        $p = new AuthorFlowAlertPresenter;
        $this->assertSame('Пробой+закреп', $p->setupRu('breakout_E'));
        $this->assertSame('Рейд→откат', $p->setupRu('raid_pb'));
        $this->assertSame('Свип', $p->setupRu('sweep'));
    }

    public function test_price_levels_mark_open_and_taken_liquidity(): void
    {
        $p = new AuthorFlowAlertPresenter;
        $levels = $p->priceLevels([
            'side' => 'CALL',
            'features' => [
                'entry_price' => 1.10,
                'liq_high' => 1.20,
                'liq_low' => 1.05,
                'open_liquidity_put' => false,
                'open_liquidity_call' => true,
            ],
        ], ['telegram_show_nearest_level' => true]);

        $labels = array_column($levels, 'label');
        $joined = implode('|', $labels);
        $this->assertStringContainsString('ликв. сверху (снята)', $joined);
        $this->assertStringContainsString('ликв. снизу (открыта)', $joined);
        $this->assertStringContainsString('вход', $joined);
    }
}
