<?php

namespace App\Support;

use App\Services\Indicators\PivotFloorIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма Pivot Floor — Period Daily|Weekly, видимость P/R/S, цвета. */
class PivotFloorSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', Rule::in(['Daily', 'Weekly'])],
            'color_p' => ['required', 'string', 'max:32'],
            'color_r' => ['required', 'string', 'max:32'],
            'color_s' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(PivotFloorIndicatorService::defaultParams(), [
            'period' => (string) $validated['period'],
            'show_p' => $request->boolean('show_p'),
            'show_r1' => $request->boolean('show_r1'),
            'show_r2' => $request->boolean('show_r2'),
            'show_r3' => $request->boolean('show_r3'),
            'show_s1' => $request->boolean('show_s1'),
            'show_s2' => $request->boolean('show_s2'),
            'show_s3' => $request->boolean('show_s3'),
            'color_p' => trim((string) $validated['color_p']),
            'color_r' => trim((string) $validated['color_r']),
            'color_s' => trim((string) $validated['color_s']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
