<?php

namespace App\Support;

use App\Services\Indicators\SmcFlowIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Разбор формы настроек SMC Flow в массив параметров SmcFlowIndicatorService.
 */
class SmcFlowSettingsForm
{
  /**
   * @return array<string, mixed>
   */
  public static function buildFromRequest(Request $request): array
  {
    $validated = $request->validate([
      'ut_key_value' => ['required', 'numeric', 'min:0.1', 'max:100'],
      'ut_atr_period' => ['required', 'integer', 'min:1', 'max:500'],
      'max_ut_labels' => ['required', 'integer', 'min:1', 'max:10'],
      'color_ut_buy' => ['required', 'string', 'max:32'],
      'color_ut_sell' => ['required', 'string', 'max:32'],
      'color_ut_trail_bull' => ['required', 'string', 'max:32'],
      'color_ut_trail_bear' => ['required', 'string', 'max:32'],
      'hull_mode' => ['required', Rule::in(['Hma', 'Thma', 'Ehma'])],
      'hull_length' => ['required', 'integer', 'min:2', 'max:500'],
      'hull_length_multiplier' => ['required', 'numeric', 'min:0.1', 'max:5'],
      'hull_line_width' => ['required', 'integer', 'min:1', 'max:6'],
      'hull_signal_line_width' => ['required', 'integer', 'min:1', 'max:6'],
      'color_hull_up' => ['required', 'string', 'max:32'],
      'color_hull_down' => ['required', 'string', 'max:32'],
      'color_hull_neutral' => ['required', 'string', 'max:32'],
      'three_br_type' => ['required', Rule::in(['Normal', 'Enhanced', 'All'])],
      'three_br_trend_type' => ['required', Rule::in(['Moving Average Cloud', 'None'])],
      'three_br_trend_filter' => ['required', Rule::in(['Aligned', 'Opposite'])],
      'three_br_ma_type' => ['required', Rule::in(['SMA', 'EMA', 'HMA', 'RMA', 'WMA', 'VWMA'])],
      'three_br_ma_fast_length' => ['required', 'integer', 'min:2', 'max:500'],
      'three_br_ma_slow_length' => ['required', 'integer', 'min:3', 'max:1000'],
      'three_br_max_patterns' => ['required', 'integer', 'min:10', 'max:500'],
      'color_three_br_bull' => ['required', 'string', 'max:32'],
      'color_three_br_bear' => ['required', 'string', 'max:32'],
      'label_size' => ['required', Rule::in(['tiny', 'small', 'normal'])],
    ]);

    $defaults = SmcFlowIndicatorService::defaultParams();

    return array_replace($defaults, [
      'ut_enabled' => $request->boolean('ut_enabled'),
      'ut_key_value' => (float) $validated['ut_key_value'],
      'ut_atr_period' => (int) $validated['ut_atr_period'],
      'ut_use_heikin_ashi' => $request->boolean('ut_use_heikin_ashi'),
      'show_ut_labels' => $request->boolean('show_ut_labels'),
      'keep_recent_only' => $request->boolean('keep_recent_only'),
      'max_ut_labels' => (int) $validated['max_ut_labels'],
      'color_ut_buy' => trim((string) $validated['color_ut_buy']),
      'color_ut_sell' => trim((string) $validated['color_ut_sell']),
      'show_ut_trailing_stop' => $request->boolean('show_ut_trailing_stop'),
      'color_ut_trail_bull' => trim((string) $validated['color_ut_trail_bull']),
      'color_ut_trail_bear' => trim((string) $validated['color_ut_trail_bear']),
      'hull_enabled' => $request->boolean('hull_enabled'),
      'hull_mode' => (string) $validated['hull_mode'],
      'hull_length' => (int) $validated['hull_length'],
      'hull_length_multiplier' => (float) $validated['hull_length_multiplier'],
      'hull_switch_color' => $request->boolean('hull_switch_color'),
      'hull_show_signal_line' => $request->boolean('hull_show_signal_line'),
      'hull_line_width' => (int) $validated['hull_line_width'],
      'hull_signal_line_width' => (int) $validated['hull_signal_line_width'],
      'color_hull_up' => trim((string) $validated['color_hull_up']),
      'color_hull_down' => trim((string) $validated['color_hull_down']),
      'color_hull_neutral' => trim((string) $validated['color_hull_neutral']),
      'three_br_enabled' => $request->boolean('three_br_enabled'),
      'three_br_type' => (string) $validated['three_br_type'],
      'three_br_trend_type' => (string) $validated['three_br_trend_type'],
      'three_br_trend_filter' => (string) $validated['three_br_trend_filter'],
      'three_br_ma_type' => (string) $validated['three_br_ma_type'],
      'three_br_ma_fast_length' => (int) $validated['three_br_ma_fast_length'],
      'three_br_ma_slow_length' => (int) $validated['three_br_ma_slow_length'],
      'three_br_show_levels' => $request->boolean('three_br_show_levels'),
      'three_br_max_patterns' => (int) $validated['three_br_max_patterns'],
      'color_three_br_bull' => trim((string) $validated['color_three_br_bull']),
      'color_three_br_bear' => trim((string) $validated['color_three_br_bear']),
      'label_size' => (string) $validated['label_size'],
    ]);
  }
}
