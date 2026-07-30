<?php

namespace App\Support;

use App\Services\Indicators\DemarkerIndicatorService;
use Illuminate\Http\Request;

/** Форма DeMarker — Period SMA, уровни 0.7 / 0.3. */
class DemarkerSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'integer', 'min:1', 'max:500'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric', 'min:0', 'max:1'],
            'level_oversold' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        return array_replace(DemarkerIndicatorService::defaultParams(), [
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