<?php

namespace Tests\Unit;

use App\Support\ChartIndicatorInstanceRegistry;
use Tests\TestCase;

class ChartIndicatorInstanceRegistrySeriesKeyTest extends TestCase
{
    /**
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    private function sampleCandles(): array
    {
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $base = 1.0 + sin($i / 4.0) * 0.05 + $i * 0.001;
            $rows[] = [
                'time' => 1_700_000_000 + $i * 30,
                'open' => $base,
                'high' => $base + 0.008,
                'low' => $base - 0.008,
                'close' => $base + 0.002,
                'volume' => 1000.0 + $i * 10,
            ];
        }

        return $rows;
    }

    public function test_single_line_series_keys_exist_in_compute_output(): void
    {
        $rows = $this->sampleCandles();
        $singleLineRenders = ['single', 'osc_line', 'rsi', 'cci', 'williams', 'demarker'];

        foreach (ChartIndicatorInstanceRegistry::supportedHelpKeys() as $helpKey) {
            $entry = ChartIndicatorInstanceRegistry::entry($helpKey);
            if (! in_array($entry['render'], $singleLineRenders, true)) {
                continue;
            }
            $seriesKey = $entry['series_key'] ?? null;
            if (! is_string($seriesKey) || $seriesKey === '') {
                $this->fail("Indicator {$helpKey} with render {$entry['render']} must define series_key in registry.");
            }

            $serviceClass = ChartIndicatorInstanceRegistry::serviceClass($helpKey);
            $computed = app($serviceClass)->compute($rows, ChartIndicatorInstanceRegistry::defaultParams($helpKey));

            $this->assertArrayHasKey(
                $seriesKey,
                $computed['series'] ?? [],
                "Indicator {$helpKey}: compute() must return series['{$seriesKey}']"
            );
            $this->assertNotEmpty(
                $computed['series'][$seriesKey],
                "Indicator {$helpKey}: series['{$seriesKey}'] must not be empty on sample candles"
            );
        }
    }
}
