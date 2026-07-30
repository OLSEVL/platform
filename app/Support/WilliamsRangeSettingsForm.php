<?php

namespace App\Support;

use App\Services\Indicators\WilliamsRangeIndicatorService;
use Illuminate\Http\Request;

/** Форма Williams %R — Period, уровни -20/-80. */
class WilliamsRangeSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'integer', 'min:1', 'max:500'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric', 'min:-100', 'max:0'],
            'level_oversold' => ['required', 'numeric', 'min:-100', 'max:0'],
        ]);

        return array_replace(WilliamsRangeIndicatorService::defaultParams(), [
            'period' => (int) $validated['period'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
            'show_levels' => $request->boolean('show_levels'),
            'level_overbought' => (float) $validated['level_overbought'],
            'level_oversold' => (float) $validated['level_oversold'],
        ]);
    }
}
