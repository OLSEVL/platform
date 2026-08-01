<?php

namespace App\Support;

use App\Services\Indicators\VolatilityAverageIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма VolatilityAverage — тип базы/переменной, длина SMA, стили двух линий. */
class VolatilityAverageSettingsForm
{
    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'volatility_base_type' => ['required', 'string', Rule::in(['Day', 'Week', 'Candle'])],
            'volatility_variable_type' => ['required', 'string', Rule::in(['Percent', 'Absolute'])],
            'sma_len' => ['required', 'integer', 'min:1', 'max:500'],
            'color_volatility' => ['required', 'string', 'max:32'],
            'color_sma' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function merge(array $validated, Request $request): array
    {
        return array_replace(VolatilityAverageIndicatorService::defaultParams(), [
            'volatility_base_type' => (string) $validated['volatility_base_type'],
            'volatility_variable_type' => (string) $validated['volatility_variable_type'],
            'sma_len' => (int) $validated['sma_len'],
            'show_volatility' => $request->boolean('show_volatility'),
            'show_sma' => $request->boolean('show_sma'),
            'color_volatility' => trim((string) $validated['color_volatility']),
            'color_sma' => trim((string) $validated['color_sma']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function forView(?array $stored = null): array
    {
        return array_replace(VolatilityAverageIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
