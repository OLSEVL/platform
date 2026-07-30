<?php

namespace App\Support;

use App\Services\Indicators\StochasticIndicatorService;
use Illuminate\Http\Request;

/** Форма Stochastic — Period1/2/3 + видимость %K/%D + уровни 80/20. */
class StochasticSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period1' => ['required', 'integer', 'min:1', 'max:500'],
            'period2' => ['required', 'integer', 'min:1', 'max:500'],
            'period3' => ['required', 'integer', 'min:1', 'max:500'],
            'color_k' => ['required', 'string', 'max:32'],
            'color_d' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric', 'min:0', 'max:100'],
            'level_oversold' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return array_replace(StochasticIndicatorService::defaultParams(), [
            'period1' => (int) $validated['period1'],
            'period2' => (int) $validated['period2'],
            'period3' => (int) $validated['period3'],
            'show_k' => $request->boolean('show_k'),
            'show_d' => $request->boolean('show_d'),
            'color_k' => trim((string) $validated['color_k']),
            'color_d' => trim((string) $validated['color_d']),
            'line_width' => (int) $validated['line_width'],
            'level_overbought' => (float) $validated['level_overbought'],
            'level_oversold' => (float) $validated['level_oversold'],
            'show_levels' => $request->boolean('show_levels'),
        ]);
    }
}
