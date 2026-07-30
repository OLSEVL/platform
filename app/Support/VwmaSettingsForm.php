<?php

namespace App\Support;

use App\Services\Indicators\OsEngineCandleMath;
use App\Services\Indicators\VwmaIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма VWMA — Length + Candle Point + стиль (OsEngine VWMA.cs). */
class VwmaSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'candle_point' => ['required', Rule::in(OsEngineCandleMath::CANDLE_POINTS)],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(VwmaIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'candle_point' => (string) $validated['candle_point'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
