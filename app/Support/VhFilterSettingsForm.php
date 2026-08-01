<?php

namespace App\Support;

use App\Services\Indicators\VhFilterIndicatorService;
use Illuminate\Http\Request;

/** Форма VHFilter — Period, цвет линии. */
class VhFilterSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'integer', 'min:1', 'max:500'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(VhFilterIndicatorService::defaultParams(), [
            'period' => (int) $validated['period'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
