<?php

namespace App\Support;

use App\Services\Indicators\EnvelopsIndicatorService;
use Illuminate\Http\Request;

/** Форма Envelops — Length, Deviation (%), стиль линий канала. */
class EnvelopsSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'deviation' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_mid' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(EnvelopsIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'deviation' => (float) $validated['deviation'],
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
