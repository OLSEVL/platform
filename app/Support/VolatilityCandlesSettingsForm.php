<?php

namespace App\Support;

use App\Services\Indicators\VolatilityCandlesIndicatorService;
use Illuminate\Http\Request;

/** Форма VolatilityCandles — Length, WeightCoeff + стиль линии. */
class VolatilityCandlesSettingsForm
{
    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'weight_coeff' => ['required', 'numeric', 'min:0', 'max:1'],
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
        return array_replace(VolatilityCandlesIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'weight_coeff' => (float) $validated['weight_coeff'],
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
        return array_replace(VolatilityCandlesIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
