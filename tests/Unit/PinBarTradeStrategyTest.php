<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\PinBarTradeStrategy;
use App\Services\Indicators\SmaIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Контракт PinBar Trade: тело в верхней/нижней трети HL + SMA → CALL/PUT.
 */
class PinBarTradeStrategyTest extends TestCase
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
        $decision = (new PinBarTradeStrategy)->analyze($this->bars(10, 1.1), null, [
            'payout' => 92.0,
            'config' => ['sma_length' => 50],
        ]);
        $this->assertNull($decision);
    }

    public function test_bull_pinbar_emits_call(): void
    {
        $n = 60;
        // Диапазон 1.00..1.30, треть=0.10 → верхняя треть с 1.20.
        // open/close в верхней трети, SMA ниже close.
        $candles = $this->bars($n, 1.2500);
        $candles[$n - 1] = [
            'time' => 1_700_000_000 + (($n - 1) * 60),
            'open' => 1.2200,
            'high' => 1.3000,
            'low' => 1.0000,
            'close' => 1.2500,
            'volume' => 1.0,
        ];

        $sma = $this->createMock(SmaIndicatorService::class);
        $sma->method('computeSeries')->willReturn(array_fill(0, $n, 1.2000)); // sma < close

        $decision = (new PinBarTradeStrategy($sma))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['sma_length' => 50],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame('pinbar_bull', $decision['features']['setup']);
        $this->assertSame('pinbar', $decision['features']['level_kind']);
        $this->assertSame(1.2500, $decision['features']['level_price']);
        $this->assertSame('pinbar_trade_v1', $decision['decision_version']);
        $this->assertGreaterThanOrEqual(0.56, $decision['confidence']);
    }

    public function test_bear_pinbar_emits_put(): void
    {
        $n = 60;
        // Нижняя треть до low+third = 1.00+0.10 = 1.10.
        $candles = $this->bars($n, 1.0500);
        $candles[$n - 1] = [
            'time' => 1_700_000_000 + (($n - 1) * 60),
            'open' => 1.0800,
            'high' => 1.3000,
            'low' => 1.0000,
            'close' => 1.0500,
            'volume' => 1.0,
        ];

        $sma = $this->createMock(SmaIndicatorService::class);
        $sma->method('computeSeries')->willReturn(array_fill(0, $n, 1.1000)); // sma > close

        $decision = (new PinBarTradeStrategy($sma))->analyze($candles, null, [
            'payout' => 90.0,
            'config' => ['sma_length' => 50],
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('PUT', $decision['side']);
        $this->assertSame('pinbar_bear', $decision['features']['setup']);
        $this->assertSame('pinbar', $decision['features']['level_kind']);
    }

    public function test_mid_body_no_signal(): void
    {
        $n = 60;
        // Тело в середине диапазона — не пин.
        $candles = $this->bars($n, 1.1500);
        $candles[$n - 1] = [
            'time' => 1_700_000_000 + (($n - 1) * 60),
            'open' => 1.1400,
            'high' => 1.3000,
            'low' => 1.0000,
            'close' => 1.1500,
            'volume' => 1.0,
        ];

        $sma = $this->createMock(SmaIndicatorService::class);
        $sma->method('computeSeries')->willReturn(array_fill(0, $n, 1.1000));

        $decision = (new PinBarTradeStrategy($sma))->analyze($candles, null, [
            'payout' => 92.0,
            'config' => ['sma_length' => 50],
        ]);

        $this->assertNull($decision);
    }
}
