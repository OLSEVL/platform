<?php

namespace App\Support;

use App\Services\Indicators\VolatilityAverageTwiceIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма VolatilityAverageTwice — база волы + fast/slow SMA. */
class VolatilityAverageTwiceSettingsForm
{
    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'volatility_base_type' => ['required', 'string', Rule::in(['Day', 'Week', 'Candle'])],
            'volatility_variable_type' => ['required', 'string', Rule::in(['Percent', 'Absolute'])],
            'sma_len_slow' => ['required', 'integer', 'min:1', 'max:500'],
            'sma_len_fast' => ['required', 'integer', 'min:1', 'max:500'],
            'color_volatility' => ['required', 'string', 'max:32'],
            'color_sma_fast' => ['required', 'string', 'max:32'],
            'color_sma_slow' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function merge(array $validated, Request $request): array
    {
        return array_replace(VolatilityAverageTwiceIndicatorService::defaultParams(), [
            'volatility_base_type' => (string) $validated['volatility_base_type'],
            'volatility_variable_type' => (string) $validated['volatility_variable_type'],
            'sma_len_slow' => (int) $validated['sma_len_slow'],
            'sma_len_fast' => (int) $validated['sma_len_fast'],
            'show_volatility' => $request->boolean('show_volatility'),
            'show_sma_fast' => $request->boolean('show_sma_fast'),
            'show_sma_slow' => $request->boolean('show_sma_slow'),
            'color_volatility' => trim((string) $validated['color_volatility']),
            'color_sma_fast' => trim((string) $validated['color_sma_fast']),
            'color_sma_slow' => trim((string) $validated['color_sma_slow']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function forView(?array $stored = null): array
    {
        return array_replace(VolatilityAverageTwiceIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
