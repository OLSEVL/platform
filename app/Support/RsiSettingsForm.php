<?php

namespace App\Support;

use App\Services\Indicators\RsiIndicatorService;
use Illuminate\Http\Request;

/** Форма RSI — Length + стиль + уровни 70/30 для pane. */
class RsiSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_overbought' => ['required', 'numeric', 'min:0', 'max:100'],
            'level_oversold' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return array_replace(RsiIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
            'level_overbought' => (float) $validated['level_overbought'],
            'level_oversold' => (float) $validated['level_oversold'],
            'show_levels' => $request->boolean('show_levels'),
        ]);
    }
}
