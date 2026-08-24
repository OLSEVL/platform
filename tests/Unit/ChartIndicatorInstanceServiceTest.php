<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\ChartIndicatorInstance;
use App\Models\User;
use App\Services\ChartIndicatorInstanceService;
use App\Services\Indicators\EmaIndicatorService;
use App\Services\Indicators\SmaIndicatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartIndicatorInstanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeBot(): Bot
    {
        $user = User::factory()->create();

        return Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Pilot bot',
            'type' => 'rsi_trade',
            'is_active' => true,
            'config' => [],
        ]);
    }

    public function test_create_assigns_distinct_default_length_and_color(): void
    {
        $bot = $this->makeBot();
        $service = new ChartIndicatorInstanceService();

        $first = $service->create($bot, 'sma');
        $second = $service->create($bot, 'sma');

        $this->assertSame(9, (int) ($first['settings']['length'] ?? 0));
        $this->assertSame(21, (int) ($second['settings']['length'] ?? 0));
        $this->assertNotSame($first['settings']['color'], $second['settings']['color']);
    }

    public function test_compute_for_chart_returns_series_for_visible_instance(): void
    {
        $bot = $this->makeBot();
        $instance = ChartIndicatorInstance::query()->create([
            'bot_id' => $bot->id,
            'indicator_type' => 'ema',
            'settings' => array_replace(EmaIndicatorService::defaultParams(), ['length' => 5]),
            'visible' => true,
            'sort_order' => 0,
        ]);
        $service = new ChartIndicatorInstanceService();
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = [
                'time' => 1_700_000_000 + $i * 60,
                'open' => 1.0 + $i * 0.01,
                'high' => 1.05 + $i * 0.01,
                'low' => 0.95 + $i * 0.01,
                'close' => 1.02 + $i * 0.01,
            ];
        }

        $out = $service->computeForChart($bot, [$instance->id], $rows);

        $this->assertCount(1, $out);
        $this->assertSame($instance->id, $out[0]['id']);
        $this->assertSame('ema', $out[0]['type']);
        $this->assertNotEmpty($out[0]['series']['ema'] ?? []);
    }

    public function test_compute_skips_hidden_instances(): void
    {
        $bot = $this->makeBot();
        $instance = ChartIndicatorInstance::query()->create([
            'bot_id' => $bot->id,
            'indicator_type' => 'sma',
            'settings' => SmaIndicatorService::defaultParams(),
            'visible' => false,
            'sort_order' => 0,
        ]);
        $service = new ChartIndicatorInstanceService();

        $out = $service->computeForChart($bot, [$instance->id], []);

        $this->assertSame([], $out);
    }

    public function test_sync_trading_creates_two_demarker_instances_for_dual_fast_bot(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Dual DeMarker',
            'type' => \App\Support\DemarkerDualFastConfig::BOT_TYPE,
            'is_active' => true,
            'config' => \App\Support\DemarkerDualFastConfig::defaultConfig(),
        ]);
        $service = new ChartIndicatorInstanceService();

        $rows = $service->syncTradingInstances($bot);

        $this->assertCount(2, $rows);
        $this->assertSame('demarker', $rows[0]['type']);
        $this->assertSame('demarker', $rows[1]['type']);
        $this->assertSame(10, (int) ($rows[0]['settings']['period'] ?? 0));
        $this->assertSame(5, (int) ($rows[1]['settings']['period'] ?? 0));
    }

    public function test_sync_trading_creates_rsi_and_sma_for_contrtrend_bot(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'RSI Contrtrend',
            'type' => 'rsi_contrtrend',
            'is_active' => true,
            'config' => [
                'rsi_length' => 9,
                'rsi_up' => 72,
                'rsi_down' => 28,
                'sma_length' => 14,
            ],
        ]);
        $service = new ChartIndicatorInstanceService();

        $rows = $service->syncTradingInstances($bot);

        $this->assertCount(2, $rows);
        $this->assertSame('rsi', $rows[0]['type']);
        $this->assertSame('sma', $rows[1]['type']);
        $this->assertSame(9, (int) ($rows[0]['settings']['length'] ?? 0));
        $this->assertSame(14, (int) ($rows[1]['settings']['length'] ?? 0));
    }

    public function test_sync_trading_creates_single_demarker_for_strong_trend(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong DeMarker',
            'type' => 'strong_trend_demarker',
            'is_active' => true,
            'config' => [
                'demarker_period' => 6,
                'demarker_oversold' => 10,
                'demarker_overbought' => 90,
            ],
        ]);
        $service = new ChartIndicatorInstanceService();

        $rows = $service->syncTradingInstances($bot);

        $this->assertCount(1, $rows);
        $this->assertSame('demarker', $rows[0]['type']);
        $this->assertSame(6, (int) ($rows[0]['settings']['period'] ?? 0));
    }
}
