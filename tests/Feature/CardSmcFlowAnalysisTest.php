<?php

namespace Tests\Feature;

use App\Jobs\RunCardSmcFlowAnalysisJob;
use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\User;
use App\Services\Bots\BotEngineService;
use App\Services\Telegram\StrongLevelTelegramLlmButton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CardSmcFlowAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_page_renders_analysis_modal(): void
    {
        [$user, $bot] = $this->makeSourceBotWithAnalyst();

        $this->actingAs($user)
            ->get(route('bots.cards', $bot))
            ->assertOk()
            ->assertSee('Доп. анализ нейросетью', false)
            ->assertSee('smc-analysis-modal', false)
            ->assertDontSee('card-smc-panel', false);
    }

    public function test_cards_data_includes_analysis_url_when_analyst_exists(): void
    {
        [$user, $bot, $trade] = $this->makeOpenCardTrade();

        $this->actingAs($user)
            ->getJson(route('bots.cards.data', $bot))
            ->assertOk()
            ->assertJsonPath('cards.0.smc_flow_analysis_enabled', true)
            ->assertJsonPath(
                'cards.0.smc_flow_analysis_url',
                route('bots.cards.smc-flow-analysis.start', ['bot' => $bot, 'trade' => $trade], false)
            );
    }

    public function test_cards_data_hides_analysis_without_analyst(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Level',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => ['telegram_analyst_llm_enabled' => true],
        ]);
        $this->makeTrade($bot);

        $this->actingAs($user)
            ->getJson(route('bots.cards.data', $bot))
            ->assertOk()
            ->assertJsonPath('cards.0.smc_flow_analysis_enabled', false)
            ->assertJsonPath('cards.0.smc_flow_analysis_url', null);
    }

    public function test_start_dispatches_job_and_status_returns_text(): void
    {
        Queue::fake();
        [$user, $bot, $trade, $analyst] = $this->makeOpenCardTrade();

        $start = $this->actingAs($user)
            ->postJson(route('bots.cards.smc-flow-analysis.start', ['bot' => $bot, 'trade' => $trade]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'pending');

        $token = $start->json('token');
        $this->assertNotEmpty($token);

        Queue::assertPushed(RunCardSmcFlowAnalysisJob::class, function (RunCardSmcFlowAnalysisJob $job) use ($analyst, $token) {
            return $job->analystBotId === $analyst->id
                && $job->instrument === 'EURUSD_otc'
                && $job->token === $token
                && $job->userQuestion === StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION;
        });

        $this->mock(BotEngineService::class, function ($mock) {
            $mock->shouldReceive('runManualChartAnalysis')
                ->once()
                ->andReturn([
                    'analysis_text' => 'CALL, вероятность 71%.',
                    'summary' => 'Анализ готов.',
                    'error' => null,
                    'billing_label' => '$0.01',
                    'model' => 'test-model',
                ]);
        });

        $job = new RunCardSmcFlowAnalysisJob($analyst->id, 'EURUSD_otc', StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION, $token);
        $job->handle(app(BotEngineService::class), app(\App\Support\CardSmcFlowAnalysisStore::class));

        $this->actingAs($user)
            ->getJson(route('bots.cards.smc-flow-analysis.status', ['bot' => $bot, 'token' => $token]))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('analysis_text', 'CALL, вероятность 71%.')
            ->assertJsonPath('billing_label', '$0.01');
    }

    public function test_second_start_dispatches_new_job_not_cached_result(): void
    {
        Queue::fake();
        [$user, $bot, $trade] = $this->makeOpenCardTrade();

        $first = $this->actingAs($user)
            ->postJson(route('bots.cards.smc-flow-analysis.start', ['bot' => $bot, 'trade' => $trade]))
            ->assertOk()
            ->json('token');

        $second = $this->actingAs($user)
            ->postJson(route('bots.cards.smc-flow-analysis.start', ['bot' => $bot, 'trade' => $trade]))
            ->assertOk()
            ->json('token');

        $this->assertNotSame($first, $second);
        Queue::assertPushed(RunCardSmcFlowAnalysisJob::class, 2);
    }

    public function test_start_forbidden_for_other_user(): void
    {
        [, $bot, $trade] = $this->makeOpenCardTrade();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson(route('bots.cards.smc-flow-analysis.start', ['bot' => $bot, 'trade' => $trade]))
            ->assertForbidden();
    }

    public function test_start_unavailable_when_llm_disabled(): void
    {
        $user = User::factory()->create();
        Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [],
        ]);
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Level',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => ['telegram_analyst_llm_enabled' => false],
        ]);
        $trade = $this->makeTrade($bot);

        $this->actingAs($user)
            ->postJson(route('bots.cards.smc-flow-analysis.start', ['bot' => $bot, 'trade' => $trade]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'smc_flow_analysis_unavailable');
    }

    /**
     * @return array{0:User,1:Bot}
     */
    private function makeSourceBotWithAnalyst(): array
    {
        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [],
        ]);
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Level',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => ['telegram_analyst_llm_enabled' => true],
        ]);

        return [$user, $bot, $analyst];
    }

    /**
     * @return array{0:User,1:Bot,2:BotTrade,3:Bot}
     */
    private function makeOpenCardTrade(): array
    {
        [$user, $bot, $analyst] = $this->makeSourceBotWithAnalyst();
        $trade = $this->makeTrade($bot);

        return [$user, $bot, $trade, $analyst];
    }

    private function makeTrade(Bot $bot): BotTrade
    {
        $signal = BotSignal::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'signal_at' => now()->subMinute(),
            'expiry_time' => now()->addMinutes(3),
            'dedupe_key' => uniqid('card-smc:', true),
        ]);

        return BotTrade::query()->create([
            'bot_id' => $bot->id,
            'bot_signal_id' => $signal->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now()->subMinute(),
            'expiry_time' => now()->addMinutes(3),
            'entry_price' => 1.08500,
            'payout_snapshot' => 90.0,
        ]);
    }
}
