<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\RsiContrtrendStrategy;
use App\Services\Indicators\RsiIndicatorService;
use App\Services\Indicators\SmaIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Контракт RSI Contrtrend: условия OsEngine Buy/Sell → CALL/PUT.
 * SMA/RSI подставляем моками, чтобы не зависеть от формы синтетических свечей.
 */
class RsiContrtrendStrategyTest extends TestCase
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
        $decision = (new RsiContrtrendStrategy)->analyze($this->bars(10, 1.1), null, [
            'payout' => 92.0,
            'config' => ['sma_length' => 50, 'rsi_length' => 20],
        ]);
        $this->assertNull($decision);
    }

    public function test_uptrend_oversold_emits_call(): void
    {
        $n = 30;
        $close = 1.2000;
        $candles = $this->bars($n, $close);

        $sma = $this->createMock(SmaIndicatorService::class);
        $sma->method('computeSeries')->willReturn(array_fill(0, $n, 1.1900)); // SMA < close

        $rsi = $this->createMock(RsiIndicatorService::class);
        $rsi->method('computeSeries')->willReturn(array_fill(0, $n, 28.0)); // RSI < 35

        $decision = (new RsiContrtrendStrategy($sma, $rsi))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => [
                'sma_length' => 10,
                'rsi_length' => 5,
                'rsi_up' => 65,
                'rsi_down' => 35,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('rsi_oversold_in_uptrend', $decision['features']['setup']);
        $this->assertSame(1.1900, $decision['features']['level_price']);
        $this->assertStringContainsString('rsi_contrtrend', $decision['decision_version']);
    }

    public function test_downtrend_overbought_emits_put(): void
    {
        $n = 30;
        $close = 1.1000;
        $candles = $this->bars($n, $close);

        $sma = $this->createMock(SmaIndicatorService::class);
        $sma->method('computeSeries')->willReturn(array_fill(0, $n, 1.1200)); // SMA > close

        $rsi = $this->createMock(RsiIndicatorService::class);
        $rsi->method('computeSeries')->willReturn(array_fill(0, $n, 72.0)); // RSI > 65

        $decision = (new RsiContrtrendStrategy($sma, $rsi))->analyze($candles, null, [
            'payout' => 90.0,
            'config' => [
                'sma_length' => 10,
                'rsi_length' => 5,
                'rsi_up' => 65,
                'rsi_down' => 35,
            ],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('rsi_overbought_in_downtrend', $decision['features']['setup']);
    }

    public function test_oversold_but_below_sma_no_call(): void
    {
        // RSI перепродан, но цена ниже SMA — OsEngine Buy не открывает.
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $sma = $this->createMock(SmaIndicatorService::class);
        $sma->method('computeSeries')->willReturn(array_fill(0, $n, 1.1200));

        $rsi = $this->createMock(RsiIndicatorService::class);
        $rsi->method('computeSeries')->willReturn(array_fill(0, $n, 25.0));

        $decision = (new RsiContrtrendStrategy($sma, $rsi))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['sma_length' => 10, 'rsi_length' => 5],
        ]);

        $this->assertNull($decision);
    }

    public function test_mid_rsi_no_signal(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1500);

        $sma = $this->createMock(SmaIndicatorService::class);
        $sma->method('computeSeries')->willReturn(array_fill(0, $n, 1.1400));

        $rsi = $this->createMock(RsiIndicatorService::class);
        $rsi->method('computeSeries')->willReturn(array_fill(0, $n, 50.0));

        $decision = (new RsiContrtrendStrategy($sma, $rsi))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['sma_length' => 10, 'rsi_length' => 5],
        ]);

        $this->assertNull($decision);
    }
}
