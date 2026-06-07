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
      'three_br_trend_type' => ['required', Rule::in(['Moving Average Cloud', 'Supertrend', 'Donchian Channels', 'None'])],
      'three_br_sr_mode' => ['required', Rule::in(['Level', 'Zone', 'None'])],
      'three_br_st_atr_period' => ['required', 'integer', 'min:1', 'max:100'],
      'three_br_st_factor' => ['required', 'numeric', 'min:0.1', 'max:20'],
      'three_br_dc_length' => ['required', 'integer', 'min:1', 'max:200'],
      'three_br_trend_filter' => ['required', Rule::in(['Aligned', 'Opposite'])],
      'three_br_ma_type' => ['required', Rule::in(['SMA', 'EMA', 'HMA', 'RMA', 'WMA', 'VWMA'])],
      'three_br_ma_fast_length' => ['required', 'integer', 'min:2', 'max:500'],
      'three_br_ma_slow_length' => ['required', 'integer', 'min:3', 'max:1000'],
      'three_br_max_patterns' => ['required', 'integer', 'min:10', 'max:500'],
      'color_three_br_bull' => ['required', 'string', 'max:32'],
      'color_three_br_bear' => ['required', 'string', 'max:32'],
      'color_rs_bull' => ['required', 'string', 'max:32'],
      'color_rs_bear' => ['required', 'string', 'max:32'],
      'mxwll_int_sensitivity' => ['required', 'integer', Rule::in([3, 5, 8])],
      'mxwll_int_structure' => ['required', Rule::in(['All', 'BoS', 'CHoCH'])],
      'mxwll_ext_sensitivity' => ['required', 'integer', Rule::in([10, 25, 50])],
      'mxwll_ext_structure' => ['required', Rule::in(['All', 'BoS', 'CHoCH'])],
      'mxwll_max_events' => ['required', 'integer', 'min:20', 'max:500'],
      'color_mxwll_bull' => ['required', 'string', 'max:32'],
      'color_mxwll_bear' => ['required', 'string', 'max:32'],
      'color_mxwll_level' => ['required', 'string', 'max:32'],
      'mxwll_fib_extend_bars' => ['required', 'integer', 'min:0', 'max:50'],
      'sessions_transparency' => ['required', 'integer', 'min:0', 'max:100'],
      'color_session_ny' => ['required', 'string', 'max:32'],
      'color_session_asia' => ['required', 'string', 'max:32'],
      'color_session_london' => ['required', 'string', 'max:32'],
      'hull_band_transparency' => ['required', 'integer', 'min:0', 'max:100'],
      'lux_fvg_threshold_pct' => ['required', 'numeric', 'min:0', 'max:100'],
      'lux_fvg_show_last' => ['required', 'integer', 'min:0', 'max:50'],
      'lux_fvg_extend_bars' => ['required', 'integer', 'min:0', 'max:200'],
      // Цвета Lux FVG/OB в UI пока не выводим — берём из defaultParams(), если не переданы.
      'color_lux_fvg_bull' => ['nullable', 'string', 'max:32'],
      'color_lux_fvg_bull_alpha' => ['nullable', 'integer', 'min:0', 'max:100'],
      'color_lux_fvg_bear' => ['nullable', 'string', 'max:32'],
      'color_lux_fvg_bear_alpha' => ['nullable', 'integer', 'min:0', 'max:100'],
      'lux_ob_length' => ['required', 'integer', 'min:1', 'max:50'],
      'lux_ob_bull_count' => ['required', 'integer', 'min:1', 'max:20'],
      'lux_ob_bear_count' => ['required', 'integer', 'min:1', 'max:20'],
      'lux_ob_mitigation' => ['required', Rule::in(['Wick', 'Close'])],
      'lux_ob_avg_line_style' => ['required', Rule::in(['solid', 'dashed', 'dotted'])],
      'lux_ob_avg_line_width' => ['required', 'integer', 'min:1', 'max:6'],
      'color_lux_ob_bull_bg' => ['nullable', 'string', 'max:32'],
      'color_lux_ob_bull_bg_alpha' => ['nullable', 'integer', 'min:0', 'max:100'],
      'color_lux_ob_bull_border' => ['nullable', 'string', 'max:32'],
      'color_lux_ob_bull_avg' => ['nullable', 'string', 'max:32'],
      'color_lux_ob_bull_avg_alpha' => ['nullable', 'integer', 'min:0', 'max:100'],
      'color_lux_ob_bear_bg' => ['nullable', 'string', 'max:32'],
      'color_lux_ob_bear_bg_alpha' => ['nullable', 'integer', 'min:0', 'max:100'],
      'color_lux_ob_bear_border' => ['nullable', 'string', 'max:32'],
      'color_lux_ob_bear_avg' => ['nullable', 'string', 'max:32'],
      'color_lux_ob_bear_avg_alpha' => ['nullable', 'integer', 'min:0', 'max:100'],
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
      'three_br_sr_mode' => (string) $validated['three_br_sr_mode'],
      'three_br_st_atr_period' => (int) $validated['three_br_st_atr_period'],
      'three_br_st_factor' => (float) $validated['three_br_st_factor'],
      'three_br_dc_length' => (int) $validated['three_br_dc_length'],
      'three_br_max_patterns' => (int) $validated['three_br_max_patterns'],
      'color_three_br_bull' => trim((string) $validated['color_three_br_bull']),
      'color_three_br_bear' => trim((string) $validated['color_three_br_bear']),
      'rs_enabled' => $request->boolean('rs_enabled'),
      'rs_sr_levels' => $request->boolean('rs_sr_levels'),
      'color_rs_bull' => trim((string) $validated['color_rs_bull']),
      'color_rs_bear' => trim((string) $validated['color_rs_bear']),
      'mxwll_enabled' => $request->boolean('mxwll_enabled'),
      'mxwll_show_internals' => $request->boolean('mxwll_show_internals'),
      'mxwll_int_sensitivity' => (int) $validated['mxwll_int_sensitivity'],
      'mxwll_int_structure' => (string) $validated['mxwll_int_structure'],
      'mxwll_show_externals' => $request->boolean('mxwll_show_externals'),
      'mxwll_ext_sensitivity' => (int) $validated['mxwll_ext_sensitivity'],
      'mxwll_ext_structure' => (string) $validated['mxwll_ext_structure'],
      'mxwll_show_hh_lh' => $request->boolean('mxwll_show_hh_lh'),
      'mxwll_show_hl_ll' => $request->boolean('mxwll_show_hl_ll'),
      'mxwll_max_events' => (int) $validated['mxwll_max_events'],
      'color_mxwll_bull' => trim((string) $validated['color_mxwll_bull']),
      'color_mxwll_bear' => trim((string) $validated['color_mxwll_bear']),
      'mxwll_show_4h_levels' => $request->boolean('mxwll_show_4h_levels'),
      'mxwll_show_4h_labels' => $request->boolean('mxwll_show_4h_labels'),
      'mxwll_show_1d_levels' => $request->boolean('mxwll_show_1d_levels'),
      'mxwll_show_1d_labels' => $request->boolean('mxwll_show_1d_labels'),
      'color_mxwll_level' => trim((string) $validated['color_mxwll_level']),
      'mxwll_show_fibs' => $request->boolean('mxwll_show_fibs'),
      'mxwll_show_main_leg' => $request->boolean('mxwll_show_main_leg'),
      'mxwll_fib_extend_bars' => (int) $validated['mxwll_fib_extend_bars'],
      'sessions_enabled' => $request->boolean('sessions_enabled'),
      'sessions_transparency' => (int) $validated['sessions_transparency'],
      'color_session_ny' => trim((string) $validated['color_session_ny']),
      'color_session_asia' => trim((string) $validated['color_session_asia']),
      'color_session_london' => trim((string) $validated['color_session_london']),
      'hull_show_band' => $request->boolean('hull_show_band'),
      'hull_band_transparency' => (int) $validated['hull_band_transparency'],
      'lux_fvg_enabled' => $request->boolean('lux_fvg_enabled'),
      'lux_fvg_threshold_pct' => (float) $validated['lux_fvg_threshold_pct'],
      'lux_fvg_threshold_auto' => $request->boolean('lux_fvg_threshold_auto'),
      'lux_fvg_show_last' => (int) $validated['lux_fvg_show_last'],
      'lux_fvg_mitigation_levels' => $request->boolean('lux_fvg_mitigation_levels'),
      'lux_fvg_extend_bars' => (int) $validated['lux_fvg_extend_bars'],
      'lux_fvg_dynamic' => $request->boolean('lux_fvg_dynamic'),
      'color_lux_fvg_bull' => self::colorOrDefault($request, 'color_lux_fvg_bull', $defaults, '#089981'),
      'color_lux_fvg_bull_alpha' => self::intOrDefault($request, 'color_lux_fvg_bull_alpha', $defaults, 70),
      'color_lux_fvg_bear' => self::colorOrDefault($request, 'color_lux_fvg_bear', $defaults, '#f23645'),
      'color_lux_fvg_bear_alpha' => self::intOrDefault($request, 'color_lux_fvg_bear_alpha', $defaults, 70),
      'lux_ob_enabled' => $request->boolean('lux_ob_enabled'),
      'lux_ob_length' => (int) $validated['lux_ob_length'],
      'lux_ob_bull_count' => (int) $validated['lux_ob_bull_count'],
      'lux_ob_bear_count' => (int) $validated['lux_ob_bear_count'],
      'lux_ob_mitigation' => (string) $validated['lux_ob_mitigation'],
      'lux_ob_avg_line_style' => (string) $validated['lux_ob_avg_line_style'],
      'lux_ob_avg_line_width' => (int) $validated['lux_ob_avg_line_width'],
      'color_lux_ob_bull_bg' => self::colorOrDefault($request, 'color_lux_ob_bull_bg', $defaults, '#089981'),
      'color_lux_ob_bull_bg_alpha' => self::intOrDefault($request, 'color_lux_ob_bull_bg_alpha', $defaults, 80),
      'color_lux_ob_bull_border' => self::colorOrDefault($request, 'color_lux_ob_bull_border', $defaults, '#089981'),
      'color_lux_ob_bull_avg' => self::colorOrDefault($request, 'color_lux_ob_bull_avg', $defaults, '#9598a1'),
      'color_lux_ob_bull_avg_alpha' => self::intOrDefault($request, 'color_lux_ob_bull_avg_alpha', $defaults, 37),
      'color_lux_ob_bear_bg' => self::colorOrDefault($request, 'color_lux_ob_bear_bg', $defaults, '#f23645'),
      'color_lux_ob_bear_bg_alpha' => self::intOrDefault($request, 'color_lux_ob_bear_bg_alpha', $defaults, 80),
      'color_lux_ob_bear_border' => self::colorOrDefault($request, 'color_lux_ob_bear_border', $defaults, '#f23645'),
      'color_lux_ob_bear_avg' => self::colorOrDefault($request, 'color_lux_ob_bear_avg', $defaults, '#9598a1'),
      'color_lux_ob_bear_avg_alpha' => self::intOrDefault($request, 'color_lux_ob_bear_avg_alpha', $defaults, 37),
      'label_size' => (string) $validated['label_size'],
    ]);
  }

  /**
   * Поле цвета из запроса или из сохранённых настроек / дефолта сервиса.
   *
   * @param  array<string, mixed>  $defaults
   */
  private static function colorOrDefault(Request $request, string $key, array $defaults, string $fallback): string
  {
    $raw = $request->input($key);
    if (is_string($raw) && trim($raw) !== '') {
      return trim($raw);
    }

    $fromDefaults = $defaults[$key] ?? $fallback;

    return is_string($fromDefaults) && trim($fromDefaults) !== '' ? trim($fromDefaults) : $fallback;
  }

  /**
   * @param  array<string, mixed>  $defaults
   */
  private static function intOrDefault(Request $request, string $key, array $defaults, int $fallback): int
  {
    $raw = $request->input($key);
    if ($raw !== null && $raw !== '' && is_numeric($raw)) {
      return (int) $raw;
    }

    $fromDefaults = $defaults[$key] ?? $fallback;

    return is_numeric($fromDefaults) ? (int) $fromDefaults : $fallback;
  }
}
