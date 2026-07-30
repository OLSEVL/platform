<?php

namespace App\Support;

use App\Services\Indicators\IvashovRangeIndicatorService;
use Illuminate\Http\Request;

/** Форма IvashovRange — Length MA и Length average range. */
class IvashovRangeSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_ma' => ['required', 'integer', 'min:1', 'max:2000'],
            'length_avg' => ['required', 'integer', 'min:1', 'max:2000'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(IvashovRangeIndicatorService::defaultParams(), [
            'length_ma' => (int) $validated['length_ma'],
            'length_avg' => (int) $validated['length_avg'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}