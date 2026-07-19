<?php

namespace Tests\Feature;

use App\Jobs\RunHybridAnalystAnalysisJob;
use App\Models\Bot;
use App\Models\User;
use App\Services\Bots\HybridAnalystDispatcher;
use App\Support\HybridAnalystConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HybridAnalystDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_when_hybrid_matches_on_signal(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $source = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Source SL',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => ['mirror_trades_enabled' => true],
        ]);
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => [
                'hybrid_enabled' => true,
                'hybrid_source_bot_id' => $source->id,
                'hybrid_trigger' => HybridAnalystConfig::TRIGGER_ON_SIGNAL,
                'hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS,
                'telegram_enabled' => true,
                'telegram_chat_id' => '1',
            ],
        ]);

        app(HybridAnalystDispatcher::class)->maybeDispatch(
            $source,
            42,
            'EURUSD_otc',
            'CALL',
            0.7,
            ['analysis_text' => 'test', 'features' => ['x' => 1], 'reasons_short' => []],
            HybridAnalystConfig::TRIGGER_ON_SIGNAL,
        );

        Queue::assertPushed(RunHybridAnalystAnalysisJob::class, function (RunHybridAnalystAnalysisJob $job) use ($analyst): bool {
            return $job->analystBotId === $analyst->id
                && $job->instrument === 'EURUSD_otc'
                && ($job->sourceSignal['source_signal_id'] ?? null) === 42
                && ($job->sourceSignal['side'] ?? null) === 'CALL';
        });
    }

    public function test_skips_on_po_mirror_when_source_mirror_disabled(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $source = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Source',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => ['mirror_trades_enabled' => false],
        ]);
        Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => [
                'hybrid_enabled' => true,
                'hybrid_source_bot_id' => $source->id,
                'hybrid_trigger' => HybridAnalystConfig::TRIGGER_ON_PO_MIRROR,
                'hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS,
            ],
        ]);

        app(HybridAnalystDispatcher::class)->maybeDispatch(
            $source,
            7,
            'EURUSD_otc',
            'PUT',
            0.6,
            ['analysis_text' => 'x'],
            HybridAnalystConfig::TRIGGER_ON_PO_MIRROR,
            100,
        );

        Queue::assertNothingPushed();
    }

    public function test_does_not_dispatch_for_wrong_trigger(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $source = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Source',
            'type' => 'candle_reversal_confirm',
            'is_active' => true,
            'config' => ['mirror_trades_enabled' => true],
        ]);
        Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => [
                'hybrid_enabled' => true,
                'hybrid_source_bot_id' => $source->id,
                'hybrid_trigger' => HybridAnalystConfig::TRIGGER_ON_SIGNAL,
                'hybrid_publish' => HybridAnalystConfig::PUBLISH_ALWAYS,
            ],
        ]);

        app(HybridAnalystDispatcher::class)->maybeDispatch(
            $source,
            1,
            'GBPUSD_otc',
            'CALL',
            0.8,
            [],
            HybridAnalystConfig::TRIGGER_ON_PO_MIRROR,
            2,
        );

        Queue::assertNothingPushed();
    }
}
