<?php

namespace App\Support;

use App\Services\Indicators\DonchianIndicatorService;
use Illuminate\Http\Request;

/** Форма Donchian Channel — Length, up/mid/down, цвета границ. */
class DonchianSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_mid' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(DonchianIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'show_up' => $request->boolean('show_up'),
            'show_mid' => $request->boolean('show_mid'),
            'show_down' => $request->boolean('show_down'),
            'color_up' => trim((string) $validated['color_up']),
            'color_mid' => trim((string) $validated['color_mid']),
            'color_down' => trim((string) $validated['color_down']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
