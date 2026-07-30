<?php

namespace App\Support;

use App\Services\Indicators\KalmanIndicatorService;
use Illuminate\Http\Request;

/** Форма KalmanFilter — Sharpness и K. */
class KalmanSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'sharpness' => ['required', 'numeric', 'min:0.0001', 'max:1000'],
            'k' => ['required', 'numeric', 'min:0.0001', 'max:1000'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(KalmanIndicatorService::defaultParams(), [
            'sharpness' => (float) $validated['sharpness'],
            'k' => (float) $validated['k'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}