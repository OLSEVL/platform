<?php

namespace App\Support;

use App\Services\Indicators\RaviIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма RAVI — fast/slow length, уровни up/down, Candle Point. */
class RaviSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_slow' => ['required', 'integer', 'min:1', 'max:500'],
            'length_fast' => ['required', 'integer', 'min:1', 'max:500'],
            'candle_point' => ['required', Rule::in(['Open', 'High', 'Low', 'Close', 'Median', 'Typical'])],
            'up_line' => ['required', 'numeric'],
            'down_line' => ['required', 'numeric'],
            'color_ravi' => ['required', 'string', 'max:32'],
            'color_up_line' => ['required', 'string', 'max:32'],
            'color_down_line' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(RaviIndicatorService::defaultParams(), [
            'length_slow' => (int) $validated['length_slow'],
            'length_fast' => (int) $validated['length_fast'],
            'candle_point' => (string) $validated['candle_point'],
            'up_line' => (float) $validated['up_line'],
            'down_line' => (float) $validated['down_line'],
            'show_ravi' => $request->boolean('show_ravi'),
            'show_up_line' => $request->boolean('show_up_line'),
            'show_down_line' => $request->boolean('show_down_line'),
            'color_ravi' => trim((string) $validated['color_ravi']),
            'color_up_line' => trim((string) $validated['color_up_line']),
            'color_down_line' => trim((string) $validated['color_down_line']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
