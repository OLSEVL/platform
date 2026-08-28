<?php

namespace Tests\Unit;

use App\Support\OpenRouterChartLevelsParser;
use App\Support\OpenRouterLlmConfig;
use App\Support\SweepRaidLlmSnapshot;
use Tests\TestCase;

class SweepRaidLlmSnapshotTest extends TestCase
{
    public function test_build_marks_sweep_candidate_on_last_bar(): void
    {
        // FH на индексе 2 (pivot=2): high=1.10, затем свип зелёной тенью выше и close ниже.
        $candles = [
            $this->bar(1000, 1.00, 1.05, 0.99, 1.02),
            $this->bar(1060, 1.02, 1.08, 1.01, 1.06),
            $this->bar(1120, 1.06, 1.10, 1.05, 1.09), // pivot high candidate
            $this->bar(1180, 1.09, 1.095, 1.07, 1.08),
            $this->bar(1240, 1.08, 1.085, 1.06, 1.07), // подтверждает пивот
            $this->bar(1300, 1.07, 1.12, 1.065, 1.09), // зелёный свип хая → PUT
        ];

        $snap = SweepRaidLlmSnapshot::build($candles, [
            'pivot_length' => 2,
            'enter_sweep' => true,
            'enter_pullback' => true,
        ]);

        $this->assertSame(60, $snap['expiry_focus_sec']);
        $this->assertNotEmpty($snap['candidates']);
        $this->assertSame('PUT', $snap['candidates'][0]['side']);
        $this->assertSame('sweep', $snap['candidates'][0]['kind']);
    }

    public function test_empty_rows_return_empty_candidates(): void
    {
        $snap = SweepRaidLlmSnapshot::build([]);
        $this->assertSame([], $snap['candidates']);
        $this->assertNull($snap['current_price']);
    }

    public function test_srl_prompts_selected_by_config_flag(): void
    {
        $cfg = ['analyst_srl_flow_enabled' => true];
        $full = OpenRouterChartLevelsParser::defaultAnalystSystemPromptForConfig($cfg);
        $short = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($cfg);

        $this->assertStringContainsString('sweep_raid_summary', $full);
        $this->assertStringContainsString('60 секунд', $full);
        $this->assertStringContainsString('candidates', $short);
        $this->assertTrue(OpenRouterLlmConfig::analystSrlFlowEnabled($cfg));
        $this->assertFalse(OpenRouterLlmConfig::analystSmcFlowEnabled($cfg));
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
