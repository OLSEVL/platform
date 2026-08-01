<?php

namespace App\Support;

use App\Services\Indicators\MassIndexIndicatorService;
use Illuminate\Http\Request;

/** Форма Mass Index — Length EMA, summing period, уровни 27/26.5. */
class MassIndexSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_ema' => ['required', 'integer', 'min:1', 'max:500'],
            'period_sum' => ['required', 'integer', 'min:1', 'max:500'],
            'level_up' => ['required', 'numeric', 'min:0'],
            'level_down' => ['required', 'numeric', 'min:0'],
            'color_mi' => ['required', 'string', 'max:32'],
            'color_level' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(MassIndexIndicatorService::defaultParams(), [
            'length_ema' => (int) $validated['length_ema'],
            'period_sum' => (int) $validated['period_sum'],
            'level_up' => (float) $validated['level_up'],
            'level_down' => (float) $validated['level_down'],
            'show_mi' => $request->boolean('show_mi'),
            'show_level_up' => $request->boolean('show_level_up'),
            'show_level_down' => $request->boolean('show_level_down'),
            'color_mi' => trim((string) $validated['color_mi']),
            'color_level' => trim((string) $validated['color_level']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
