<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\BollingerContrtrendStrategy;
use App\Services\Indicators\BollingerIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Контракт Bollinger Contrtrend: close за полосой BB → CALL/PUT (OsEngine StrategyBollinger).
 */
class BollingerContrtrendStrategyTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function bars(int $n, float $close): array
    {
        $candles = [];
        for ($i = 0; $i < $n; $i++) {
            $candles[] = [
                'time' => 1_700_000_000 + ($i * 60),
                'open' => $close,
                'high' => $close + 0.0001,
                'low' => $close - 0.0001,
                'close' => $close,
                'volume' => 1.0,
            ];
        }

        return $candles;
    }

    public function test_warmup_returns_null(): void
    {
        $decision = (new BollingerContrtrendStrategy)->analyze($this->bars(5, 1.1), null, [
            'payout' => 92.0,
            'config' => ['bb_length' => 12, 'bb_deviation' => 2.0],
        ]);
        $this->assertNull($decision);
    }

    public function test_below_lower_band_emits_call(): void
    {
        $n = 30;
        $close = 1.0500;
        $candles = $this->bars($n, $close);

        $bb = $this->createMock(BollingerIndicatorService::class);
        $bb->method('computeSeries')->willReturn([
            'mid' => array_fill(0, $n, 1.1000),
            'up' => array_fill(0, $n, 1.1200),
            'down' => array_fill(0, $n, 1.0800), // close < down
        ]);

        $decision = (new BollingerContrtrendStrategy($bb))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['bb_length' => 12, 'bb_deviation' => 2.0],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('bb_touch_lower', $decision['features']['setup']);
        $this->assertSame('bb_lower', $decision['features']['level_kind']);
        $this->assertSame(1.0800, $decision['features']['level_price']);
        $this->assertStringContainsString('bollinger_contrtrend', $decision['decision_version']);
    }

    public function test_above_upper_band_emits_put(): void
    {
        $n = 30;
        $close = 1.1500;
        $candles = $this->bars($n, $close);

        $bb = $this->createMock(BollingerIndicatorService::class);
        $bb->method('computeSeries')->willReturn([
            'mid' => array_fill(0, $n, 1.1000),
            'up' => array_fill(0, $n, 1.1200),
            'down' => array_fill(0, $n, 1.0800), // close > up
        ]);

        $decision = (new BollingerContrtrendStrategy($bb))->analyze($candles, null, [
            'payout' => 90.0,
            'config' => ['bb_length' => 12, 'bb_deviation' => 2.0],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('bb_touch_upper', $decision['features']['setup']);
        $this->assertSame('bb_upper', $decision['features']['level_kind']);
    }

    public function test_inside_bands_no_signal(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $bb = $this->createMock(BollingerIndicatorService::class);
        $bb->method('computeSeries')->willReturn([
            'mid' => array_fill(0, $n, 1.1000),
            'up' => array_fill(0, $n, 1.1200),
            'down' => array_fill(0, $n, 1.0800),
        ]);

        $decision = (new BollingerContrtrendStrategy($bb))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['bb_length' => 12],
        ]);

        $this->assertNull($decision);
    }
}
