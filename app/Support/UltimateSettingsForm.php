<?php

namespace App\Support;

use App\Services\Indicators\UltimateIndicatorService;
use Illuminate\Http\Request;

/** Форма Ultimate Oscillator — 3 периода и уровни 70/30. */
class UltimateSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period1' => ['required', 'integer', 'min:1', 'max:500'],
            'period2' => ['required', 'integer', 'min:1', 'max:500'],
            'period3' => ['required', 'integer', 'min:1', 'max:500'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric'],
            'level_oversold' => ['required', 'numeric'],
        ]);

        return array_replace(UltimateIndicatorService::defaultParams(), [
            'period1' => (int) $validated['period1'],
            'period2' => (int) $validated['period2'],
            'period3' => (int) $validated['period3'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
            'show_levels' => $request->boolean('show_levels'),
            'level_overbought' => (float) $validated['level_overbought'],
            'level_oversold' => (float) $validated['level_oversold'],
        ]);
    }
}