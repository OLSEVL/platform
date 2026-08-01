<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Bots\BotEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpenRouterLlmAnalystTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_bot_does_nothing_for_analyst(): void
    {
        $bot = $this->makeAnalystBot();

        $summary = app(BotEngineService::class)->scanBot($bot->fresh());

        $this->assertSame(['processed' => 0, 'signals' => 0, 'trades' => 0], $summary);
    }

    public function test_manual_analysis_returns_free_text_and_logs(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            $this->markTestSkipped('bot_llm_logs table missing');
        }

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => "H1: flat; M15: range; анализ тестовый.\n\n```chart_levels\n".json_encode([
                        'levels' => [
                            ['price' => 1.1, 'kind' => 'support', 'timeframe' => 'H1', 'label' => 'H1 support'],
                        ],
                        'zones' => [],
                        'entry' => null,
                    ], JSON_UNESCAPED_UNICODE)."\n```"]],
                ],
                'usage' => [
                    'prompt_tokens' => 7,
                    'completion_tokens' => 3,
                    'total_tokens' => 10,
                    'cost' => 0.0012,
                    'cost_details' => [
                        'upstream_inference_prompt_cost' => 0.0009,
                        'upstream_inference_completions_cost' => 0.0003,
                    ],
                ],
            ], 200),
            'https://openrouter.ai/api/v1/credits' => Http::response([
                'data' => ['total_credits' => 10, 'total_usage' => 7.5],
            ], 200),
        ]);

        $user = User::factory()->create();
        $bot = $this->makeAnalystBot($user);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'display_name' => 'EUR/USD',
            'is_active' => true,
            'priority' => 1,
            'current_payout' => 85.0,
        ]);

        $this->actingAs($user);

        $response = $this->getJson(route('bots.analysis.data', $bot).'?'.http_build_query([
            'instrument' => 'EURUSD',
            'user_question' => 'Есть ли сетап на отбой?',
        ]));

        $response->assertOk();
        $response->assertJsonFragment(['summary' => 'Анализ готов.']);
        $response->assertJsonPath('analysis_text', 'H1: flat; M15: range; анализ тестовый.');
        $response->assertJsonPath('chart_levels_ok', true);
        $response->assertJsonPath('chart_levels.levels.0.price', 1.1);
        $response->assertJsonPath('chart_levels.levels.0.kind', 'support');
        $response->assertJsonPath('user_question', 'Есть ли сетап на отбой?');
        $response->assertJsonPath('billing.request_cost_usd', 0.0012);
        $response->assertJsonPath('billing.total_cost_usd', 0.0012);
        $response->assertJsonPath('billing.prompt_cost_usd', 0.0009);
        $response->assertJsonPath('billing.completion_cost_usd', 0.0003);
        $response->assertJsonPath('billing.balance_remaining_usd', 2.5);
        $response->assertJsonPath('billing.total_tokens', 10);
        $response->assertJsonPath('billing.prompt_tokens', 7);
        $response->assertJsonPath('billing.completion_tokens', 3);
        $response->assertJsonFragment([
            'billing_label' => 'Вход: $0.0009 · Выход: $0.0003 · Итого: $0.0012 · Токены: 10 ( prompt tokens: 7 + completion tokens: 3 ) · Баланс: $2.5',
        ]);

        $this->assertDatabaseHas('bot_llm_logs', [
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD',
            'status' => 'ok',
            'response_text' => 'H1: flat; M15: range; анализ тестовый.',
        ]);

        if (Schema::hasColumn('bot_llm_logs', 'chart_levels_json')) {
            $log = \Illuminate\Support\Facades\DB::table('bot_llm_logs')
                ->where('bot_id', $bot->id)
                ->where('instrument', 'EURUSD')
                ->latest('id')
                ->first();
            $this->assertNotNull($log);
            $levels = json_decode((string) ($log->chart_levels_json ?? ''), true);
            $this->assertIsArray($levels);
            $this->assertSame(1.1, $levels['levels'][0]['price']);
        }

        $log = \Illuminate\Support\Facades\DB::table('bot_llm_logs')
            ->where('bot_id', $bot->id)
            ->where('instrument', 'EURUSD')
            ->latest('id')
            ->first();
        $this->assertNotNull($log);
        $request = json_decode((string) ($log->request_json ?? ''), true);
        $payload = $request['payload'] ?? [];
        $this->assertArrayHasKey('closed_candles_s5', $payload);
        $this->assertArrayHasKey('partial_candle_s5', $payload);
        $this->assertArrayHasKey('smc_flow_summary', $payload);
        $this->assertSame([3, 6], $payload['expiry_focus_min'] ?? null);

        $response->assertJsonStructure(['smc_flow' => ['lines', 'areas', 'labels', 'modules']]);
        $this->assertStringContainsString('--- system ---', (string) ($log->request_text ?? ''));
        $this->assertStringContainsString('smc_flow_summary', (string) ($log->request_text ?? ''));
        $this->assertStringContainsString('--- user ---', (string) ($log->request_text ?? ''));
    }

    public function test_activity_page_works_for_analyst(): void
    {
        $user = User::factory()->create();
        $bot = $this->makeAnalystBot($user);

        $this->actingAs($user)
            ->get(route('bots.activity', $bot))
            ->assertOk();
    }

    public function test_cards_page_works_for_analyst(): void
    {
        $user = User::factory()->create();
        $bot = $this->makeAnalystBot($user);

        $this->actingAs($user)
            ->get(route('bots.cards', $bot))
            ->assertOk();
    }

    public function test_classic_analyst_omits_smc_flow_from_llm_payload(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            $this->markTestSkipped('bot_llm_logs table missing');
        }

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Классический анализ без SMC.']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7, 'cost' => 0.0001],
            ], 200),
            'https://openrouter.ai/api/v1/credits' => Http::response([
                'data' => ['total_credits' => 10, 'total_usage' => 0],
            ], 200),
        ]);

        $user = User::factory()->create();
        $bot = $this->makeAnalystBot($user, false);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'display_name' => 'EUR/USD',
            'is_active' => true,
            'priority' => 1,
            'current_payout' => 85.0,
        ]);

        $this->actingAs($user)->getJson(route('bots.analysis.data', $bot).'?instrument=EURUSD')
            ->assertOk()
            ->assertJsonPath('analyst_smc_flow_enabled', false)
            ->assertJsonPath('smc_flow', null);

        $log = \Illuminate\Support\Facades\DB::table('bot_llm_logs')
            ->where('bot_id', $bot->id)
            ->latest('id')
            ->first();
        $request = json_decode((string) ($log->request_json ?? ''), true);
        $payload = $request['payload'] ?? [];
        $this->assertArrayNotHasKey('smc_flow_summary', $payload);
    }

    public function test_short_prompt_mode_returns_verdict_without_chart_levels(): void
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            $this->markTestSkipped('bot_llm_logs table missing');
        }

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => "Рекомендация: CALL\nВероятность успеха: 68%\nПричина: MTF согласован."]]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7, 'cost' => 0.0001],
            ], 200),
            'https://openrouter.ai/api/v1/credits' => Http::response([
                'data' => ['total_credits' => 10, 'total_usage' => 0],
            ], 200),
        ]);

        $user = User::factory()->create();
        $bot = $this->makeAnalystBot($user, false);
        $config = is_array($bot->config) ? $bot->config : [];
        $config['analyst_prompt_mode'] = 'short';
        $config['system_prompt_short'] = \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortClassic();
        $bot->config = $config;
        $bot->save();

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'display_name' => 'EUR/USD',
            'is_active' => true,
            'priority' => 1,
            'current_payout' => 85.0,
        ]);

        $this->actingAs($user)->getJson(route('bots.analysis.data', $bot).'?instrument=EURUSD')
            ->assertOk()
            ->assertJsonPath('analyst_prompt_mode', 'short')
            ->assertJsonPath('chart_levels_ok', false)
            ->assertJsonPath('analysis_text', "Рекомендация: CALL\nВероятность успеха: 68%\nПричина: MTF согласован.");

        $log = \Illuminate\Support\Facades\DB::table('bot_llm_logs')
            ->where('bot_id', $bot->id)
            ->latest('id')
            ->first();
        $request = json_decode((string) ($log->request_json ?? ''), true);
        $this->assertSame('short', $request['analyst_prompt_mode'] ?? null);
        $this->assertStringContainsString('краткий вердикт', (string) ($log->request_text ?? ''));
    }

    private function makeAnalystBot(?User $user = null, bool $smcFlowEnabled = true): Bot
    {
        $user ??= User::factory()->create();

        return Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [
                'count' => 10,
                's5_take' => 24,
                'model' => 'openai/gpt-5.2',
                'temperature' => 0,
                'max_tokens' => 200,
                'mtf_enabled' => false,
                'analyst_smc_flow_enabled' => $smcFlowEnabled,
                'system_prompt' => $smcFlowEnabled
                    ? \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptWithSmcFlow()
                    : \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptClassic(),
                'system_prompt_short' => $smcFlowEnabled
                    ? \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortWithSmcFlow()
                    : \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortClassic(),
                'analyst_prompt_mode' => 'full',
                'openrouter_timeout_sec' => 120,
                'version' => 'openrouter_llm_analyst_v1',
            ],
        ]);
    }
}
