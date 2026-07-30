<?php

namespace App\Support;

use App\Services\Indicators\LinRegIndicatorService;
use App\Services\Indicators\OsEngineCandleMath;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма Linear Regression Line. */
class LinRegSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'integer', 'min:2', 'max:2000'],
            'candle_point' => ['required', 'string', Rule::in(OsEngineCandleMath::CANDLE_POINTS)],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(LinRegIndicatorService::defaultParams(), [
            'period' => (int) $validated['period'],
            'candle_point' => (string) $validated['candle_point'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}