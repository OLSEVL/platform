<?php

namespace Tests\Unit;

use App\Support\EngulfingLlmSnapshot;
use App\Support\OpenRouterChartLevelsParser;
use App\Support\OpenRouterLlmConfig;
use Tests\TestCase;

class EngulfingLlmSnapshotTest extends TestCase
{
    public function test_build_marks_engulf_candidate_on_last_bar(): void
    {
        // Цепочка CALL из EngulfingLiquidityStrategyTest.
        $candles = [
            $this->bar(1, 10.0, 10.1, 9.9, 10.0),
            $this->bar(2, 10.0, 10.05, 9.85, 9.90),
            $this->bar(3, 9.90, 10.20, 9.80, 10.15),
            $this->bar(4, 10.15, 10.18, 10.00, 10.02),
            $this->bar(5, 10.02, 10.25, 9.98, 10.22),
        ];

        $snap = EngulfingLlmSnapshot::build($candles, [
            'allow_classic' => true,
            'allow_same_color' => true,
        ]);

        $this->assertSame(60, $snap['expiry_focus_sec']);
        $this->assertNotEmpty($snap['candidates']);
        $this->assertSame('CALL', $snap['candidates'][0]['side']);
        $this->assertSame('classic', $snap['candidates'][0]['engulf_kind']);
    }

    public function test_empty_rows_return_empty_candidates(): void
    {
        $snap = EngulfingLlmSnapshot::build([]);
        $this->assertSame([], $snap['candidates']);
        $this->assertNull($snap['current_price']);
    }

    public function test_engulf_prompts_selected_by_config_flag(): void
    {
        $cfg = ['analyst_engulf_flow_enabled' => true, '_bot_type' => 'openrouter_llm_analyst_engulf'];
        $full = OpenRouterChartLevelsParser::defaultAnalystSystemPromptForConfig($cfg);
        $short = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($cfg);

        $this->assertStringContainsString('engulfing_summary', $full);
        $this->assertStringContainsString('60 секунд', $full);
        $this->assertStringContainsString('candidates', $short);
        $this->assertTrue(OpenRouterLlmConfig::analystEngulfFlowEnabled($cfg));
        $this->assertFalse(OpenRouterLlmConfig::analystSmcFlowEnabled($cfg));
        $this->assertFalse(OpenRouterLlmConfig::analystSrlFlowEnabled($cfg));
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
