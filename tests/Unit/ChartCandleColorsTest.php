<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserTradingSetting;
use App\Support\ChartBootFlags;
use App\Support\ChartCandleColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartCandleColorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_accepts_hex_variants(): void
    {
        $this->assertSame('#22ff00', ChartCandleColors::normalize('#22FF00'));
        $this->assertSame('#ff0000', ChartCandleColors::normalize('ff0000'));
        $this->assertSame('#aabbcc', ChartCandleColors::normalize('#abc'));
        $this->assertNull(ChartCandleColors::normalize('not-a-color'));
        $this->assertNull(ChartCandleColors::normalize(''));
        $this->assertNull(ChartCandleColors::normalize(null));
    }

    public function test_for_user_returns_po_defaults_without_settings(): void
    {
        $colors = ChartCandleColors::forUser(null);
        $this->assertSame(ChartCandleColors::DEFAULT_UP, $colors['up']);
        $this->assertSame(ChartCandleColors::DEFAULT_DOWN, $colors['down']);
    }

    public function test_for_user_uses_custom_colors_from_settings(): void
    {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'candle_up_color' => '#00aaff',
            'candle_down_color' => '#ff00aa',
        ]);

        $colors = ChartCandleColors::forUser((int) $user->id);
        $this->assertSame('#00aaff', $colors['up']);
        $this->assertSame('#ff00aa', $colors['down']);
    }

    public function test_boot_flags_include_candle_colors(): void
    {
        $flags = ChartBootFlags::forClient(null);
        $this->assertSame(ChartCandleColors::DEFAULT_UP, $flags['candleUpColor']);
        $this->assertSame(ChartCandleColors::DEFAULT_DOWN, $flags['candleDownColor']);
    }
}
