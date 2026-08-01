<?php

namespace App\Support;

use App\Services\Indicators\StochRsiIndicatorService;
use Illuminate\Http\Request;

/** Форма Stochastic RSI — RSI/stoch/K/D, цвета линий, уровни 80/20. */
class StochRsiSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'rsi_length' => ['required', 'integer', 'min:1', 'max:500'],
            'stochastic_length' => ['required', 'integer', 'min:1', 'max:500'],
            'k' => ['required', 'integer', 'min:1', 'max:100'],
            'd' => ['required', 'integer', 'min:1', 'max:100'],
            'color_k' => ['required', 'string', 'max:32'],
            'color_d' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric', 'min:0', 'max:100'],
            'level_oversold' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return array_replace(StochRsiIndicatorService::defaultParams(), [
            'rsi_length' => (int) $validated['rsi_length'],
            'stochastic_length' => (int) $validated['stochastic_length'],
            'k' => (int) $validated['k'],
            'd' => (int) $validated['d'],
            'show_k' => $request->boolean('show_k'),
            'show_d' => $request->boolean('show_d'),
            'color_k' => trim((string) $validated['color_k']),
            'color_d' => trim((string) $validated['color_d']),
            'line_width' => (int) $validated['line_width'],
            'show_levels' => $request->boolean('show_levels'),
            'level_overbought' => (float) $validated['level_overbought'],
            'level_oversold' => (float) $validated['level_oversold'],
        ]);
    }
}
