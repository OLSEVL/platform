<?php

namespace Tests\Unit;

use App\Services\Bots\AuthorFlow\AuthorFlowRanker;
use PHPUnit\Framework\TestCase;

class AuthorFlowRankerTest extends TestCase
{
    public function test_breakout_e_beats_engulf_d_on_same_bar(): void
    {
        $ranker = new AuthorFlowRanker;
        $context = [
            'last_bar_index' => 10,
            'structure_bias' => 'up',
            'open_liquidity_call' => true,
            'open_liquidity_put' => true,
        ];
        $config = [
            'af_require_structure' => false,
            'af_require_open_liquidity' => false,
        ];

        $picked = $ranker->pick([
            [
                'setup' => 'engulf_D',
                'side' => 'PUT',
                'bar_index' => 10,
                'priority' => 20,
                'score' => 0.90,
                'features' => ['reason_ru' => 'D'],
            ],
            [
                'setup' => 'breakout_E',
                'side' => 'CALL',
                'bar_index' => 10,
                'priority' => 10,
                'score' => 0.70,
                'features' => ['reason_ru' => 'E'],
            ],
        ], $context, $config);

        $this->assertIsArray($picked);
        $this->assertSame('breakout_E', $picked['setup']);
        $this->assertSame('CALL', $picked['side']);
        $this->assertCount(1, $picked['features']['suppressed'] ?? []);
        $this->assertSame('engulf_D', $picked['features']['suppressed'][0]['setup']);
    }

    public function test_structure_filter_drops_counter_trend(): void
    {
        $ranker = new AuthorFlowRanker;
        $context = [
            'last_bar_index' => 5,
            'structure_bias' => 'up',
            'open_liquidity_call' => true,
            'open_liquidity_put' => true,
        ];
        $config = [
            'af_require_structure' => true,
            'af_allow_range_entries' => false,
            'af_require_open_liquidity' => false,
        ];

        $picked = $ranker->pick([
            [
                'setup' => 'engulf_D',
                'side' => 'PUT',
                'bar_index' => 5,
                'priority' => 20,
                'score' => 0.80,
                'features' => [],
            ],
        ], $context, $config);

        $this->assertNull($picked);
    }

    public function test_equal_priority_call_put_tie_skips(): void
    {
        $ranker = new AuthorFlowRanker;
        $context = [
            'last_bar_index' => 3,
            'structure_bias' => 'range',
            'open_liquidity_call' => true,
            'open_liquidity_put' => true,
        ];
        $config = [
            'af_require_structure' => false,
            'af_require_open_liquidity' => false,
        ];

        $picked = $ranker->pick([
            [
                'setup' => 'sweep',
                'side' => 'CALL',
                'bar_index' => 3,
                'priority' => 40,
                'score' => 0.62,
                'features' => [],
            ],
            [
                'setup' => 'sweep',
                'side' => 'PUT',
                'bar_index' => 3,
                'priority' => 40,
                'score' => 0.62,
                'features' => [],
            ],
        ], $context, $config);

        $this->assertNull($picked);
    }

    public function test_ignores_candidates_not_on_last_bar(): void
    {
        $ranker = new AuthorFlowRanker;
        $context = [
            'last_bar_index' => 9,
            'structure_bias' => 'down',
        ];
        $config = ['af_require_structure' => false];

        $picked = $ranker->pick([
            [
                'setup' => 'raid_pb',
                'side' => 'PUT',
                'bar_index' => 8,
                'priority' => 30,
                'score' => 0.99,
                'features' => [],
            ],
            [
                'setup' => 'sweep',
                'side' => 'PUT',
                'bar_index' => 9,
                'priority' => 40,
                'score' => 0.50,
                'features' => [],
            ],
        ], $context, $config);

        $this->assertIsArray($picked);
        $this->assertSame('sweep', $picked['setup']);
    }
}
