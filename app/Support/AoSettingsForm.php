<?php

namespace App\Support;

use App\Services\Indicators\AoIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма AO — fast/slow EMA, Typical price, цвета гистограммы. */
class AoSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_fast' => ['required', 'integer', 'min:1', 'max:500'],
            'length_slow' => ['required', 'integer', 'min:1', 'max:500'],
            'candle_point' => ['required', Rule::in(['Open', 'High', 'Low', 'Close', 'Median', 'Typical'])],
            'color_histogram' => ['required', 'string', 'max:32'],
            'color_histogram_neg' => ['required', 'string', 'max:32'],
        ]);

        return array_replace(AoIndicatorService::defaultParams(), [
            'length_fast' => (int) $validated['length_fast'],
            'length_slow' => (int) $validated['length_slow'],
            'candle_point' => (string) $validated['candle_point'],
            'show_histogram' => $request->boolean('show_histogram'),
            'color_histogram' => trim((string) $validated['color_histogram']),
            'color_histogram_neg' => trim((string) $validated['color_histogram_neg']),
        ]);
    }
}
