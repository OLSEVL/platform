<?php

namespace App\Support;

use App\Services\Indicators\AtrChannelIndicatorService;
use Illuminate\Http\Request;

/** Форма ATR Channel — Length, Multiplier, цвет линии. */
class AtrChannelSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'multiplier' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(AtrChannelIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'multiplier' => (float) $validated['multiplier'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
