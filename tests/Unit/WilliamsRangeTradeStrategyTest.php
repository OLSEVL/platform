<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\WilliamsRangeTradeStrategy;
use App\Services\Indicators\WilliamsRangeIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Контракт Williams Range Trade: вход в зону WR → CALL/PUT (по умолчанию только кросс).
 */
class WilliamsRangeTradeStrategyTest extends TestCase
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

    /** @return list<float> */
    private function seriesCrossInto(int $n, float $before, float $now): array
    {
        $s = array_fill(0, $n, $before);
        $s[$n - 1] = $now;

        return $s;
    }

    public function test_warmup_returns_null(): void
    {
        $decision = (new WilliamsRangeTradeStrategy)->analyze($this->bars(5, 1.1), null, [
            'payout' => 92.0,
            'config' => ['wr_length' => 14],
        ]);
        $this->assertNull($decision);
    }

    public function test_oversold_cross_emits_call(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $wr = $this->createMock(WilliamsRangeIndicatorService::class);
        // Было −70 (ещё не в зоне), стало −92 (вход ниже −90).
        $wr->method('computeSeries')->willReturn($this->seriesCrossInto($n, -70.0, -92.0));

        $decision = (new WilliamsRangeTradeStrategy($wr))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['wr_length' => 14, 'wr_up' => -10, 'wr_down' => -90, 'require_cross' => true],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('wr_oversold', $decision['features']['setup']);
    }

    public function test_held_oversold_no_signal_with_cross(): void
    {
        // Удержание зоны без кросса — шум, сигнала нет.
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $wr = $this->createMock(WilliamsRangeIndicatorService::class);
        $wr->method('computeSeries')->willReturn(array_fill(0, $n, -95.0));

        $decision = (new WilliamsRangeTradeStrategy($wr))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['wr_length' => 14, 'wr_down' => -90, 'require_cross' => true],
        ]);

        $this->assertNull($decision);
    }

    public function test_overbought_cross_emits_put(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $wr = $this->createMock(WilliamsRangeIndicatorService::class);
        $wr->method('computeSeries')->willReturn($this->seriesCrossInto($n, -30.0, -5.0));

        $decision = (new WilliamsRangeTradeStrategy($wr))->analyze($candles, null, [
            'payout' => 90.0,
            'config' => ['wr_length' => 14, 'wr_up' => -10, 'wr_down' => -90, 'require_cross' => true],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('wr_overbought', $decision['features']['setup']);
    }

    public function test_mid_wr_no_signal(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $wr = $this->createMock(WilliamsRangeIndicatorService::class);
        $wr->method('computeSeries')->willReturn(array_fill(0, $n, -50.0));

        $decision = (new WilliamsRangeTradeStrategy($wr))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['wr_length' => 14],
        ]);

        $this->assertNull($decision);
    }
}
