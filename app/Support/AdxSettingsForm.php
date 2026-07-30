<?php

namespace App\Support;

use App\Services\Indicators\AdxIndicatorService;
use Illuminate\Http\Request;

/** Форма ADX — Length, видимость ADX/+DI/−DI, уровни 20/25. */
class AdxSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'color_adx' => ['required', 'string', 'max:32'],
            'color_plus' => ['required', 'string', 'max:32'],
            'color_minus' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'level_weak' => ['required', 'numeric', 'min:0', 'max:100'],
            'level_strong' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return array_replace(AdxIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'show_adx' => $request->boolean('show_adx'),
            'show_plus' => $request->boolean('show_plus'),
            'show_minus' => $request->boolean('show_minus'),
            'color_adx' => trim((string) $validated['color_adx']),
            'color_plus' => trim((string) $validated['color_plus']),
            'color_minus' => trim((string) $validated['color_minus']),
            'line_width' => (int) $validated['line_width'],
            'show_levels' => $request->boolean('show_levels'),
            'level_weak' => (float) $validated['level_weak'],
            'level_strong' => (float) $validated['level_strong'],
        ]);
    }
}
