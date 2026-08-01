<?php

namespace App\Support;

use App\Services\Indicators\VolatilityAverageChannelIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма VolatilityAverageChannel — база волы, fast/slow SMA, deviation канала. */
class VolatilityAverageChannelSettingsForm
{
    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'volatility_base_type' => ['required', 'string', Rule::in(['Day', 'Week', 'Candle'])],
            'volatility_variable_type' => ['required', 'string', Rule::in(['Percent', 'Absolute'])],
            'sma_len_slow' => ['required', 'integer', 'min:1', 'max:500'],
            'sma_len_fast' => ['required', 'integer', 'min:1', 'max:500'],
            'channel_deviation' => ['required', 'numeric', 'min:0', 'max:10'],
            'color_volatility' => ['required', 'string', 'max:32'],
            'color_sma_fast' => ['required', 'string', 'max:32'],
            'color_sma_slow' => ['required', 'string', 'max:32'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function merge(array $validated, Request $request): array
    {
        return array_replace(VolatilityAverageChannelIndicatorService::defaultParams(), [
            'volatility_base_type' => (string) $validated['volatility_base_type'],
            'volatility_variable_type' => (string) $validated['volatility_variable_type'],
            'sma_len_slow' => (int) $validated['sma_len_slow'],
            'sma_len_fast' => (int) $validated['sma_len_fast'],
            'channel_deviation' => (float) $validated['channel_deviation'],
            'show_volatility' => $request->boolean('show_volatility'),
            'show_sma_fast' => $request->boolean('show_sma_fast'),
            'show_sma_slow' => $request->boolean('show_sma_slow'),
            'show_up' => $request->boolean('show_up'),
            'show_down' => $request->boolean('show_down'),
            'color_volatility' => trim((string) $validated['color_volatility']),
            'color_sma_fast' => trim((string) $validated['color_sma_fast']),
            'color_sma_slow' => trim((string) $validated['color_sma_slow']),
            'color_up' => trim((string) $validated['color_up']),
            'color_down' => trim((string) $validated['color_down']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function forView(?array $stored = null): array
    {
        return array_replace(VolatilityAverageChannelIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
