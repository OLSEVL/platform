<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\CustomCandlesImpulseStrategy;
use App\Services\Bots\Strategies\ThreeSoldierVolAdaptiveStrategy;
use App\Services\Bots\Strategies\VolAdaptiveCandlesStrategy;
use App\Support\OsEngineDailyVolatilityAdaptive;
use App\Support\OsEngineWaveBBotConfig;
use PHPUnit\Framework\TestCase;

/**
 * Smoke + явные сетапы Wave B (impulse / vola-adaptive) под CALL/PUT.
 */
class WaveBStrategiesTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function flatDay(int $n = 100, float $price = 100.0, int $dayOffset = 0): array
    {
        $rows = [];
        $baseTs = strtotime('2024-06-01 UTC') + $dayOffset * 86400;
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'time' => $baseTs + $i * 60,
                'open' => $price,
                'high' => $price + 0.5,
                'low' => $price - 0.5,
                'close' => $price,
                'volume' => 0.0,
            ];
        }

        return $rows;
    }

    public function test_wave_b_types_registered(): void
    {
        $this->assertCount(3, OsEngineWaveBBotConfig::TYPES);
        foreach (OsEngineWaveBBotConfig::TYPES as $type) {
            $this->assertTrue(OsEngineWaveBBotConfig::isWaveB($type));
            $cfg = OsEngineWaveBBotConfig::defaultConfig($type);
            $this->assertArrayHasKey('version', $cfg);
            $this->assertArrayHasKey('expiry_sec', $cfg);
        }
    }

    public function test_daily_vola_percent_from_range(): void
    {
        // OsEngine при days=1 фиксирует волу самого свежего дня при смене даты назад.
        // День 0: широкий диапазон; день 1 (текущий): high=101, low=99 → ≈2.02%.
        $day0 = $this->flatDay(50, 100.0, 0);
        $day1 = $this->flatDay(10, 100.0, 1);
        foreach ($day1 as &$r) {
            $r['high'] = 101.0;
            $r['low'] = 99.0;
        }
        unset($r);
        $all = array_merge($day0, $day1);

        $avg = OsEngineDailyVolatilityAdaptive::averageDailyVolaPercent($all, 1);
        $this->assertNotNull($avg);
        $this->assertGreaterThan(1.5, $avg);
        $this->assertLessThan(3.0, $avg);
    }

    public function test_vol_adaptive_candle_bull_signal(): void
    {
        $rows = $this->flatDay(30, 100.0);
        // Адаптацию отключаем (days=0) — используем fallback порог.
        $i = count($rows) - 1;
        $rows[$i]['open'] = 100.0;
        $rows[$i]['close'] = 100.20; // тело 0.20%
        $rows[$i]['high'] = 100.25;
        $rows[$i]['low'] = 99.95;

        $out = (new VolAdaptiveCandlesStrategy)->analyze($rows, null, [
            'config' => [
                'days_volatility_adaptive' => 0,
                'height_signal_candle' => 0.05,
            ],
            'payout' => 90.0,
        ]);
        $this->assertNotNull($out);
        $this->assertSame('CALL', $out['side']);
        $this->assertSame('vol_adaptive_candles_v1', $out['decision_version']);
    }

    public function test_three_soldier_vol_adaptive_bear(): void
    {
        $rows = $this->flatDay(20, 100.0);
        // Три медвежьих солдата с заметным телом.
        $seq = [
            ['open' => 100.30, 'close' => 100.00],
            ['open' => 100.00, 'close' => 99.70],
            ['open' => 99.70, 'close' => 99.40],
        ];
        $base = count($rows) - 3;
        foreach ($seq as $k => $ohlc) {
            $idx = $base + $k;
            $rows[$idx]['open'] = $ohlc['open'];
            $rows[$idx]['close'] = $ohlc['close'];
            $rows[$idx]['high'] = max($ohlc['open'], $ohlc['close']) + 0.05;
            $rows[$idx]['low'] = min($ohlc['open'], $ohlc['close']) - 0.05;
        }

        $out = (new ThreeSoldierVolAdaptiveStrategy)->analyze($rows, null, [
            'config' => [
                'days_volatility_adaptive' => 0,
                'height_soldiers' => 0.5,
                'min_height_one_soldier' => 0.2,
            ],
            'payout' => 85.0,
        ]);
        $this->assertNotNull($out);
        $this->assertSame('PUT', $out['side']);
        $this->assertSame('three_soldier_vol_adaptive_v1', $out['decision_version']);
    }

    public function test_custom_candles_impulse_bull(): void
    {
        $rows = $this->flatDay(20, 100.0);
        // Две бычьи свечи подряд; span last−ref = 2×60 при N=2.
        $n = count($rows);
        for ($i = $n - 2; $i < $n; $i++) {
            $rows[$i]['open'] = 100.0;
            $rows[$i]['close'] = 100.15;
            $rows[$i]['high'] = 100.20;
            $rows[$i]['low'] = 99.95;
        }

        $out = (new CustomCandlesImpulseStrategy)->analyze($rows, null, [
            'config' => [
                'candles_count_to_entry' => 2,
                'seconds_time_on_candles' => 120,
            ],
            'payout' => 88.0,
        ]);
        $this->assertNotNull($out);
        $this->assertSame('CALL', $out['side']);
        $this->assertSame('custom_candles_impulse_v1', $out['decision_version']);
    }

    public function test_custom_candles_impulse_rejects_slow_span(): void
    {
        $rows = $this->flatDay(20, 100.0);
        $n = count($rows);
        for ($i = $n - 2; $i < $n; $i++) {
            $rows[$i]['open'] = 100.0;
            $rows[$i]['close'] = 100.15;
        }

        $out = (new CustomCandlesImpulseStrategy)->analyze($rows, null, [
            'config' => [
                'candles_count_to_entry' => 2,
                'seconds_time_on_candles' => 60, // слишком коротко для span=120
            ],
            'payout' => 88.0,
        ]);
        $this->assertNull($out);
    }
}
