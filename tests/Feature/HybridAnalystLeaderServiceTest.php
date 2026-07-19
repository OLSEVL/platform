<?php

namespace Tests\Feature;

use App\Jobs\RunHybridAnalystAnalysisJob;
use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\User;
use App\Services\Bots\HybridAnalystDispatcher;
use App\Services\Bots\HybridAnalystLeaderService;
use App\Support\HybridAnalystConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HybridAnalystLeaderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_picks_higher_winrate_with_min_trades(): void
    {
        $user = User::factory()->create();
        $analyst = $this->makeAnalyst($user, [
            'hybrid_leader_window_sec' => 3600,
            'hybrid_leader_min_trades' => 10,
        ]);
        $weak = $this->makeSource($user, 'weak');
        $strong = $this->makeSource($user, 'strong');

        // weak: 9/9 = 100% but below min trades 10 → не квалифицируется
        $this->seedTrades($weak->id, 9, 0);
        // strong: 8/10 = 80%
        $this->seedTrades($strong->id, 8, 2);

        $leader = app(HybridAnalystLeaderService::class)->computeLeader(
            $analyst,
            is_array($analyst->config) ? $analyst->config : []
        );

        $this->assertSame($strong->id, $leader['bot_id']);
    }

    public function test_keeps_previous_leader_when_no_qualified(): void
    {
        $user = User::factory()->create();
        $prev = $this->makeSource($user, 'prev');
        $analyst = $this->makeAnalyst($user, [
            'hybrid_leader_window_sec' => 3600,
            'hybrid_leader_min_trades' => 10,
            'hybrid_leader_bot_id' => $prev->id,
        ]);
        $other = $this->makeSource($user, 'other');
        $this->seedTrades($other->id, 3, 0); // < 10

        $leader = app(HybridAnalystLeaderService::class)->computeLeader(
            $analyst,
            is_array($analyst->config) ? $analyst->config : []
        );

        $this->assertSame($prev->id, $leader['bot_id']);
    }

    public function test_dispatcher_best_winrate_only_fires_for_leader(): void
    {
        Queue::fake();
        Cache::flush();

        $user = User::factory()->create();
        $leaderBot = $this->makeSource($user, 'leader');
        $other = $this->makeSource($user, 'other');
        $this->seedTrades($leaderBot->id, 10, 0);
        $this->seedTrades($other->id, 5, 5);

        $analyst = $this->makeAnalyst($user, [
            'hybrid_enabled' => true,
            'hybrid_source_mode' => HybridAnalystConfig::SOURCE_MODE_BEST_WINRATE,
            'hybrid_source_bot_id' => $other->id, // fixed id игнорируется в best_winrate
            'hybrid_trigger' => HybridAnalystConfig::TRIGGER_ON_SIGNAL,
            'hybrid_leader_window_sec' => 3600,
            'hybrid_leader_min_trades' => 10,
        ]);

        $dispatcher = app(HybridAnalystDispatcher::class);

        $dispatcher->maybeDispatch(
            $other,
            101,
            'EURUSD_otc',
            'CALL',
            0.7,
            ['analysis_text' => 'x'],
            HybridAnalystConfig::TRIGGER_ON_SIGNAL,
        );
        Queue::assertNothingPushed();

        $dispatcher->maybeDispatch(
            $leaderBot,
            102,
            'EURUSD_otc',
            'PUT',
            0.8,
            ['analysis_text' => 'y'],
            HybridAnalystConfig::TRIGGER_ON_SIGNAL,
        );
        Queue::assertPushed(RunHybridAnalystAnalysisJob::class, function (RunHybridAnalystAnalysisJob $job) use ($analyst, $leaderBot): bool {
            return $job->analystBotId === $analyst->id
                && ($job->sourceSignal['source_bot_id'] ?? null) === $leaderBot->id;
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function makeAnalyst(User $user, array $extra = []): Bot
    {
        return Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => array_merge([
                'hybrid_enabled' => true,
                'hybrid_source_mode' => HybridAnalystConfig::SOURCE_MODE_BEST_WINRATE,
                'hybrid_trigger' => HybridAnalystConfig::TRIGGER_ON_SIGNAL,
                'hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS,
            ], $extra),
        ]);
    }

    private function makeSource(User $user, string $name): Bot
    {
        return Bot::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => 'strong_level',
            'is_active' => true,
            'config' => [],
        ]);
    }

    private function seedTrades(int $botId, int $wins, int $losses): void
    {
        $now = now();
        for ($i = 0; $i < $wins; $i++) {
            BotTrade::query()->create([
                'bot_id' => $botId,
                'instrument' => 'EURUSD_otc',
                'side' => 'CALL',
                'status' => BotTrade::STATUS_SETTLED,
                'result' => BotTrade::RESULT_WIN,
                'entry_time' => $now->copy()->subMinutes(10 + $i),
                'expiry_time' => $now->copy()->subMinutes(5 + $i),
                'entry_price' => 1.1,
            ]);
        }
        for ($i = 0; $i < $losses; $i++) {
            BotTrade::query()->create([
                'bot_id' => $botId,
                'instrument' => 'GBPUSD_otc',
                'side' => 'PUT',
                'status' => BotTrade::STATUS_SETTLED,
                'result' => BotTrade::RESULT_LOSS,
                'entry_time' => $now->copy()->subMinutes(20 + $i),
                'expiry_time' => $now->copy()->subMinutes(15 + $i),
                'entry_price' => 1.2,
            ]);
        }
    }
}
