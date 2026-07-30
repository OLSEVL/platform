<?php

namespace App\Support;

use App\Services\Indicators\CciIndicatorService;
use App\Services\Indicators\OsEngineCandleMath;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма CCI — Length + Candle Point + стиль + уровни ±100. */
class CciSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'candle_point' => ['required', 'string', Rule::in(OsEngineCandleMath::CANDLE_POINTS)],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric', 'min:-500', 'max:500'],
            'level_oversold' => ['required', 'numeric', 'min:-500', 'max:500'],
        ]);

        return array_replace(CciIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'candle_point' => (string) $validated['candle_point'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
            'level_overbought' => (float) $validated['level_overbought'],
            'level_oversold' => (float) $validated['level_oversold'],
            'show_levels' => $request->boolean('show_levels'),
        ]);
    }
}
