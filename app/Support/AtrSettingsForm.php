<?php

namespace App\Support;

use App\Services\Indicators\AtrIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма ATR — Length + Series type Absolute|Percent + стиль. */
class AtrSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'series_type' => ['required', Rule::in(['Absolute', 'Percent'])],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(AtrIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'series_type' => (string) $validated['series_type'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
