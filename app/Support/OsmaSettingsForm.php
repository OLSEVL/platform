<?php

namespace App\Support;

use App\Services\Indicators\OsmaIndicatorService;
use Illuminate\Http\Request;

/** Форма OsMa — Fast/Slow/Signal как у MACD. */
class OsmaSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_fast' => ['required', 'integer', 'min:1', 'max:500'],
            'length_slow' => ['required', 'integer', 'min:1', 'max:500'],
            'length_signal' => ['required', 'integer', 'min:1', 'max:500'],
            'color_osma' => ['required', 'string', 'max:32'],
            'color_signal' => ['required', 'string', 'max:32'],
            'color_histogram' => ['required', 'string', 'max:32'],
            'color_histogram_neg' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(OsmaIndicatorService::defaultParams(), [
            'length_fast' => (int) $validated['length_fast'],
            'length_slow' => (int) $validated['length_slow'],
            'length_signal' => (int) $validated['length_signal'],
            'show_osma' => $request->boolean('show_osma'),
            'show_signal' => $request->boolean('show_signal'),
            'show_histogram' => $request->boolean('show_histogram'),
            'color_osma' => trim((string) $validated['color_osma']),
            'color_signal' => trim((string) $validated['color_signal']),
            'color_histogram' => trim((string) $validated['color_histogram']),
            'color_histogram_neg' => trim((string) $validated['color_histogram_neg']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}