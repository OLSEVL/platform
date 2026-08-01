<?php

namespace App\Support;

use App\Services\Indicators\AcIndicatorService;
use Illuminate\Http\Request;

/** Форма AC — длины long/short, цвета гистограммы. */
class AcSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_long' => ['required', 'integer', 'min:1', 'max:500'],
            'length_short' => ['required', 'integer', 'min:1', 'max:500'],
            'color_histogram' => ['required', 'string', 'max:32'],
            'color_histogram_neg' => ['required', 'string', 'max:32'],
        ]);

        return array_replace(AcIndicatorService::defaultParams(), [
            'length_long' => (int) $validated['length_long'],
            'length_short' => (int) $validated['length_short'],
            'show_histogram' => $request->boolean('show_histogram'),
            'color_histogram' => trim((string) $validated['color_histogram']),
            'color_histogram_neg' => trim((string) $validated['color_histogram_neg']),
        ]);
    }
}
