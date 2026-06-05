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
            'request_cost_usd' => 0.00665394,
            'total_tokens' => 1550,
            'prompt_tokens' => 1200,
            'completion_tokens' => 350,
            'balance_remaining_usd' => 2.843,
        ]);

        $this->assertStringContainsString('Запрос:', $label);
        $this->assertStringContainsString('Токены:', $label);
        $this->assertStringContainsString('1 550', $label);
        $this->assertStringContainsString('prompt tokens: 1 200', $label);
        $this->assertStringContainsString('completion tokens: 350', $label);
        $this->assertStringContainsString('Баланс:', $label);
    }
}
