<?php

namespace App\Support;

use App\Services\Indicators\SuperTrendIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма SuperTrend — Length, Deviation, Median/Typical, wicks. */
class SuperTrendSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'deviation' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'candle_point' => ['required', Rule::in(['Median', 'Typical'])],
            'color_center' => ['required', 'string', 'max:32'],
            'color_upper' => ['required', 'string', 'max:32'],
            'color_lower' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(SuperTrendIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'deviation' => (float) $validated['deviation'],
            'candle_point' => (string) $validated['candle_point'],
            'use_wicks' => $request->boolean('use_wicks'),
            'show_center' => $request->boolean('show_center'),
            'show_upper' => $request->boolean('show_upper'),
            'show_lower' => $request->boolean('show_lower'),
            'color_center' => trim((string) $validated['color_center']),
            'color_upper' => trim((string) $validated['color_upper']),
            'color_lower' => trim((string) $validated['color_lower']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
