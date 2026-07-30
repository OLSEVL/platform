<?php

namespace App\Support;

use App\Services\Indicators\SmiIndicatorService;
use Illuminate\Http\Request;

/** Форма SMI — 4 периода и уровни ±40. */
class SmiSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period1' => ['required', 'integer', 'min:1', 'max:500'],
            'period2' => ['required', 'integer', 'min:1', 'max:500'],
            'period3' => ['required', 'integer', 'min:1', 'max:500'],
            'period4' => ['required', 'integer', 'min:1', 'max:500'],
            'color_smi' => ['required', 'string', 'max:32'],
            'color_signal' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric'],
            'level_oversold' => ['required', 'numeric'],
        ]);

        return array_replace(SmiIndicatorService::defaultParams(), [
            'period1' => (int) $validated['period1'],
            'period2' => (int) $validated['period2'],
            'period3' => (int) $validated['period3'],
            'period4' => (int) $validated['period4'],
            'show_smi' => $request->boolean('show_smi'),
            'show_signal' => $request->boolean('show_signal'),
            'color_smi' => trim((string) $validated['color_smi']),
            'color_signal' => trim((string) $validated['color_signal']),
            'line_width' => (int) $validated['line_width'],
            'show_levels' => $request->boolean('show_levels'),
            'level_overbought' => (float) $validated['level_overbought'],
            'level_oversold' => (float) $validated['level_oversold'],
        ]);
    }
}