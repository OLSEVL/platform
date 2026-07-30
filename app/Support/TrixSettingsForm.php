<?php

namespace App\Support;

use App\Services\Indicators\OsEngineCandleMath;
use App\Services\Indicators\TrixIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма Trix — Length, Candle point. */
class TrixSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'candle_point' => ['required', 'string', Rule::in(OsEngineCandleMath::CANDLE_POINTS)],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_zero' => ['required', 'numeric'],
        ]);

        return array_replace(TrixIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'candle_point' => (string) $validated['candle_point'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
            'show_levels' => $request->boolean('show_levels'),
            'level_zero' => (float) $validated['level_zero'],
        ]);
    }
}