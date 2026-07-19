<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Bots\BotDataService;
use App\Services\Bots\HybridAnalystTradeService;
use App\Support\HybridAnalystConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HybridAnalystTradeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_opens_paper_trade_with_llm_side_and_source_expiry(): void
    {
        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Hybrid Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => [
                'hybrid_enabled' => true,
                'hybrid_paper_trades_enabled' => true,
                'hybrid_mirror_trades_enabled' => false,
                'mirror_trades_enabled' => false,
                'hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS,
                'hybrid_min_success_percent' => 0,
            ],
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
            'priority' => 1,
            'current_payout' => 88.0,
        ]);

        $data = Mockery::mock(BotDataService::class);
        $data->shouldReceive('series')->andReturn([
            'closed' => [['close' => 1.1001, 'time' => time() - 60]],
            'partial' => ['close' => 1.1005, 'time' => time()],
            'series' => [],
        ]);
        $this->app->instance(BotDataService::class, $data);

        $cfg = is_array($analyst->config) ? $analyst->config : [];
        $result = app(HybridAnalystTradeService::class)->maybeOpenTrade(
            $analyst,
            'EURUSD_otc',
            "Рекомендация: PUT\nВероятность успеха: 71%",
            $cfg,
            [
                'source_bot_id' => 9,
                'source_signal_id' => 55,
                'source_bot_type' => 'strong_level',
                'side' => 'CALL',
                'expiry_sec' => 240,
                'confidence' => 0.6,
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('bot_signals', [
            'bot_id' => $analyst->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'PUT',
            'decision_version' => 'hybrid_analyst_v1',
        ]);
        $trade = BotTrade::query()->where('bot_id', $analyst->id)->first();
        $this->assertNotNull($trade);
        $this->assertSame('PUT', $trade->side);
        $this->assertSame(240, (int) ($trade->meta_json['expiry_sec_used'] ?? 0));
        $this->assertFalse((bool) ($result['mirrored'] ?? false));
    }

    public function test_skips_when_paper_disabled(): void
    {
        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Hybrid Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => [
                'hybrid_paper_trades_enabled' => false,
                'hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS,
            ],
        ]);

        $result = app(HybridAnalystTradeService::class)->maybeOpenTrade(
            $analyst,
            'EURUSD_otc',
            "Рекомендация: CALL\nВероятность успеха: 80%",
            is_array($analyst->config) ? $analyst->config : [],
            ['source_signal_id' => 1, 'side' => 'CALL', 'expiry_sec' => 180],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('filters_or_paper_disabled', $result['skipped']);
        $this->assertDatabaseCount('bot_trades', 0);
    }
}
