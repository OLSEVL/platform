<?php

namespace Tests\Unit;

use App\Services\Indicators\AcIndicatorService;
use App\Services\Indicators\BearsPowerIndicatorService;
use App\Services\Indicators\BullsPowerIndicatorService;
use App\Services\Indicators\CogIndicatorService;
use App\Services\Indicators\DonchianIndicatorService;
use App\Services\Indicators\DpoIndicatorService;
use App\Services\Indicators\EfficiencyRatioIndicatorService;
use App\Services\Indicators\MassIndexIndicatorService;
use App\Services\Indicators\PivotFloorIndicatorService;
use App\Services\Indicators\QstickIndicatorService;
use App\Services\Indicators\RaviIndicatorService;
use App\Services\Indicators\StochRsiIndicatorService;
use App\Services\Indicators\VhFilterIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-тесты 13 новых OsEngine OHLC-индикаторов: форма computeSeries и ненулевые значения после прогрева.
 * Без golden JSON — только проверка, что алгоритмы отрабатывают на синтетических рядах.
 */
class OsEngineOhlcIndicatorsTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function syntheticRows(int $count = 100, bool $rising = true): array
    {
        $rows = [];
        $base = 100.0;
        for ($i = 0; $i < $count; $i++) {
            // Восходящий или нисходящий тренд с небольшим шумом внутри бара.
            $drift = $rising ? ($i * 0.15) : (-$i * 0.12);
            $close = $base + $drift + (($i % 5) - 2) * 0.05;
            $open = $close - ($rising ? 0.08 : -0.08);
            $high = max($open, $close) + 0.25 + ($i % 3) * 0.02;
            $low = min($open, $close) - 0.25 - ($i % 4) * 0.02;
            $rows[] = [
                'time' => 1_700_000_000 + $i * 60,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => 1000.0 + $i,
            ];
        }

        return $rows;
    }

    /** @param list<float> $series */
    private function assertHasNonZeroAfterWarmup(array $series, int $warmup, string $label): void
    {
        $this->assertCount(count($series), $series, "$label: длина серии");
        $tail = array_slice($series, $warmup);
        $this->assertNotEmpty($tail, "$label: хвост после прогрева пуст");
        $hasNonZero = false;
        foreach ($tail as $v) {
            if (abs($v) > 1e-9) {
                $hasNonZero = true;
                break;
            }
        }
        $this->assertTrue($hasNonZero, "$label: ожидали ненулевые значения после прогрева");
    }

    public function test_donchian_compute_series_shape_and_values(): void
    {
        $rows = $this->syntheticRows(120, true);
        $raw = (new DonchianIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('up', $raw);
        $this->assertArrayHasKey('mid', $raw);
        $this->assertArrayHasKey('down', $raw);
        $this->assertCount(120, $raw['up']);
        $this->assertHasNonZeroAfterWarmup($raw['up'], 25, 'donchian.up');
        $this->assertHasNonZeroAfterWarmup($raw['mid'], 25, 'donchian.mid');
        $this->assertHasNonZeroAfterWarmup($raw['down'], 25, 'donchian.down');
    }

    public function test_stoch_rsi_compute_series(): void
    {
        $rows = $this->syntheticRows(100, true);
        $raw = (new StochRsiIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('k', $raw);
        $this->assertArrayHasKey('d', $raw);
        $this->assertHasNonZeroAfterWarmup($raw['k'], 40, 'stoch_rsi.k');
        $this->assertHasNonZeroAfterWarmup($raw['d'], 45, 'stoch_rsi.d');
    }

    public function test_ac_histogram_series(): void
    {
        $rows = $this->syntheticRows(100, false);
        $raw = (new AcIndicatorService)->computeSeries($rows);
        $this->assertIsArray($raw);
        $this->assertCount(100, $raw);
        $this->assertHasNonZeroAfterWarmup($raw, 40, 'ac');
    }

    public function test_bulls_and_bears_power_histograms(): void
    {
        $rows = $this->syntheticRows(100, true);
        $bulls = (new BullsPowerIndicatorService)->computeSeries($rows);
        $bears = (new BearsPowerIndicatorService)->computeSeries($rows);
        $this->assertCount(100, $bulls);
        $this->assertCount(100, $bears);
        $this->assertHasNonZeroAfterWarmup($bulls, 20, 'bulls_power');
        $this->assertHasNonZeroAfterWarmup($bears, 20, 'bears_power');
    }

    public function test_dpo_qstick_vh_filter_efficiency_ratio_pane_series(): void
    {
        $rows = $this->syntheticRows(100, true);
        $dpo = (new DpoIndicatorService)->computeSeries($rows);
        $qstick = (new QstickIndicatorService)->computeSeries($rows);
        $vh = (new VhFilterIndicatorService)->computeSeries($rows);
        $er = (new EfficiencyRatioIndicatorService)->computeSeries($rows);
        $this->assertCount(100, $dpo);
        $this->assertCount(100, $qstick);
        $this->assertCount(100, $vh);
        $this->assertCount(100, $er);
        $this->assertHasNonZeroAfterWarmup($dpo, 30, 'dpo');
        $this->assertHasNonZeroAfterWarmup($qstick, 15, 'qstick');
        $this->assertHasNonZeroAfterWarmup($vh, 15, 'vh_filter');
        $this->assertHasNonZeroAfterWarmup($er, 15, 'efficiency_ratio');
    }

    public function test_ravi_cog_and_mass_index_multi_series(): void
    {
        $rows = $this->syntheticRows(100, true);
        $ravi = (new RaviIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('ravi', $ravi);
        $this->assertArrayHasKey('up_line', $ravi);
        $this->assertArrayHasKey('down_line', $ravi);
        $this->assertHasNonZeroAfterWarmup($ravi['ravi'], 70, 'ravi');

        $cog = (new CogIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('cog', $cog);
        $this->assertArrayHasKey('prev_cog', $cog);
        $this->assertHasNonZeroAfterWarmup($cog['cog'], 15, 'cog');

        $mi = (new MassIndexIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('mi', $mi);
        $this->assertArrayHasKey('level_up', $mi);
        $this->assertArrayHasKey('level_down', $mi);
        $this->assertHasNonZeroAfterWarmup($mi['mi'], 30, 'mass_index.mi');
    }

    public function test_pivot_floor_overlay_levels(): void
    {
        // Несколько «дней» через смену unix-time (86400 сек).
        $rows = [];
        for ($d = 0; $d < 5; $d++) {
            for ($b = 0; $b < 24; $b++) {
                $i = $d * 24 + $b;
                $close = 100.0 + $d * 2 + $b * 0.1;
                $rows[] = [
                    'time' => 1_700_000_000 + $d * 86400 + $b * 3600,
                    'open' => $close - 0.1,
                    'high' => $close + 0.5,
                    'low' => $close - 0.5,
                    'close' => $close,
                    'volume' => 500.0,
                ];
            }
        }
        $raw = (new PivotFloorIndicatorService)->computeSeries($rows);
        foreach (['p', 'r1', 'r2', 'r3', 's1', 's2', 's3'] as $key) {
            $this->assertArrayHasKey($key, $raw, "pivot_floor.$key");
            $this->assertCount(count($rows), $raw[$key]);
        }
        $this->assertHasNonZeroAfterWarmup($raw['p'], 24, 'pivot_floor.p');
    }
}
