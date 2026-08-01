<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\CciTradeStrategy;
use App\Services\Indicators\CciIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Контракт CCI Trade: CCI за ±150 → CALL/PUT.
 */
class CciTradeStrategyTest extends TestCase
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
        $decision = (new CciTradeStrategy)->analyze($this->bars(5, 1.1), null, [
            'payout' => 92.0,
            'config' => ['cci_length' => 20],
        ]);
        $this->assertNull($decision);
    }

    public function test_oversold_emits_call(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $cci = $this->createMock(CciIndicatorService::class);
        $cci->method('computeSeries')->willReturn(array_fill(0, $n, -180.0)); // < -150

        $decision = (new CciTradeStrategy($cci))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['cci_length' => 20, 'cci_up' => 150, 'cci_down' => -150],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('cci_oversold', $decision['features']['setup']);
        $this->assertSame('cci', $decision['features']['level_kind']);
        $this->assertSame(1.1000, $decision['features']['level_price']);
        $this->assertSame('cci_trade_v1', $decision['decision_version']);
    }

    public function test_overbought_emits_put(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $cci = $this->createMock(CciIndicatorService::class);
        $cci->method('computeSeries')->willReturn(array_fill(0, $n, 200.0)); // > 150

        $decision = (new CciTradeStrategy($cci))->analyze($candles, null, [
            'payout' => 90.0,
            'config' => ['cci_length' => 20, 'cci_up' => 150, 'cci_down' => -150],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('cci_overbought', $decision['features']['setup']);
    }

    public function test_mid_cci_no_signal(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $cci = $this->createMock(CciIndicatorService::class);
        $cci->method('computeSeries')->willReturn(array_fill(0, $n, 50.0));

        $decision = (new CciTradeStrategy($cci))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['cci_length' => 20],
        ]);

        $this->assertNull($decision);
    }
}
