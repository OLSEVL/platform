<?php

namespace App\Support;

use App\Services\Indicators\NrtrIndicatorService;
use Illuminate\Http\Request;

/** Форма NRTR — Length, Deviation %, center/up/down. */
class NrtrSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'deviation' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'color_center' => ['required', 'string', 'max:32'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(NrtrIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'deviation' => (float) $validated['deviation'],
            'show_center' => $request->boolean('show_center'),
            'show_up' => $request->boolean('show_up'),
            'show_down' => $request->boolean('show_down'),
            'color_center' => trim((string) $validated['color_center']),
            'color_up' => trim((string) $validated['color_up']),
            'color_down' => trim((string) $validated['color_down']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
