<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\RsiTradeStrategy;
use App\Services\Indicators\RsiIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Контракт RSI Trade: кросс уровней 35/65 (не зональная RsiContrtrend).
 */
class RsiTradeStrategyTest extends TestCase
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
        $decision = (new RsiTradeStrategy)->analyze($this->bars(10, 1.1), null, [
            'payout' => 92.0,
            'config' => ['rsi_length' => 14],
        ]);
        $this->assertNull($decision);
    }

    public function test_cross_up_from_oversold_emits_call(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $series = array_fill(0, $n, 50.0);
        $series[$n - 2] = 30.0; // prev < 35
        $series[$n - 1] = 40.0; // curr > 35

        $rsi = $this->createMock(RsiIndicatorService::class);
        $rsi->method('computeSeries')->willReturn($series);

        $decision = (new RsiTradeStrategy($rsi))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['rsi_length' => 14, 'rsi_up' => 65, 'rsi_down' => 35],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('rsi_cross_up_from_oversold', $decision['features']['setup']);
        $this->assertSame('rsi', $decision['features']['level_kind']);
        $this->assertSame('rsi_trade_v1', $decision['decision_version']);
    }

    public function test_cross_down_from_overbought_emits_put(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $series = array_fill(0, $n, 50.0);
        $series[$n - 2] = 70.0; // prev > 65
        $series[$n - 1] = 60.0; // curr < 65

        $rsi = $this->createMock(RsiIndicatorService::class);
        $rsi->method('computeSeries')->willReturn($series);

        $decision = (new RsiTradeStrategy($rsi))->analyze($candles, null, [
            'payout' => 90.0,
            'config' => ['rsi_length' => 14, 'rsi_up' => 65, 'rsi_down' => 35],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('rsi_cross_down_from_overbought', $decision['features']['setup']);
    }

    public function test_no_cross_no_signal(): void
    {
        // RSI в зоне oversold без кросса — в отличие от Contrtrend сигнала нет.
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $rsi = $this->createMock(RsiIndicatorService::class);
        $rsi->method('computeSeries')->willReturn(array_fill(0, $n, 25.0));

        $decision = (new RsiTradeStrategy($rsi))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['rsi_length' => 14],
        ]);

        $this->assertNull($decision);
    }
}
