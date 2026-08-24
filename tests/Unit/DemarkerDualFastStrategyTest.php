<?php

namespace Tests\Unit;

use App\Services\Bots\Strategies\DemarkerDualFastStrategy;
use App\Services\Indicators\DemarkerIndicatorService;
use App\Support\DemarkerDualFastConfig;
use PHPUnit\Framework\TestCase;

class DemarkerDualFastStrategyTest extends TestCase
{
    public function test_call_requires_dem1_exit_with_delta_min_zone_and_dem2_confirm(): void
    {
        $strategy = new DemarkerDualFastStrategy(new DemarkerIndicatorService);
        $decision = $strategy->analyze($this->callPath(), null, [
            'config' => $this->baseConfig([
                // period=1 упрощает синтетический путь DeM.
                'demarker_period' => 1,
                'second_demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'demarker_level_delta' => 0,
                'second_demarker_oversold' => 10,
                'second_demarker_overbought' => 90,
                'second_demarker_level_delta' => 0,
                'min_candles_in_zone' => 2,
                'second_demarker_confirm_bars' => 1,
                'exit_candle_avg_bars' => 3,
                'max_exit_candle_ratio' => 10.0,
                'streak_len' => 0,
            ]),
        ]);

        $this->assertNotNull($decision);
        $this->assertSame('CALL', $decision['side']);
        $this->assertSame(DemarkerDualFastConfig::VERSION, $decision['decision_version']);
        $this->assertGreaterThanOrEqual(2, $decision['features']['zone_bars']);
        $this->assertSame(1, $decision['features']['knee']);
        $this->assertSame(2, $decision['features']['entries_per_signal']);
    }

    public function test_rejects_when_zone_too_short(): void
    {
        $strategy = new DemarkerDualFastStrategy(new DemarkerIndicatorService);
        $this->assertNull($strategy->analyze($this->callPath(), null, [
            'config' => $this->baseConfig([
                'demarker_period' => 1,
                'second_demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'demarker_level_delta' => 0,
                'second_demarker_oversold' => 10,
                'second_demarker_overbought' => 90,
                'second_demarker_level_delta' => 0,
                // В callPath зона ~2 бара — требуем 10.
                'min_candles_in_zone' => 10,
                'second_demarker_confirm_bars' => 1,
                'exit_candle_avg_bars' => 3,
                'max_exit_candle_ratio' => 10.0,
                'streak_len' => 0,
            ]),
        ]));
    }

    public function test_rejects_rocket_exit_candle(): void
    {
        $strategy = new DemarkerDualFastStrategy(new DemarkerIndicatorService);
        $candles = $this->callPath();
        $last = array_key_last($candles);
        // Раздуваем диапазон сигнальной свечи относительно спокойной истории.
        $candles[$last] = $this->candle(
            (int) $candles[$last]['time'],
            (float) $candles[$last]['open'],
            (float) $candles[$last]['high'] + 50,
            (float) $candles[$last]['low'] - 50,
            (float) $candles[$last]['close'],
        );

        $this->assertNull($strategy->analyze($candles, null, [
            'config' => $this->baseConfig([
                'demarker_period' => 1,
                'second_demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'demarker_level_delta' => 0,
                'second_demarker_oversold' => 10,
                'second_demarker_overbought' => 90,
                'second_demarker_level_delta' => 0,
                'min_candles_in_zone' => 2,
                'second_demarker_confirm_bars' => 1,
                'exit_candle_avg_bars' => 3,
                'max_exit_candle_ratio' => 2.0,
                'streak_len' => 0,
            ]),
        ]));
    }

    public function test_second_demarker_can_confirm_within_window(): void
    {
        $strategy = new DemarkerDualFastStrategy(new DemarkerIndicatorService);
        // confirm_bars=3: при period=1 оба ряда совпадают — подтверждение на том же баре.
        $decision = $strategy->analyze($this->callPath(), null, [
            'config' => $this->baseConfig([
                'demarker_period' => 1,
                'second_demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'demarker_level_delta' => 0,
                'second_demarker_oversold' => 10,
                'second_demarker_overbought' => 90,
                'second_demarker_level_delta' => 0,
                'min_candles_in_zone' => 2,
                'second_demarker_confirm_bars' => 3,
                'exit_candle_avg_bars' => 3,
                'max_exit_candle_ratio' => 10.0,
                'streak_len' => 0,
            ]),
        ]);

        $this->assertNotNull($decision);
        $this->assertSame(0, $decision['features']['second_demarker_confirm_offset']);
    }

    public function test_optional_streak_rejects_wrong_pre_zone_color(): void
    {
        $strategy = new DemarkerDualFastStrategy(new DemarkerIndicatorService);
        $candles = $this->callPath();
        // Бары до зоны делаем медвежьими — streak ломается.
        $candles[4] = $this->candle(120, 10.80, 10.81, 10.60, 10.62);
        $candles[5] = $this->candle(150, 10.62, 10.63, 10.40, 10.45);

        $this->assertNull($strategy->analyze($candles, null, [
            'config' => $this->baseConfig([
                'demarker_period' => 1,
                'second_demarker_period' => 1,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
                'demarker_level_delta' => 0,
                'second_demarker_oversold' => 10,
                'second_demarker_overbought' => 90,
                'second_demarker_level_delta' => 0,
                'min_candles_in_zone' => 2,
                'second_demarker_confirm_bars' => 1,
                'exit_candle_avg_bars' => 3,
                'max_exit_candle_ratio' => 10.0,
                'streak_len' => 2,
            ]),
        ]));
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function baseConfig(array $override): array
    {
        return array_replace(DemarkerDualFastConfig::defaultConfig(), $override);
    }

    /**
     * Рост → слив в перепроданность (≥2 бара) → выход вверх.
     *
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    private function callPath(): array
    {
        $candles = [];
        $t = 0;
        $p = 10.0;
        for ($i = 0; $i < 6; $i++) {
            $open = $p;
            $close = $p + 0.15;
            $candles[] = $this->candle($t, $open, $close + 0.02, $open - 0.01, $close);
            $p = $close;
            $t += 30;
        }
        // Два бара в зоне перепроданности (DeM≈0 при period=1).
        $candles[] = $this->candle($t, $p, $p + 0.01, $p - 0.60, $p - 0.50);
        $t += 30;
        $p -= 0.50;
        $candles[] = $this->candle($t, $p, $p + 0.01, $p - 0.15, $p - 0.10);
        $t += 30;
        $p -= 0.10;
        // Выход вверх.
        $candles[] = $this->candle($t, $p, $p + 0.50, $p - 0.02, $p + 0.20);

        return $candles;
    }

    /** @return array{time:int,open:float,high:float,low:float,close:float,volume:float} */
    private function candle(int $time, float $open, float $high, float $low, float $close): array
    {
        return compact('time', 'open', 'high', 'low', 'close') + ['volume' => 1.0];
    }
}
