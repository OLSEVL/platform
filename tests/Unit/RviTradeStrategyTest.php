<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\RviTradeStrategy;
use App\Services\Indicators\RviIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Контракт RVI Trade: кросс rvi/signal + |signal|≥порога → CALL/PUT.
 */
class RviTradeStrategyTest extends TestCase
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
        $decision = (new RviTradeStrategy)->analyze($this->bars(8, 1.1), null, [
            'payout' => 92.0,
            'config' => ['rvi_length' => 10],
        ]);
        $this->assertNull($decision);
    }

    public function test_bull_cross_emits_call(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        // Prev: rvi ≤ signal; now: rvi > signal, signal < −0.20.
        $rviSeries = array_fill(0, $n, -0.35);
        $sigSeries = array_fill(0, $n, -0.30);
        $rviSeries[$n - 1] = -0.10; // кросс вверх через signal

        $rvi = $this->createMock(RviIndicatorService::class);
        $rvi->method('computeSeries')->willReturn([
            'rvi' => $rviSeries,
            'signal' => $sigSeries,
        ]);

        $decision = (new RviTradeStrategy($rvi))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['rvi_length' => 10, 'min_signal_abs' => 0.20, 'require_cross' => true],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('rvi_bull_cross', $decision['features']['setup']);
    }

    public function test_held_state_no_signal_with_cross(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        // Состояние bull всё время без кросса — шум.
        $rvi = $this->createMock(RviIndicatorService::class);
        $rvi->method('computeSeries')->willReturn([
            'rvi' => array_fill(0, $n, -0.10),
            'signal' => array_fill(0, $n, -0.30),
        ]);

        $decision = (new RviTradeStrategy($rvi))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['rvi_length' => 10, 'require_cross' => true],
        ]);

        $this->assertNull($decision);
    }

    public function test_bear_cross_emits_put(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $rviSeries = array_fill(0, $n, 0.35);
        $sigSeries = array_fill(0, $n, 0.30);
        $rviSeries[$n - 1] = 0.10; // кросс вниз через signal

        $rvi = $this->createMock(RviIndicatorService::class);
        $rvi->method('computeSeries')->willReturn([
            'rvi' => $rviSeries,
            'signal' => $sigSeries,
        ]);

        $decision = (new RviTradeStrategy($rvi))->analyze($candles, null, [
            'payout' => 90.0,
            'config' => ['rvi_length' => 10, 'min_signal_abs' => 0.20, 'require_cross' => true],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('rvi_bear_cross', $decision['features']['setup']);
    }

    public function test_weak_signal_near_zero_filtered(): void
    {
        $n = 30;
        $candles = $this->bars($n, 1.1000);

        $rviSeries = array_fill(0, $n, -0.08);
        $sigSeries = array_fill(0, $n, -0.05);
        $rviSeries[$n - 1] = -0.01; // кросс, но |signal| < 0.20

        $rvi = $this->createMock(RviIndicatorService::class);
        $rvi->method('computeSeries')->willReturn([
            'rvi' => $rviSeries,
            'signal' => $sigSeries,
        ]);

        $decision = (new RviTradeStrategy($rvi))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['rvi_length' => 10, 'min_signal_abs' => 0.20, 'require_cross' => true],
        ]);

        $this->assertNull($decision);
    }
}
