<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\SweepRaidLiquidityStrategy;
use App\Services\Indicators\SweepRaidLiquidityIndicatorService;
use PHPUnit\Framework\TestCase;

class SweepRaidLiquidityStrategyTest extends TestCase
{
    public function test_enters_put_on_valid_green_sweep_of_confirmed_high(): void
    {
        $strategy = new SweepRaidLiquidityStrategy;
        $decision = $strategy->analyze($this->highSweepCandles(), null, [
            'config' => [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => true,
                'srl_enter_pullback' => false,
                'srl_demarker_enabled' => false,
                'expiry_sec' => 60,
            ],
            'payout' => 85.0,
        ]);

        $this->assertIsArray($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('sweep_high', $decision['features']['setup']);
        $this->assertSame(60, $decision['expiry_sec']);
    }

    public function test_demarker_filter_blocks_sweep_without_zone_crossing(): void
    {
        $strategy = new SweepRaidLiquidityStrategy;
        $decision = $strategy->analyze($this->highSweepCandles(), null, [
            'config' => [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => true,
                'srl_enter_pullback' => false,
                'srl_demarker_enabled' => true,
                'srl_demarker_period' => 6,
                'srl_demarker_oversold' => 10,
                'srl_demarker_overbought' => 90,
                'expiry_sec' => 60,
            ],
        ]);

        $this->assertNull($decision);
    }

    public function test_enters_call_on_first_red_pullback_after_low_raid(): void
    {
        $strategy = new SweepRaidLiquidityStrategy;
        $candles = [
            $this->candle(1, 10, 10.2, 9.8, 10.1),
            $this->candle(2, 10.1, 10.2, 9.0, 9.5), // подтверждаемый минимум
            $this->candle(3, 9.5, 10.0, 9.3, 9.8),
            $this->candle(4, 9.8, 9.9, 8.8, 9.3), // тело ниже лоу: первая половина рейда
            $this->candle(5, 9.3, 9.6, 9.1, 9.4), // возврат над лоу: рейд
            $this->candle(6, 9.5, 9.6, 9.2, 9.3), // первая красная откатная: CALL
        ];
        $decision = $strategy->analyze($candles, null, [
            'config' => [
                'srl_pivot_length' => 1,
                'srl_enter_sweep' => false,
                'srl_enter_pullback' => true,
                'expiry_sec' => 60,
            ],
        ]);

        $this->assertIsArray($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('pullback', $decision['features']['entry_mode']);
    }

    public function test_standalone_indicator_exposes_sweep_marker_from_shared_detector(): void
    {
        $payload = (new SweepRaidLiquidityIndicatorService)->compute($this->highSweepCandles(), [
            'pivot_length' => 1,
            'show_sweeps' => true,
            'show_raids' => false,
            'show_pullback_entries' => false,
        ]);

        $labels = $payload['labels'];
        $sweepLabels = array_filter($labels, static fn (array $label): bool => str_contains((string) $label['text'], 'SWEEP H'));

        $this->assertNotEmpty($sweepLabels);
    }

    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function highSweepCandles(): array
    {
        return [
            $this->candle(1, 10.0, 10.2, 9.8, 10.1),
            $this->candle(2, 10.1, 11.0, 10.0, 10.8), // фрактальный high
            $this->candle(3, 10.8, 10.9, 10.1, 10.3),
            $this->candle(4, 10.3, 11.2, 10.2, 10.7), // high>11, зелёная, close<11
        ];
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float,volume:float} */
    private function candle(int $time, float $open, float $high, float $low, float $close): array
    {
        return compact('time', 'open', 'high', 'low', 'close') + ['volume' => 1.0];
    }
}
