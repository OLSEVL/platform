<?php

namespace Tests\Unit;

use App\Services\Indicators\AdaptiveLookBackIndicatorService;
use App\Services\Indicators\AtrChannelIndicatorService;
use App\Services\Indicators\DynamicTrendDetectorIndicatorService;
use App\Services\Indicators\LinRegChannelFastIndicatorService;
use App\Services\Indicators\NrtrWatrIndicatorService;
use App\Services\Indicators\OffsetEmaIndicatorService;
use App\Services\Indicators\OffsetSmaIndicatorService;
use App\Services\Indicators\OffsetSsmaIndicatorService;
use App\Services\Indicators\ParabolicBollingerIndicatorService;
use App\Services\Indicators\ParabolicPriceChannelIndicatorService;
use App\Services\Indicators\PriceChannelAdaptiveIndicatorService;
use App\Services\Indicators\PriceChannelOffsetIndicatorService;
use App\Services\Indicators\PriceOscillatorIndicatorService;
use App\Services\Indicators\VolatilityAverageChannelIndicatorService;
use App\Services\Indicators\VolatilityAverageIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-тесты Priority-1 OsEngine индикаторов: computeSeries + pack через compute().
 */
class OsEnginePriority1IndicatorsTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function syntheticRows(int $count = 150): array
    {
        $rows = [];
        $base = 100.0;
        for ($i = 0; $i < $count; $i++) {
            $close = $base + $i * 0.12 + sin($i / 7) * 0.8;
            $open = $close - 0.1;
            $high = max($open, $close) + 0.35;
            $low = min($open, $close) - 0.35;
            $rows[] = [
                'time' => 1_700_000_000 + $i * 60,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => 0.0,
            ];
        }

        return $rows;
    }

    /** @param list<float>|null $series */
    private function assertHasNonZero(array $series, int $warmup, string $label): void
    {
        $tail = array_slice($series, $warmup);
        $this->assertNotEmpty($tail, "$label empty");
        $ok = false;
        foreach ($tail as $v) {
            if (is_float($v) || is_int($v)) {
                if (abs((float) $v) > 1e-9) {
                    $ok = true;
                    break;
                }
            }
        }
        $this->assertTrue($ok, "$label: no non-zero after warmup");
    }

    public function test_nrtr_watr_and_atr_channel_on_price(): void
    {
        $rows = $this->syntheticRows();
        $nrtr = (new NrtrWatrIndicatorService)->computeSeries($rows);
        $this->assertCount(count($rows), $nrtr);
        $this->assertHasNonZero($nrtr, 20, 'nrtr_watr');

        $ch = (new AtrChannelIndicatorService)->computeSeries($rows);
        $this->assertCount(count($rows), $ch);
        $this->assertHasNonZero($ch, 20, 'atr_channel');

        $packed = (new NrtrWatrIndicatorService)->compute($rows);
        $this->assertArrayHasKey('nrtr', $packed['series']);
        $this->assertNotEmpty($packed['series']['nrtr']);
    }

    public function test_price_channels_adaptive_and_offset(): void
    {
        $rows = $this->syntheticRows();
        $a = (new PriceChannelAdaptiveIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('up', $a);
        $this->assertArrayHasKey('down', $a);
        $this->assertHasNonZero($a['up'], 30, 'pca.up');

        $o = (new PriceChannelOffsetIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('up_border', $o);
        $this->assertHasNonZero($o['up'], 25, 'pco.up');
    }

    public function test_parabolic_variants(): void
    {
        $rows = $this->syntheticRows(200);
        $bb = (new ParabolicBollingerIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('parabolic', $bb);
        $this->assertHasNonZero($bb['up'], 30, 'pb.up');
        $this->assertHasNonZero($bb['parabolic'], 40, 'pb.parabolic');

        $pc = (new ParabolicPriceChannelIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('parabolic', $pc);
        $this->assertHasNonZero($pc['down'], 30, 'ppc.down');
    }

    public function test_offset_mas(): void
    {
        $rows = $this->syntheticRows();
        foreach ([
            'sma' => new OffsetSmaIndicatorService,
            'ema' => new OffsetEmaIndicatorService,
            'ssma' => new OffsetSsmaIndicatorService,
        ] as $label => $svc) {
            $raw = $svc->computeSeries($rows);
            $this->assertCount(count($rows), $raw, $label);
            $this->assertHasNonZero($raw, 20, "offset_$label");
            $packed = $svc->compute($rows);
            $this->assertNotEmpty($packed['series'], $label.' packed');
        }
    }

    public function test_volatility_and_oscillators(): void
    {
        $rows = $this->syntheticRows(200);
        $va = (new VolatilityAverageIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('volatility', $va);
        $this->assertArrayHasKey('sma', $va);

        $vac = (new VolatilityAverageChannelIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('up', $vac);
        $this->assertArrayHasKey('down', $vac);

        $po = (new PriceOscillatorIndicatorService)->computeSeries($rows);
        $this->assertCount(count($rows), $po);
        $this->assertHasNonZero($po, 30, 'price_oscillator');

        $alb = (new AdaptiveLookBackIndicatorService)->computeSeries($rows);
        $this->assertCount(count($rows), $alb);
    }

    public function test_dtd_and_linreg_fast(): void
    {
        $rows = $this->syntheticRows(200);
        $dtd = (new DynamicTrendDetectorIndicatorService)->computeSeries($rows);
        $this->assertCount(count($rows), $dtd);
        $this->assertHasNonZero($dtd, 30, 'dtd');

        $lr = (new LinRegChannelFastIndicatorService)->computeSeries($rows);
        $this->assertArrayHasKey('up', $lr);
        $this->assertArrayHasKey('mid', $lr);
        $this->assertArrayHasKey('down', $lr);
        $this->assertHasNonZero($lr['up'], 40, 'lrcf.up');

        $packed = (new LinRegChannelFastIndicatorService)->compute($rows);
        $this->assertArrayHasKey('up', $packed['series']);
        $this->assertArrayHasKey('down', $packed['series']);
    }
}
