<?php

namespace App\Support;

use App\Services\Indicators\RviIndicatorService;
use Illuminate\Http\Request;

/** Форма RVI — Period, две линии. */
class RviSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['required', 'integer', 'min:1', 'max:500'],
            'color_rvi' => ['required', 'string', 'max:32'],
            'color_signal' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(RviIndicatorService::defaultParams(), [
            'period' => (int) $validated['period'],
            'show_rvi' => $request->boolean('show_rvi'),
            'show_signal' => $request->boolean('show_signal'),
            'color_rvi' => trim((string) $validated['color_rvi']),
            'color_signal' => trim((string) $validated['color_signal']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}