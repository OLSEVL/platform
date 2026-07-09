<?php

namespace Tests\Unit;

use App\Services\Telegram\LevelBounceAlertPresenter;
use App\Services\Telegram\TelegramAlertService;
use Tests\TestCase;

class LevelBounceAlertPresenterTest extends TestCase
{
    public function test_crc_alert_branding_and_confirm_block(): void
    {
        $decision = $this->crcDecisionSample();
        $service = app(TelegramAlertService::class);

        $brief = $service->formatStrongLevelAlertText(
            'EURUSD_otc',
            $decision,
            85.0,
            300,
            1.0846,
            'brief',
            'candle_reversal_confirm',
        );

        $this->assertStringContainsString('Candle Reversal + Confirm', $brief);
        $this->assertStringNotContainsString('Strong Level', $brief);
        $this->assertStringContainsString('Подтверждение:', $brief);
        $this->assertStringContainsString('M5: rejection', $brief);
        $this->assertStringContainsString('M1: 2 свечи', $brief);
        $this->assertStringContainsString('поддержка', $brief);
        $this->assertStringContainsString('5 минут', $brief);
        $this->assertStringContainsString('до уровня:', $brief);
    }

    public function test_crc_detailed_uses_human_sources_and_narrative(): void
    {
        $decision = $this->crcDecisionSample();
        $service = app(TelegramAlertService::class);

        $detailed = $service->formatStrongLevelAlertText(
            'EURUSD_otc',
            $decision,
            85.0,
            300,
            1.0846,
            'detailed',
            'candle_reversal_confirm',
        );

        $this->assertStringContainsString('Источники: DGT+4H', $detailed);
        $this->assertStringNotContainsString('pa_dgt_sr', $detailed);
        $this->assertStringContainsString('2 свечи подтверждают разворот', $detailed);
        $this->assertStringContainsString('продолжение движения', $detailed);
    }

    public function test_crc_card_format_highlights_reversal(): void
    {
        $decision = $this->crcDecisionSample();
        $service = app(TelegramAlertService::class);

        $card = $service->formatStrongLevelAlertText(
            'EURUSD_otc',
            $decision,
            85.0,
            300,
            1.0846,
            'card',
            'candle_reversal_confirm',
        );

        $this->assertStringContainsString('Отскок от уровня + разворот', $card);
        $this->assertStringContainsString('5 минут', $card);
        $this->assertStringContainsString('Подтверждение', $card);
        $this->assertStringContainsString('M5: rejection', $card);
    }

    public function test_strong_level_branding_unchanged(): void
    {
        $decision = [
            'side' => 'CALL',
            'confidence' => 0.72,
            'reasons_short' => ['Отскок от support'],
            'features' => [
                'level_price' => 1.0845,
                'level_strength' => 62.0,
                'level_kind' => 'support',
                'entry_regime' => 'bounce',
                'level_sources' => ['pa_dgt_sr'],
            ],
        ];

        $brief = app(TelegramAlertService::class)->formatStrongLevelAlertText(
            'EURUSD_otc',
            $decision,
            85.0,
            180,
            1.0846,
            'brief',
            'strong_level',
        );

        $this->assertStringContainsString('Strong Level', $brief);
        $this->assertStringNotContainsString('Candle Reversal', $brief);
        $this->assertStringNotContainsString('Подтверждение:', $brief);
        $this->assertStringContainsString('3 минуты', $brief);
    }

    public function test_presenter_format_confirm_lines(): void
    {
        $presenter = app(LevelBounceAlertPresenter::class);
        $lines = $presenter->formatConfirmLines([
            'm5_rejection_enabled' => true,
            'm5_reversal_wick' => 0.42,
            'm1_reversal_enabled' => true,
            'm1_reversal_confirm_bars' => 2,
            'signal_side' => 'CALL',
        ], []);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('42%', $lines[0]);
        $this->assertStringContainsString('2 свечи', $lines[1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function crcDecisionSample(): array
    {
        return [
            'side' => 'CALL',
            'confidence' => 0.74,
            'decision_version' => 'candle_reversal_confirm_v3_level_bounce',
            'reasons_short' => [
                'Сильный уровень поддержки 1.08450 (сила 62, DGT+4H).',
                'M5 rejection (тень 42%).',
            ],
            'features' => [
                'level_price' => 1.0845,
                'level_strength' => 62.0,
                'level_kind' => 'support',
                'entry_regime' => 'bounce',
                'setup_type' => 'level_bounce_reversal',
                'h1_bias' => 'up',
                'level_sources' => ['pa_dgt_sr', 'mxwll_4h'],
                'distance_to_level' => 0.00012,
                'atr_m1' => 0.00006,
                'm5_rejection_enabled' => true,
                'm5_reversal_wick' => 0.42,
                'm1_reversal_enabled' => true,
                'm1_reversal_confirm_bars' => 2,
                'signal_side' => 'CALL',
            ],
        ];
    }
}
