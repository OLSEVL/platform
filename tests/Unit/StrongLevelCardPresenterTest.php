<?php

namespace Tests\Unit;

use App\Services\Bots\Levels\StrongLevelCardPresenter;
use PHPUnit\Framework\TestCase;

class StrongLevelCardPresenterTest extends TestCase
{
    private StrongLevelCardPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new StrongLevelCardPresenter(
            $this->createMock(\App\Services\CandleService::class),
            $this->createMock(\App\Services\Telegram\StrongLevelTelegramChartBuilder::class),
            $this->createMock(\App\Services\Telegram\TelegramChartImageRenderer::class),
        );
    }

    public function test_strategy_title_bounce_and_breakout(): void
    {
        $this->assertSame('Отбой от уровня', $this->presenter->strategyTitle(['entry_regime' => 'bounce']));
        $this->assertSame('Пробой уровня', $this->presenter->strategyTitle(['entry_regime' => 'breakout']));
    }

    public function test_strategy_narrative_bounce_put_mentions_resistance_and_down(): void
    {
        $text = $this->presenter->strategyNarrative([
            'entry_regime' => 'bounce',
            'level_price' => 0.82811,
            'level_strength' => 62,
            'level_kind' => 'resistance',
            'level_touches' => 4,
            'level_sources' => ['h1_swing', 'pa_dgt_sr'],
            'h1_bias' => 'bear',
        ], 'PUT');

        $this->assertStringContainsString('сопротивления 0.82811', $text);
        $this->assertStringContainsString('4 касания', $text);
        $this->assertStringContainsString('отскока вниз', $text);
        $this->assertStringContainsString('H1', $text);
    }

    public function test_strategy_narrative_breakout_call(): void
    {
        $text = $this->presenter->strategyNarrative([
            'entry_regime' => 'breakout',
            'level_price' => 1.08450,
            'level_strength' => 55,
            'level_kind' => 'resistance',
            'level_touches' => 2,
            'level_sources' => ['m15_swing'],
            'setup_type' => 'breakout_retest',
            'h1_bias' => 'bull',
        ], 'CALL');

        $this->assertStringContainsString('пробила', $text);
        $this->assertStringContainsString('вверх', $text);
        $this->assertStringContainsString('ретестом', $text);
    }

    public function test_technical_details_joins_level_and_reasons(): void
    {
        $line = $this->presenter->technicalDetails(
            [
                'level_price' => 0.82811,
                'level_kind' => 'resistance',
                'level_touches' => 4,
                'entry_regime' => 'bounce',
                'level_sources' => ['rs_sr'],
                'setup_type' => 'pin-bear',
            ],
            ['отбой от сопротивления', 'pin-bear']
        );

        $this->assertStringContainsString('сопротивления 0.82811', $line);
        $this->assertStringContainsString('режим: отскок', $line);
        $this->assertStringContainsString('pin-bear', $line);
    }
}
