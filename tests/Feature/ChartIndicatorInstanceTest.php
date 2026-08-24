<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\ChartIndicatorInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartIndicatorInstanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeBot(User $user): Bot
    {
        return Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart pilot',
            'type' => 'rsi_trade',
            'is_active' => true,
            'config' => [],
        ]);
    }

    public function test_owner_can_create_list_and_delete_instance(): void
    {
        $user = User::factory()->create();
        $bot = $this->makeBot($user);

        $create = $this->actingAs($user)->postJson(route('bots.chart-instances.store', $bot), [
            'type' => 'sma',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('instance.id');
        $this->assertGreaterThan(0, $id);

        $list = $this->actingAs($user)->getJson(route('bots.chart-instances.index', $bot));
        $list->assertOk();
        $this->assertCount(1, $list->json('instances'));

        $delete = $this->actingAs($user)->deleteJson(route('bots.chart-instances.destroy', [$bot, $id]));
        $delete->assertOk();
        $this->assertDatabaseMissing('chart_indicator_instances', ['id' => $id]);
    }

    public function test_create_rejects_unsupported_type(): void
    {
        $user = User::factory()->create();
        $bot = $this->makeBot($user);

        $create = $this->actingAs($user)->postJson(route('bots.chart-instances.store', $bot), [
            'type' => 'order-blocks',
        ]);
        $create->assertUnprocessable();
    }

    public function test_can_create_rsi_instance(): void
    {
        $user = User::factory()->create();
        $bot = $this->makeBot($user);

        $create = $this->actingAs($user)->postJson(route('bots.chart-instances.store', $bot), [
            'type' => 'rsi',
        ]);
        $create->assertCreated();
        $this->assertSame('rsi', $create->json('instance.type'));
    }

    public function test_indicators_data_returns_indicator_instances_payload(): void
    {
        $user = User::factory()->create();
        $bot = $this->makeBot($user);
        $instance = ChartIndicatorInstance::query()->create([
            'bot_id' => $bot->id,
            'indicator_type' => 'sma',
            'settings' => ['length' => 9, 'candle_point' => 'Close', 'show_line' => true, 'color' => '#ff0', 'line_width' => 1],
            'visible' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)->getJson(route('bots.indicators.data', $bot).'?'.http_build_query([
            'instrument' => 'EURUSD_otc',
            'timeframe' => 1,
            'instances' => [$instance->id],
        ]));

        $response->assertOk();
        $rows = $response->json('indicator_instances');
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame($instance->id, $rows[0]['id']);
        $this->assertSame('sma', $rows[0]['type']);
    }

    public function test_sync_trading_endpoint_creates_dual_demarker_instances(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Dual DeMarker Fast',
            'type' => \App\Support\DemarkerDualFastConfig::BOT_TYPE,
            'is_active' => true,
            'config' => \App\Support\DemarkerDualFastConfig::defaultConfig(),
        ]);

        $response = $this->actingAs($user)->postJson(route('bots.chart-instances.sync-trading', $bot));
        $response->assertOk();
        $instances = $response->json('instances');
        $this->assertCount(2, $instances);
        $this->assertDatabaseCount('chart_indicator_instances', 2);
    }
}
