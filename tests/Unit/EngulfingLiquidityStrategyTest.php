<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\EngulfingLiquidityStrategy;
use App\Services\Indicators\EngulfingLiquidityIndicatorService;
use PHPUnit\Framework\TestCase;

class EngulfingLiquidityStrategyTest extends TestCase
{
    public function test_enters_call_on_classic_three_step_chain(): void
    {
        $strategy = new EngulfingLiquidityStrategy;
        // Шаг1 @ t=2: зелёная съела красную; шаг2 @ t=3: красная реакция; шаг3 @ t=4: зелёная контр-pogloщение.
        $candles = [
            $this->candle(1, 10.0, 10.1, 9.9, 10.0),
            $this->candle(2, 10.0, 10.05, 9.85, 9.90), // красная (prev для step1)
            $this->candle(3, 9.90, 10.20, 9.80, 10.15), // step1 classic CALL
            $this->candle(4, 10.15, 10.18, 10.00, 10.02), // step2 red reaction
            $this->candle(5, 10.02, 10.25, 9.98, 10.22), // step3 green counter → CALL
        ];

        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'eng_allow_classic' => true,
                'eng_allow_same_color' => true,
                'expiry_sec' => 60,
            ],
        ]);

        $this->assertIsArray($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('counter_engulf', $decision['features']['entry_mode']);
        $this->assertSame('classic', $decision['features']['engulf_kind']);
        $this->assertSame(60, $decision['expiry_sec']);
    }

    public function test_enters_put_on_classic_three_step_chain(): void
    {
        $strategy = new EngulfingLiquidityStrategy;
        $candles = [
            $this->candle(1, 10.0, 10.1, 9.9, 10.0),
            $this->candle(2, 10.0, 10.20, 9.95, 10.15), // зелёная prev
            $this->candle(3, 10.15, 10.18, 9.70, 9.75), // step1 classic PUT
            $this->candle(4, 9.75, 9.95, 9.72, 9.90), // step2 green reaction
            $this->candle(5, 9.90, 9.92, 9.60, 9.65), // step3 red counter → PUT
        ];

        $decision = $strategy->analyze($candles, null, [
            'config' => ['expiry_sec' => 60],
        ]);

        $this->assertIsArray($decision);
        $this->assertSame('PUT', $decision['side']);
    }

    public function test_does_not_enter_on_first_engulf_only(): void
    {
        $strategy = new EngulfingLiquidityStrategy;
        $candles = [
            $this->candle(1, 10.0, 10.05, 9.85, 9.90),
            $this->candle(2, 9.90, 10.20, 9.80, 10.15), // только step1, нет цепочки
        ];

        $this->assertNull($strategy->analyze($candles, null, ['config' => []]));
    }

    public function test_indicator_shows_engulf_markers(): void
    {
        $candles = [
            $this->candle(1, 10.0, 10.1, 9.9, 10.0),
            $this->candle(2, 10.0, 10.05, 9.85, 9.90),
            $this->candle(3, 9.90, 10.20, 9.80, 10.15),
            $this->candle(4, 10.15, 10.18, 10.00, 10.02),
            $this->candle(5, 10.02, 10.25, 9.98, 10.22),
        ];

        $payload = (new EngulfingLiquidityIndicatorService)->compute($candles);
        $texts = array_column($payload['labels'], 'text');
        $joined = implode('|', $texts);

        $this->assertStringContainsString('ENGULF 1', $joined);
        $this->assertStringContainsString('ENGULF 3', $joined);
        $this->assertStringContainsString('CALL', $joined);
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float,volume:float} */
    private function candle(int $time, float $open, float $high, float $low, float $close): array
    {
        return compact('time', 'open', 'high', 'low', 'close') + ['volume' => 1.0];
    }
}
