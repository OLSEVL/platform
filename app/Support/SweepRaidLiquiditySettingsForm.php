<?php

namespace App\Support;

use App\Services\Indicators\SweepRaidLiquidityIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Валидирует настройки самостоятельного индикатора, не смешивая их с LuxAlgo.
 */
class SweepRaidLiquiditySettingsForm
{
    /** @return array<string,mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'pivot_length' => ['required', 'integer', 'min:1', 'max:100'],
            'breakout_closes' => ['required', 'integer', 'min:1', 'max:5'],
            'color_fractal_high' => ['required', 'string', 'max:32'],
            'color_fractal_low' => ['required', 'string', 'max:32'],
            'color_sweep' => ['required', 'string', 'max:32'],
            'color_raid' => ['required', 'string', 'max:32'],
            'color_pullback' => ['required', 'string', 'max:32'],
            'color_breakout' => ['required', 'string', 'max:32'],
            'label_size' => ['required', Rule::in(['tiny', 'small', 'normal'])],
        ]);

        return array_replace(SweepRaidLiquidityIndicatorService::defaultParams(), [
            'pivot_length' => (int) $validated['pivot_length'],
            'show_fractals' => $request->boolean('show_fractals'),
            'show_sweeps' => $request->boolean('show_sweeps'),
            'show_raids' => $request->boolean('show_raids'),
            'show_pullback_entries' => $request->boolean('show_pullback_entries'),
            'show_breakout_entries' => $request->boolean('show_breakout_entries'),
            'breakout_closes' => (int) $validated['breakout_closes'],
            'color_fractal_high' => trim((string) $validated['color_fractal_high']),
            'color_fractal_low' => trim((string) $validated['color_fractal_low']),
            'color_sweep' => trim((string) $validated['color_sweep']),
            'color_raid' => trim((string) $validated['color_raid']),
            'color_pullback' => trim((string) $validated['color_pullback']),
            'color_breakout' => trim((string) $validated['color_breakout']),
            'label_size' => (string) $validated['label_size'],
        ]);
    }
}
