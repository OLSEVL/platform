<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use App\Services\Bots\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterModelsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_lists_models_with_prices(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'test/model-a',
                        'name' => 'Test Model A',
                        'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Analyst Test',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => ['model' => 'test/model-a'],
        ]);

        $response = $this->actingAs($user)->get(route('bots.settings', $bot));

        $response->assertOk();
        $response->assertSee('Test Model A — test/model-a · in $1/M · out $2/M', false);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/models'));
    }

    public function test_settings_fetches_catalog_on_each_visit(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'new/model',
                        'name' => 'New Model',
                        'pricing' => ['prompt' => '0', 'completion' => '0'],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'LLM Test',
            'type' => 'openrouter_llm',
            'is_active' => false,
            'config' => ['model' => 'new/model'],
        ]);

        $this->actingAs($user)->get(route('bots.settings', $bot))->assertOk();
        $this->actingAs($user)->get(route('bots.settings', $bot))->assertOk();

        Http::assertSentCount(2);
    }

    public function test_model_select_label_works_from_pricing_array(): void
    {
        $label = OpenRouterClient::modelSelectLabel([
            'id' => 'x/y',
            'name' => 'X Y',
            'pricing' => ['prompt' => 0.000001, 'completion' => 0.000002],
        ]);

        $this->assertStringContainsString('in $1/M', $label);
        $this->assertStringContainsString('out $2/M', $label);
    }
}
