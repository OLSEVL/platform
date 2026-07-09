<?php

namespace Tests\Unit;

use App\Support\OpenRouterChartLevelsParser;
use App\Support\OpenRouterLlmConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenRouterLlmConfigTest extends TestCase
{
    #[Test]
    public function m1_uses_count_and_drops_legacy_candles_take_on_save(): void
    {
        $config = OpenRouterLlmConfig::withoutLegacyCandlesTake([
            'count' => 80,
            'candles_take' => 40,
        ]);

        $this->assertSame(80, OpenRouterLlmConfig::m1BarCount($config));
        $this->assertArrayNotHasKey('candles_take', $config);
    }

    #[Test]
    public function s5_bar_count_reads_s5_take(): void
    {
        $this->assertSame(240, OpenRouterLlmConfig::s5BarCount([]));
        $this->assertSame(100, OpenRouterLlmConfig::s5BarCount(['s5_take' => 100]));
    }

    #[Test]
    public function mtf_map_uses_same_limits_for_each_timeframe(): void
    {
        $map = OpenRouterLlmConfig::mtfBarMap([
            'count' => 100,
            'm5_take' => 50,
            'm15_take' => 30,
            'h1_take' => 12,
        ], true, 1);

        $this->assertSame([
            5 => 50,
            15 => 30,
            60 => 12,
            1 => 100,
        ], $map);
    }

    #[Test]
    public function analyst_smc_flow_enabled_reads_config_flag(): void
    {
        $this->assertFalse(OpenRouterLlmConfig::analystSmcFlowEnabled([]));
        $this->assertTrue(OpenRouterLlmConfig::analystSmcFlowEnabled(['analyst_smc_flow_enabled' => true]));
    }

    #[Test]
    public function telegram_skip_user_question_reads_config_flag(): void
    {
        $this->assertFalse(OpenRouterLlmConfig::telegramSkipUserQuestion([]));
        $this->assertTrue(OpenRouterLlmConfig::telegramSkipUserQuestion(['telegram_skip_user_question' => true]));
    }

    #[Test]
    public function default_trading_system_prompt_requires_success_probability_percent(): void
    {
        $prompt = OpenRouterLlmConfig::defaultTradingSystemPrompt();

        $this->assertStringContainsString('Вероятность успеха: NN%', $prompt);
        $this->assertStringContainsString('success_probability_percent', $prompt);
    }

    #[Test]
    public function resolve_analyst_system_prompt_uses_short_mode_from_config(): void
    {
        $full = OpenRouterChartLevelsParser::defaultAnalystSystemPromptClassic();
        $short = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortClassic();

        $this->assertSame($full, OpenRouterLlmConfig::resolveAnalystSystemPrompt([
            'analyst_prompt_mode' => 'full',
            'system_prompt' => $full,
        ]));
        $this->assertSame($short, OpenRouterLlmConfig::resolveAnalystSystemPrompt([
            'analyst_prompt_mode' => 'short',
            'system_prompt_short' => $short,
        ]));
    }

    #[Test]
    public function analyst_prompt_mode_defaults_to_full(): void
    {
        $this->assertSame('full', OpenRouterLlmConfig::analystPromptMode([]));
        $this->assertFalse(OpenRouterLlmConfig::isAnalystShortPromptMode([]));
    }
}
