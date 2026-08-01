<?php

namespace App\Support;

use App\Services\Indicators\OsEngineCandleMath;
use App\Services\Indicators\PriceOscillatorIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма Price Oscillator — short/long MA, Punkt|Persent, стиль. */
class PriceOscillatorSettingsForm
{
    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'length_short' => ['required', 'integer', 'min:1', 'max:500'],
            'length_long' => ['required', 'integer', 'min:1', 'max:500'],
            'candle_point' => ['required', 'string', Rule::in(OsEngineCandleMath::CANDLE_POINTS)],
            'type_search' => ['required', 'string', Rule::in(['Punkt', 'Persent'])],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function merge(array $validated, Request $request): array
    {
        return array_replace(PriceOscillatorIndicatorService::defaultParams(), [
            'length_short' => (int) $validated['length_short'],
            'length_long' => (int) $validated['length_long'],
            'candle_point' => (string) $validated['candle_point'],
            'type_search' => (string) $validated['type_search'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function forView(?array $stored = null): array
    {
        return array_replace(PriceOscillatorIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
