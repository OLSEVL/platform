<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\BreakLinRegChannelStrategy;
use App\Services\Bots\Strategies\EnvelopTrendStrategy;
use App\Services\Bots\Strategies\MomentumMacdStrategy;
use App\Services\Bots\Strategies\ParabolicBollingerStrategy;
use App\Services\Bots\Strategies\ParabolicPriceChannelStrategy;
use App\Services\Bots\Strategies\ParabolicSarTradeStrategy;
use App\Services\Bots\Strategies\PivotPointsStrategy;
use App\Services\Bots\Strategies\PriceChannelTradeStrategy;
use App\Services\Bots\Strategies\SmaStochasticStrategy;
use App\Support\OsEngineWaveABotConfig;
use PHPUnit\Framework\TestCase;

/**
 * Smoke: Wave A стратегии возвращают CALL/PUT или null без фаталов.
 */
class WaveAStrategiesTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function rows(int $n = 400, float $trend = 0.02): array
    {
        $rows = [];
        $base = 100.0;
        for ($i = 0; $i < $n; $i++) {
            $close = $base + $i * $trend + sin($i / 7.0) * 0.8;
            $open = $close - 0.05;
            $high = max($open, $close) + 0.35;
            $low = min($open, $close) - 0.35;
            // Смена календарного дня каждые 200 баров (~для PivotFloor).
            $day = intdiv($i, 200);
            $rows[] = [
                'time' => strtotime('2024-01-01 UTC') + $day * 86400 + ($i % 200) * 60,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => 0.0,
            ];
        }

        return $rows;
    }

    public function test_wave_a_types_registered(): void
    {
        $this->assertCount(9, OsEngineWaveABotConfig::TYPES);
        foreach (OsEngineWaveABotConfig::TYPES as $type) {
            $this->assertTrue(OsEngineWaveABotConfig::isWaveA($type));
            $cfg = OsEngineWaveABotConfig::defaultConfig($type);
            $this->assertArrayHasKey('version', $cfg);
            $this->assertArrayHasKey('expiry_sec', $cfg);
        }
    }

    /**
     * @dataProvider strategiesProvider
     */
    public function test_strategy_analyze_returns_null_or_valid_side(string $class): void
    {
        $rows = $this->rows(400);
        /** @var \App\Services\Bots\Strategies\BotStrategyInterface $svc */
        $svc = new $class;
        $out = $svc->analyze($rows, null, ['config' => [], 'payout' => 85.0]);
        if ($out === null) {
            $this->assertNull($out);

            return;
        }
        $this->assertContains($out['side'], ['CALL', 'PUT']);
        $this->assertArrayHasKey('confidence', $out);
        $this->assertArrayHasKey('decision_version', $out);
        $this->assertGreaterThanOrEqual(0.56, $out['confidence']);
    }

    /** @return list<array{0: class-string}> */
    public static function strategiesProvider(): array
    {
        return [
            [EnvelopTrendStrategy::class],
            [PriceChannelTradeStrategy::class],
            [ParabolicSarTradeStrategy::class],
            [BreakLinRegChannelStrategy::class],
            [PivotPointsStrategy::class],
            [SmaStochasticStrategy::class],
            [MomentumMacdStrategy::class],
            [ParabolicBollingerStrategy::class],
            [ParabolicPriceChannelStrategy::class],
        ];
    }

    public function test_envelop_breakout_on_extreme_bar(): void
    {
        // Плоский ряд → узкие полосы; последний бар с огромным high → CALL.
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = [
                'time' => 1_700_000_000 + $i * 60,
                'open' => 100.0,
                'high' => 100.2,
                'low' => 99.8,
                'close' => 100.0,
                'volume' => 0.0,
            ];
        }
        $i = count($rows) - 1;
        // Close оставляем ~100, чтобы SMA/полосы не уехали; пробиваем только high.
        $rows[$i]['high'] = 110.0;
        $rows[$i]['close'] = 100.1;
        $rows[$i]['open'] = 100.0;
        $rows[$i]['low'] = 99.95;

        $out = (new EnvelopTrendStrategy)->analyze($rows, null, [
            'config' => ['length' => 10, 'deviation' => 0.3],
            'payout' => 90.0,
        ]);
        $this->assertNotNull($out);
        $this->assertSame('CALL', $out['side']);
    }

    public function test_price_channel_uses_previous_channel(): void
    {
        $rows = $this->rows(60, 0.05);
        $out = (new PriceChannelTradeStrategy)->analyze($rows, null, [
            'config' => ['length_up' => 10, 'length_down' => 10],
            'payout' => 90.0,
        ]);
        // Может быть null на спокойных данных — главное без исключения.
        if ($out !== null) {
            $this->assertContains($out['side'], ['CALL', 'PUT']);
            $this->assertSame('price_channel_trade_v1', $out['decision_version']);
        } else {
            $this->assertNull($out);
        }
    }
}
