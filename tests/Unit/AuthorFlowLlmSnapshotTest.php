<?php

namespace Tests\Unit;

use App\Support\AuthorFlowLlmSnapshot;
use App\Support\ChartAnalystBotTypes;
use App\Support\OpenRouterChartLevelsParser;
use App\Support\OpenRouterLlmConfig;
use Tests\TestCase;

class AuthorFlowLlmSnapshotTest extends TestCase
{
    public function test_build_marks_engulf_d_candidate_on_last_s15_bar(): void
    {
        // Та же CALL-цепочка, что в EngulfDScenario / EngulfingLlmSnapshotTest.
        $candles = [
            $this->bar(1, 10.0, 10.1, 9.9, 10.0),
            $this->bar(2, 10.0, 10.05, 9.85, 9.90),
            $this->bar(3, 9.90, 10.20, 9.80, 10.15),
            $this->bar(4, 10.15, 10.18, 10.00, 10.02),
            $this->bar(5, 10.02, 10.25, 9.98, 10.22),
        ];

        $snap = AuthorFlowLlmSnapshot::build($candles, [
            'expiry_sec' => 15,
            'af_enable_engulf_d' => true,
            'af_enable_breakout_e' => false,
            'af_enable_raid_pb' => false,
            'af_enable_sweep' => false,
            'af_eng_allow_classic' => true,
            'af_eng_allow_same_color' => true,
            'af_eng_require_level' => false,
            'af_eng_skip_if_left' => false,
            'af_eng_skip_if_breakout_hold' => false,
            'af_require_structure' => false,
            'af_gap_filter_enabled' => false,
        ]);

        $this->assertSame(15, $snap['expiry_focus_sec']);
        $this->assertSame('S15', $snap['timeframe']);
        $this->assertSame('pool_plus_context', $snap['mode']);
        $this->assertNotEmpty($snap['candidates']);
        $this->assertSame('CALL', $snap['candidates'][0]['side']);
        $this->assertSame('engulf_D', $snap['candidates'][0]['setup']);
    }

    public function test_empty_rows_return_empty_candidates(): void
    {
        $snap = AuthorFlowLlmSnapshot::build([]);
        $this->assertSame([], $snap['candidates']);
        $this->assertNull($snap['current_price']);
        $this->assertSame(15, $snap['expiry_focus_sec']);
    }

    public function test_author_flow_prompts_selected_by_config_flag(): void
    {
        $cfg = [
            'analyst_author_flow_enabled' => true,
            '_bot_type' => ChartAnalystBotTypes::AUTHOR_FLOW,
        ];
        $full = OpenRouterChartLevelsParser::defaultAnalystSystemPromptForConfig($cfg);
        $short = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($cfg);

        $this->assertStringContainsString('author_flow_summary', $full);
        $this->assertStringContainsString('следующая свеча S15', $full);
        $this->assertStringContainsString('candidates', $short);
        $this->assertTrue(OpenRouterLlmConfig::analystAuthorFlowEnabled($cfg));
        $this->assertFalse(OpenRouterLlmConfig::analystSmcFlowEnabled($cfg));
        $this->assertFalse(OpenRouterLlmConfig::analystEngulfFlowEnabled($cfg));
        $this->assertTrue(ChartAnalystBotTypes::isAuthorFlow(ChartAnalystBotTypes::AUTHOR_FLOW));
    }

    public function test_pack_seeds_chart_levels_from_candidates(): void
    {
        $candles = [
            $this->bar(1, 10.0, 10.1, 9.9, 10.0),
            $this->bar(2, 10.0, 10.05, 9.85, 9.90),
            $this->bar(3, 9.90, 10.20, 9.80, 10.15),
            $this->bar(4, 10.15, 10.18, 10.00, 10.02),
            $this->bar(5, 10.02, 10.25, 9.98, 10.22),
        ];
        $pack = AuthorFlowLlmSnapshot::pack($candles, [
            'af_enable_engulf_d' => true,
            'af_enable_breakout_e' => false,
            'af_enable_raid_pb' => false,
            'af_enable_sweep' => false,
            'af_eng_require_level' => false,
            'af_eng_skip_if_left' => false,
            'af_eng_skip_if_breakout_hold' => false,
            'af_gap_filter_enabled' => false,
        ]);

        $this->assertArrayHasKey('snapshot', $pack);
        $this->assertArrayHasKey('chart_levels', $pack);
        if (($pack['snapshot']['candidates'] ?? []) !== []) {
            $this->assertNotNull($pack['chart_levels']['entry'] ?? null);
        }
    }

    /**
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float}
     */
    private function bar(int $time, float $o, float $h, float $l, float $c): array
    {
        return [
            'time' => $time,
            'open' => $o,
            'high' => $h,
            'low' => $l,
            'close' => $c,
            'volume' => 1.0,
        ];
    }
}
