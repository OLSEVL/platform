<?php

namespace Tests\Unit;

use App\Services\Bots\Levels\StrongLevelCardPresenter;
use App\Services\Telegram\LevelBounceAlertPresenter;
use PHPUnit\Framework\TestCase;

class StrongLevelCardPresenterTest extends TestCase
{
    private StrongLevelCardPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        // Реальный presenter текстов — проверяем сборку заголовков/нарративов для карточек.
        $this->presenter = new StrongLevelCardPresenter(
            $this->createMock(\App\Services\CandleService::class),
            $this->createMock(\App\Services\Telegram\StrongLevelTelegramChartBuilder::class),
            $this->createMock(\App\Services\Telegram\TelegramChartImageRenderer::class),
            new LevelBounceAlertPresenter,
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

        $this->assertStringContainsString('сопротивление 0.82811', $line);
        $this->assertStringContainsString('режим: отскок', $line);
        $this->assertStringContainsString('pin-bear', $line);
    }

    public function test_rsi_contrtrend_title_and_narrative(): void
    {
        $features = [
            'setup' => 'rsi_oversold_in_uptrend',
            'sma' => 1.10500,
            'rsi' => 28.5,
            'sma_length' => 50,
            'rsi_length' => 20,
            'rsi_up' => 65,
            'rsi_down' => 35,
            'level_price' => 1.10500,
            'level_kind' => 'sma',
        ];
        $decision = ['decision_version' => 'rsi_contrtrend_v1'];

        $this->assertSame(
            'RSI перепроданность в аптренде',
            $this->presenter->strategyTitle($features, 'rsi_contrtrend', $decision)
        );

        $text = $this->presenter->strategyNarrative($features, 'CALL', 'rsi_contrtrend', $decision, [
            'Цена выше SMA(50), RSI(28.5) < 35 — отскок вверх в аптренде.',
        ]);
        $this->assertStringContainsString('SMA(50)', $text);
        $this->assertStringContainsString('перепроданность', $text);
        $this->assertStringContainsString('вверх', $text);

        $tech = $this->presenter->technicalDetails($features, ['reason'], 'rsi_contrtrend', $decision);
        $this->assertStringContainsString('RSI Contrtrend', $tech);
        $this->assertStringContainsString('RSI(20)=28.50', $tech);
    }

    public function test_bollinger_contrtrend_title_and_narrative(): void
    {
        $features = [
            'setup' => 'bb_touch_lower',
            'close' => 1.09000,
            'bb_up' => 1.12000,
            'bb_mid' => 1.10000,
            'bb_down' => 1.08000,
            'bb_length' => 12,
            'bb_deviation' => 2.0,
            'level_price' => 1.08000,
            'level_kind' => 'bb_lower',
        ];
        $decision = ['decision_version' => 'bollinger_contrtrend_v1'];

        $this->assertSame(
            'Отскок от нижней полосы Bollinger',
            $this->presenter->strategyTitle($features, 'bollinger_contrtrend', $decision)
        );

        $text = $this->presenter->strategyNarrative($features, 'CALL', 'bollinger_contrtrend', $decision, [
            'Close ниже нижней BB',
        ]);
        $this->assertStringContainsString('нижней полосы BB(12', $text);
        $this->assertStringContainsString('вверх', $text);

        $tech = $this->presenter->technicalDetails($features, ['reason'], 'bollinger_contrtrend', $decision);
        $this->assertStringContainsString('Bollinger Contrtrend', $tech);
        $this->assertStringContainsString('BB(12, 2.0σ)', $tech);
    }

    public function test_pinbar_trade_title_and_narrative(): void
    {
        $features = [
            'setup' => 'pinbar_bull',
            'close' => 1.10550,
            'sma' => 1.10000,
            'sma_length' => 50,
            'level_price' => 1.10550,
            'level_kind' => 'pinbar',
        ];
        $decision = ['decision_version' => 'pinbar_trade_v1'];

        $this->assertSame(
            'Бычий пинбар',
            $this->presenter->strategyTitle($features, 'pinbar_trade', $decision)
        );

        $text = $this->presenter->strategyNarrative($features, 'CALL', 'pinbar_trade', $decision, [
            'Бычий пин: O/C в верхней 1/3 HL, SMA(50)=1.10000 < close=1.10550.',
        ]);
        $this->assertStringContainsString('Бычий пин', $text);
        $this->assertStringContainsString('вверх', $text);

        $tech = $this->presenter->technicalDetails($features, ['reason'], 'pinbar_trade', $decision);
        $this->assertStringContainsString('pinbar_bull', $tech);
        $this->assertStringContainsString('режим: PinBar', $tech);
        $this->assertStringContainsString('SMA(50)', $tech);
    }
}
