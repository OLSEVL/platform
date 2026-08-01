<?php

namespace App\Support;

use App\Services\Indicators\ZigZagIndicatorService;
use Illuminate\Http\Request;

/** Форма ZigZag — Length + видимость линии/хай/лоу + цвета. */
class ZigZagSettingsForm
{
    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'color_line' => ['required', 'string', 'max:32'],
            'color_high' => ['required', 'string', 'max:32'],
            'color_low' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'point_radius' => ['required', 'integer', 'min:1', 'max:12'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function merge(array $validated, Request $request): array
    {
        return array_replace(ZigZagIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'show_line' => $request->boolean('show_line'),
            'show_highs' => $request->boolean('show_highs'),
            'show_lows' => $request->boolean('show_lows'),
            'show_points' => $request->boolean('show_points'),
            'color_line' => trim((string) $validated['color_line']),
            'color_high' => trim((string) $validated['color_high']),
            'color_low' => trim((string) $validated['color_low']),
            'line_width' => (int) $validated['line_width'],
            'point_radius' => (int) $validated['point_radius'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function forView(?array $stored = null): array
    {
        return array_replace(ZigZagIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
