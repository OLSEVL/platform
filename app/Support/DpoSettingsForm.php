<?php

namespace App\Support;

use App\Services\Indicators\DpoIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма DPO — Length SMA, Candle Point, цвет линии. */
class DpoSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_sma' => ['required', 'integer', 'min:1', 'max:500'],
            'candle_point' => ['required', Rule::in(['Open', 'High', 'Low', 'Close', 'Median', 'Typical'])],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(DpoIndicatorService::defaultParams(), [
            'length_sma' => (int) $validated['length_sma'],
            'candle_point' => (string) $validated['candle_point'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
