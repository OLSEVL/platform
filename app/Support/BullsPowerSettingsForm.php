<?php

namespace App\Support;

use App\Services\Indicators\BullsPowerIndicatorService;
use Illuminate\Http\Request;

/** Форма Bulls Power — Period EMA, цвета гистограммы. */
class BullsPowerSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'integer', 'min:1', 'max:500'],
            'color_histogram' => ['required', 'string', 'max:32'],
            'color_histogram_neg' => ['required', 'string', 'max:32'],
        ]);

        return array_replace(BullsPowerIndicatorService::defaultParams(), [
            'period' => (int) $validated['period'],
            'show_histogram' => $request->boolean('show_histogram'),
            'color_histogram' => trim((string) $validated['color_histogram']),
            'color_histogram_neg' => trim((string) $validated['color_histogram_neg']),
        ]);
    }
}
