<?php

namespace Tests\Unit;

use App\Services\Bots\OpenRouterClient;
use PHPUnit\Framework\TestCase;

class OpenRouterClientPricingTest extends TestCase
{
    public function test_format_pricing_label_for_paid_model(): void
    {
        $label = OpenRouterClient::formatPricingLabel([
            'prompt' => 0.00000015,
            'completion' => 0.0000006,
        ]);

        $this->assertSame('in $0.15/M · out $0.6/M', $label);
    }

    public function test_format_pricing_label_for_free_model(): void
    {
        $this->assertSame('бесплатно', OpenRouterClient::formatPricingLabel([
            'prompt' => 0.0,
            'completion' => 0.0,
        ]));
    }

    public function test_model_select_label_includes_pricing(): void
    {
        $label = OpenRouterClient::modelSelectLabel([
            'id' => 'openai/gpt-4o-mini',
            'name' => 'GPT-4o Mini',
            'pricing_label' => 'in $0.15/M · out $0.6/M',
        ]);

        $this->assertSame('GPT-4o Mini — openai/gpt-4o-mini · in $0.15/M · out $0.6/M', $label);
    }

    public function test_list_models_parses_pricing_from_api_shape(): void
    {
        $pricing = OpenRouterClient::normalizePricing([
            'prompt' => '0.0000004',
            'completion' => '0.0000016',
            'input_cache_read' => '0.00000008',
        ]);

        $this->assertSame(0.0000004, $pricing['prompt']);
        $this->assertSame(0.0000016, $pricing['completion']);
        $this->assertSame('in $0.4/M · out $1.6/M', OpenRouterClient::formatPricingLabel($pricing));
    }
}
