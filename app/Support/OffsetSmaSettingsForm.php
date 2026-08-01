<?php

namespace App\Support;

use App\Services\Indicators\OffsetSmaIndicatorService;
use Illuminate\Http\Request;

/** Форма OffsetSma — Sma length, Sma offset, цвет. */
class OffsetSmaSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'offset' => ['required', 'integer', 'min:0', 'max:500'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(OffsetSmaIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'offset' => (int) $validated['offset'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
