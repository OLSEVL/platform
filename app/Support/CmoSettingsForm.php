<?php

namespace App\Support;

use App\Services\Indicators\CmoIndicatorService;
use Illuminate\Http\Request;

/** Форма CMO — Period и уровни ±50. */
class CmoSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'integer', 'min:1', 'max:500'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric'],
            'level_oversold' => ['required', 'numeric'],
        ]);

        return array_replace(CmoIndicatorService::defaultParams(), [
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