<?php

namespace Tests\Unit;

use App\Support\HybridAnalystConfig;
use PHPUnit\Framework\TestCase;

class HybridAnalystConfigTest extends TestCase
{
    public function test_extract_recommendation_side_from_full_line(): void
    {
        $text = "Разбор...\nРекомендация: CALL\nВероятность успеха: 70%";
        $this->assertSame('CALL', HybridAnalystConfig::extractRecommendationSide($text));
    }

    public function test_extract_recommendation_side_none(): void
    {
        $this->assertSame('NONE', HybridAnalystConfig::extractRecommendationSide("Рекомендация: NONE (флэт)\n"));
    }

    public function test_publish_agree_source(): void
    {
        $cfg = ['hybrid_publish' => HybridAnalystConfig::PUBLISH_AGREE_SOURCE];
        $this->assertTrue(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            "Рекомендация: PUT\nВероятность успеха: 65%",
            'PUT'
        ));
        $this->assertFalse(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            "Рекомендация: CALL\nВероятность успеха: 65%",
            'PUT'
        ));
        $this->assertFalse(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            "Рекомендация: NONE\nВероятность успеха: 40%",
            'PUT'
        ));
    }

    public function test_publish_call_put_only(): void
    {
        $cfg = ['hybrid_publish' => HybridAnalystConfig::PUBLISH_CALL_PUT_ONLY];
        $this->assertTrue(HybridAnalystConfig::shouldPublishToTelegram($cfg, 'Рекомендация: CALL', 'PUT'));
        $this->assertFalse(HybridAnalystConfig::shouldPublishToTelegram($cfg, 'Рекомендация: NONE', 'CALL'));
    }

    public function test_extract_success_percent(): void
    {
        $this->assertSame(72, HybridAnalystConfig::extractSuccessPercent(
            "Рекомендация: CALL\nВероятность успеха: 72%\nПричина: ok"
        ));
        $this->assertSame(5, HybridAnalystConfig::extractSuccessPercent('Вероятность успеха: 5%'));
        $this->assertNull(HybridAnalystConfig::extractSuccessPercent('Рекомендация: CALL'));
    }

    public function test_publish_respects_min_success_percent(): void
    {
        $cfg = [
            'hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS,
            'hybrid_min_success_percent' => 65,
        ];
        $this->assertTrue(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            "Рекомендация: CALL\nВероятность успеха: 70%",
            'CALL'
        ));
        $this->assertFalse(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            "Рекомендация: CALL\nВероятность успеха: 60%",
            'CALL'
        ));
        // Нет строки вероятности при пороге > 0 — не публикуем.
        $this->assertFalse(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            'Рекомендация: CALL',
            'CALL'
        ));
        // Порог 0 — не фильтруем по вероятности.
        $cfgZero = ['hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS, 'hybrid_min_success_percent' => 0];
        $this->assertTrue(HybridAnalystConfig::shouldPublishToTelegram($cfgZero, 'Рекомендация: CALL', 'PUT'));
    }

    public function test_min_success_works_with_agree_source(): void
    {
        $cfg = [
            'hybrid_publish' => HybridAnalystConfig::PUBLISH_AGREE_SOURCE,
            'hybrid_min_success_percent' => 70,
        ];
        $this->assertTrue(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            "Рекомендация: PUT\nВероятность успеха: 75%",
            'PUT'
        ));
        // Согласие есть, но вероятность низкая.
        $this->assertFalse(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            "Рекомендация: PUT\nВероятность успеха: 55%",
            'PUT'
        ));
        // Вероятность ок, но side не совпал.
        $this->assertFalse(HybridAnalystConfig::shouldPublishToTelegram(
            $cfg,
            "Рекомендация: CALL\nВероятность успеха: 80%",
            'PUT'
        ));
    }

    public function test_should_open_paper_requires_call_put_and_paper_flag(): void
    {
        $base = [
            'hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS,
            'hybrid_min_success_percent' => 0,
            'hybrid_paper_trades_enabled' => true,
        ];
        $this->assertTrue(HybridAnalystConfig::shouldOpenPaperTrade(
            $base,
            "Рекомендация: CALL\nВероятность успеха: 70%",
            'PUT'
        ));
        $this->assertFalse(HybridAnalystConfig::shouldOpenPaperTrade(
            $base,
            "Рекомендация: NONE\nВероятность успеха: 70%",
            'CALL'
        ));
        $this->assertFalse(HybridAnalystConfig::shouldOpenPaperTrade(
            array_merge($base, ['hybrid_paper_trades_enabled' => false]),
            "Рекомендация: CALL\nВероятность успеха: 70%",
            'CALL'
        ));
    }

    public function test_resolve_expiry_sec_from_source(): void
    {
        $this->assertSame(180, HybridAnalystConfig::resolveExpirySecFromSource(['expiry_sec' => 180]));
        $this->assertSame(300, HybridAnalystConfig::resolveExpirySecFromSource([], 300));
        $this->assertSame(180, HybridAnalystConfig::resolveExpirySecFromSource([]));
    }

    public function test_build_source_signal_payload(): void
    {
        $payload = HybridAnalystConfig::buildSourceSignalPayload(
            3,
            'Strong Level',
            'strong_level',
            99,
            'EURUSD_otc',
            'call',
            0.72,
            [
                'analysis_text' => 'bounce',
                'reasons_short' => ['level'],
                'features' => ['level_price' => 1.1],
                'decision_version' => 'v2',
                'expiry_sec' => 180,
            ],
            55,
        );

        $this->assertSame(3, $payload['source_bot_id']);
        $this->assertSame(99, $payload['source_signal_id']);
        $this->assertSame(55, $payload['source_bot_trade_id']);
        $this->assertSame('CALL', $payload['side']);
        $this->assertSame(1.1, $payload['features']['level_price']);
    }
}
