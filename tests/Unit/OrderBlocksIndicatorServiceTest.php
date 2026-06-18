<?php

namespace Tests\Unit;

use App\Services\Indicators\OrderBlocksIndicatorService;
use PHPUnit\Framework\TestCase;

class OrderBlocksIndicatorServiceTest extends TestCase
{
    public function test_apply_styles_adds_fill_and_border_to_order_block_zones(): void
    {
        $zones = [
            [
                'type' => 'order_block_bull',
                'time_start' => 1,
                'time_end' => 2,
                'price_low' => 1.0,
                'price_high' => 1.1,
                'formed_at' => 1,
            ],
            [
                'type' => 'imbalance_bull',
                'time_start' => 1,
                'time_end' => 2,
                'price_low' => 1.0,
                'price_high' => 1.1,
                'formed_at' => 1,
            ],
        ];

        $styled = (new OrderBlocksIndicatorService)->applyStylesToZones($zones);

        $this->assertArrayHasKey('fill', $styled[0]);
        $this->assertArrayHasKey('border', $styled[0]);
        $this->assertSame('Бычий ордерблок', $styled[0]['label']);
        $this->assertArrayNotHasKey('fill', $styled[1]);
    }

    public function test_breaker_zone_uses_dashed_border(): void
    {
        $zones = [[
            'type' => 'order_block_bull_break',
            'time_start' => 1,
            'time_end' => 2,
            'price_low' => 1.0,
            'price_high' => 1.1,
            'formed_at' => 1,
        ]];

        $styled = (new OrderBlocksIndicatorService)->applyStylesToZones($zones);

        $this->assertStringContainsString('dashed', $styled[0]['border']);
    }
}
