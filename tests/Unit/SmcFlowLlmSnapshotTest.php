<?php

namespace Tests\Unit;

use App\Services\Indicators\SmcFlowIndicatorService;
use App\Support\SmcFlowLlmSnapshot;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SmcFlowLlmSnapshotTest extends TestCase
{
    #[Test]
    public function build_includes_ut_signals_and_modules(): void
    {
        $rows = [];
        $t = 1_700_000_000;
        $close = 100.0;
        for ($i = 0; $i < 80; $i++) {
            $open = $close;
            if ($i < 40) {
                $close -= 0.3;
            } elseif ($i < 55) {
                $close += 0.05;
            } else {
                $close += 0.9;
            }
            $rows[] = [
                'time' => $t + $i * 60,
                'open' => $open,
                'high' => max($open, $close) + 0.2,
                'low' => min($open, $close) - 0.2,
                'close' => $close,
                'volume' => 1.0,
            ];
        }

        $service = new SmcFlowIndicatorService;
        $overlay = $service->compute($rows, [
            'ut_enabled' => true,
            'hull_enabled' => false,
            'three_br_enabled' => false,
            'mxwll_enabled' => false,
            'lux_fvg_enabled' => false,
            'lux_ob_enabled' => false,
            'rs_enabled' => false,
            'sessions_enabled' => false,
            'keep_recent_only' => false,
            'show_ut_trailing_stop' => false,
        ]);

        $snapshot = SmcFlowLlmSnapshot::build($overlay, $rows);

        $this->assertSame('M1', $snapshot['timeframe']);
        $this->assertSame([3, 6], $snapshot['expiry_focus_min']);
        $this->assertContains('ut_bot', $snapshot['modules']);
        $this->assertIsArray($snapshot['ut_bot']['recent_signals'] ?? null);
    }
}
