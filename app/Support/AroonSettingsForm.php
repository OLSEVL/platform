<?php

namespace App\Support;

use App\Services\Indicators\AroonIndicatorService;
use Illuminate\Http\Request;

/** Форма Aroon — Period, Up/Down, уровни 70/30. */
class AroonSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'integer', 'min:1', 'max:500'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_up' => ['required', 'numeric', 'min:0', 'max:100'],
            'level_down' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return array_replace(AroonIndicatorService::defaultParams(), [
            'period' => (int) $validated['period'],
            'show_up' => $request->boolean('show_up'),
            'show_down' => $request->boolean('show_down'),
            'color_up' => trim((string) $validated['color_up']),
            'color_down' => trim((string) $validated['color_down']),
            'line_width' => (int) $validated['line_width'],
            'show_levels' => $request->boolean('show_levels'),
            'level_up' => (float) $validated['level_up'],
            'level_down' => (float) $validated['level_down'],
        ]);
    }
}
