<?php

namespace App\Support;

use App\Services\Indicators\PriceActionDgtIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Форма настроек индикатора Price Action (DGT): валидация и сбор массива параметров
 * в формате PriceActionDgtIndicatorService::defaultParams() из HTTP-запроса.
 */
final class PriceActionDgtSettingsForm
{
    /** @return list<string> */
    public static function booleanKeys(): array
    {
        return [
            'sr_lines',
            'v_spike_markers',
            'v_spike_lines',
            'atr_markers',
            'atr_lines',
            'volume_profile',
            'vp_vah',
            'vp_poc',
            'vp_val',
            'vp_price_levels',
            'supply_demand',
            'vwcb',
        ];
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $defaults = PriceActionDgtIndicatorService::defaultParams();
        $rules = self::rules();
        $validated = $request->validate($rules);
        $out = $defaults;
        foreach ($validated as $k => $v) {
            if (! array_key_exists($k, $defaults)) {
                continue;
            }
            $out[$k] = self::castScalar($defaults[$k], $v);
        }
        foreach (self::booleanKeys() as $k) {
            $out[$k] = $request->boolean($k);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, string|array<int, mixed>>
     */
    public static function rules(): array
    {
        $lineStyles = ['Solid', 'Dotted', 'Dashed'];
        $levels = ['High', 'Close', 'Both'];

        return [
            'sr_lookback_range' => ['required', 'string', Rule::in(['visible', 'fixed'])],
            'sr_fixed_lookback_bars' => ['required', 'integer', 'min:10', 'max:5000'],
            'sr_source' => ['required', 'string', Rule::in(['volume', 'price'])],
            'sr_line_color' => ['required', 'string', 'max:12'],
            'sr_line_width' => ['required', 'integer', 'min:1', 'max:20'],
            'sr_line_style' => ['required', 'string', Rule::in($lineStyles)],

            'v_spike_threshold' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'v_spike_line_color' => ['required', 'string', 'max:12'],
            'v_spike_line_width' => ['required', 'integer', 'min:1', 'max:20'],
            'v_spike_line_style' => ['required', 'string', Rule::in($lineStyles)],
            'v_spike_bull_level' => ['required', 'string', Rule::in($levels)],
            'v_spike_bear_level' => ['required', 'string', Rule::in($levels)],

            'atr_length' => ['required', 'integer', 'min:1', 'max:500'],
            'atr_mult' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'atr_line_color' => ['required', 'string', 'max:12'],
            'atr_line_width' => ['required', 'integer', 'min:1', 'max:20'],
            'atr_line_style' => ['required', 'string', Rule::in($lineStyles)],
            'atr_bull_level' => ['required', 'string', Rule::in($levels)],
            'atr_bear_level' => ['required', 'string', Rule::in($levels)],

            'volume_sma_length' => ['required', 'integer', 'min:1', 'max:500'],

            'vp_lookback_range' => ['required', 'string', Rule::in(['visible', 'fixed'])],
            'vp_fixed_lookback_bars' => ['required', 'integer', 'min:10', 'max:5000'],
            'vp_profile_levels' => ['required', 'integer', 'min:10', 'max:170'],
            'vp_horizontal_offset_bars' => ['required', 'integer', 'min:0', 'max:200'],
            'vp_value_area_pct' => ['required', 'integer', 'min:1', 'max:99'],
            'vp_vah_color' => ['required', 'string', 'max:12'],
            'vp_poc_color' => ['required', 'string', 'max:12'],
            'vp_val_color' => ['required', 'string', 'max:12'],
            'vp_label_color' => ['required', 'string', 'max:12'],
            'vp_avg_node_color' => ['required', 'string', 'max:12'],
            'vp_high_node_color' => ['required', 'string', 'max:12'],
            'vp_low_node_color' => ['required', 'string', 'max:12'],

            'sd_low_vol_ratio_pct' => ['required', 'numeric', 'min:1', 'max:50'],
            'sd_zone_color' => ['required', 'string', 'max:12'],

            'vwcb_high' => ['required', 'numeric', 'min:0.01', 'max:20'],
            'vwcb_low' => ['required', 'numeric', 'min:0.01', 'max:20'],
        ];
    }

    private static function castScalar(mixed $default, mixed $value): mixed
    {
        return match (true) {
            is_int($default) => (int) $value,
            is_float($default) => (float) $value,
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }
}
