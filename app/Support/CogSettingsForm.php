<?php

namespace App\Support;

use App\Services\Indicators\CogIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма COG — Length, Candle Point, серии cog/prev_cog. */
class CogSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'candle_point' => ['required', Rule::in(['Open', 'High', 'Low', 'Close', 'Median', 'Typical'])],
            'color_cog' => ['required', 'string', 'max:32'],
            'color_prev_cog' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(CogIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'candle_point' => (string) $validated['candle_point'],
            'show_cog' => $request->boolean('show_cog'),
            'show_prev_cog' => $request->boolean('show_prev_cog'),
            'color_cog' => trim((string) $validated['color_cog']),
            'color_prev_cog' => trim((string) $validated['color_prev_cog']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
