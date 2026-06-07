<?php

namespace Tests\Unit;

use App\Support\SmcFlowSettingsForm;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SmcFlowSettingsFormTest extends TestCase
{
    #[Test]
    public function build_from_request_uses_defaults_for_lux_colors_not_in_form(): void
    {
        $request = Request::create('/test', 'PUT', $this->minimalFormPayload());

        $params = SmcFlowSettingsForm::buildFromRequest($request);

        $this->assertSame('#089981', $params['color_lux_fvg_bull']);
        $this->assertSame(70, $params['color_lux_fvg_bull_alpha']);
        $this->assertSame('#f23645', $params['color_lux_fvg_bear']);
        $this->assertSame('#089981', $params['color_lux_ob_bull_bg']);
        $this->assertTrue($params['hull_show_band']);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalFormPayload(): array
    {
        return [
            'ut_key_value' => 2,
            'ut_atr_period' => 6,
            'max_ut_labels' => 3,
            'color_ut_buy' => '#22c55e',
            'color_ut_sell' => '#ef4444',
            'color_ut_trail_bull' => '#22c55e',
            'color_ut_trail_bear' => '#ef4444',
            'hull_mode' => 'Hma',
            'hull_length' => 55,
            'hull_length_multiplier' => 1,
            'hull_line_width' => 2,
            'hull_signal_line_width' => 1,
            'color_hull_up' => '#00ff00',
            'color_hull_down' => '#ff0000',
            'color_hull_neutral' => '#ff9800',
            'hull_show_band' => '1',
            'three_br_type' => 'All',
            'three_br_trend_type' => 'None',
            'three_br_sr_mode' => 'Level',
            'three_br_st_atr_period' => 10,
            'three_br_st_factor' => 3,
            'three_br_dc_length' => 13,
            'three_br_trend_filter' => 'Aligned',
            'three_br_ma_type' => 'HMA',
            'three_br_ma_fast_length' => 50,
            'three_br_ma_slow_length' => 200,
            'three_br_max_patterns' => 120,
            'color_three_br_bull' => '#2962ff',
            'color_three_br_bear' => '#ff9800',
            'color_rs_bull' => '#089981',
            'color_rs_bear' => '#f23645',
            'mxwll_int_sensitivity' => 5,
            'mxwll_int_structure' => 'All',
            'mxwll_ext_sensitivity' => 25,
            'mxwll_ext_structure' => 'All',
            'mxwll_max_events' => 120,
            'color_mxwll_bull' => '#14D990',
            'color_mxwll_bear' => '#F24968',
            'color_mxwll_level' => '#00ffff',
            'mxwll_fib_extend_bars' => 5,
            'sessions_transparency' => 98,
            'color_session_ny' => '#f24968',
            'color_session_asia' => '#14D990',
            'color_session_london' => '#F2B807',
            'hull_band_transparency' => 40,
            'lux_fvg_threshold_pct' => 0,
            'lux_fvg_show_last' => 0,
            'lux_fvg_extend_bars' => 20,
            'lux_ob_length' => 5,
            'lux_ob_bull_count' => 3,
            'lux_ob_bear_count' => 3,
            'lux_ob_mitigation' => 'Wick',
            'lux_ob_avg_line_style' => 'dotted',
            'lux_ob_avg_line_width' => 1,
            'label_size' => 'tiny',
        ];
    }
}
