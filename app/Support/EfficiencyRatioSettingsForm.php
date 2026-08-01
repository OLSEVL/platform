<?php

namespace App\Support;

use App\Services\Indicators\EfficiencyRatioIndicatorService;
use Illuminate\Http\Request;

/** Форма Efficiency Ratio — Length, цвет линии. */
class EfficiencyRatioSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(EfficiencyRatioIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
