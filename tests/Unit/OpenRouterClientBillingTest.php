<?php

namespace Tests\Unit;

use App\Services\Bots\OpenRouterClient;
use Tests\TestCase;

class OpenRouterClientBillingTest extends TestCase
{
    public function test_parse_request_cost_from_usage(): void
    {
        $cost = OpenRouterClient::parseRequestCostUsd([
            'cost' => 0.00665394,
            'total_tokens' => 100,
        ]);

        $this->assertEqualsWithDelta(0.00665394, $cost, 0.0000001);
    }

    public function test_parse_usage_costs_with_prompt_and_completion_breakdown(): void
    {
        $costs = OpenRouterClient::parseUsageCosts([
            'cost' => 0.0012,
            'cost_details' => [
                'upstream_inference_prompt_cost' => 0.0009,
                'upstream_inference_completions_cost' => 0.0003,
            ],
        ]);

        $this->assertEqualsWithDelta(0.0012, $costs['total_cost_usd'], 0.0000001);
        $this->assertEqualsWithDelta(0.0009, $costs['prompt_cost_usd'], 0.0000001);
        $this->assertEqualsWithDelta(0.0003, $costs['completion_cost_usd'], 0.0000001);
    }

    public function test_parse_request_tokens_from_usage(): void
    {
        $tokens = OpenRouterClient::parseRequestTokens([
            'prompt_tokens' => 1200,
            'completion_tokens' => 350,
            'total_tokens' => 1550,
        ]);

        $this->assertSame(1200, $tokens['prompt_tokens']);
        $this->assertSame(350, $tokens['completion_tokens']);
        $this->assertSame(1550, $tokens['total_tokens']);
    }

    public function test_parse_request_tokens_sums_prompt_and_completion_when_total_missing(): void
    {
        $tokens = OpenRouterClient::parseRequestTokens([
            'prompt_tokens' => 100,
            'completion_tokens' => 25,
        ]);

        $this->assertSame(125, $tokens['total_tokens']);
    }

    public function test_format_billing_label(): void
    {
        $label = OpenRouterClient::formatBillingLabel([
            'total_cost_usd' => 0.00665394,
            'prompt_cost_usd' => 0.001,
            'completion_cost_usd' => 0.00565394,
            'total_tokens' => 1550,
            'prompt_tokens' => 1200,
            'completion_tokens' => 350,
            'balance_remaining_usd' => 2.843,
        ]);

        $this->assertStringContainsString('Вход:', $label);
        $this->assertStringContainsString('Выход:', $label);
        $this->assertStringContainsString('Итого:', $label);
        $this->assertStringContainsString('Токены:', $label);
        $this->assertStringContainsString('1 550', $label);
        $this->assertStringContainsString('prompt tokens: 1 200', $label);
        $this->assertStringContainsString('completion tokens: 350', $label);
        $this->assertStringContainsString('Баланс:', $label);
    }

    public function test_format_billing_label_falls_back_to_total_only(): void
    {
        $label = OpenRouterClient::formatBillingLabel([
            'request_cost_usd' => 0.0012,
            'total_tokens' => 10,
        ]);

        $this->assertStringContainsString('Итого анализа:', $label);
        $this->assertStringNotContainsString('Вход:', $label);
    }
}
