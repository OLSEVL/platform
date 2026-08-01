<?php

namespace App\Support;

use App\Services\Indicators\DynamicTrendDetectorIndicatorService;
use Illuminate\Http\Request;

/** Форма Dynamic Trend Detector — Length, CorrectionCoeff %, стиль. */
class DynamicTrendDetectorSettingsForm
{
    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'correction_coeff' => ['required', 'numeric', 'min:0', 'max:100'],
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
        return array_replace(DynamicTrendDetectorIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'correction_coeff' => (float) $validated['correction_coeff'],
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
        return array_replace(DynamicTrendDetectorIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
