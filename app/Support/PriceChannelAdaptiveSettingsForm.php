<?php

namespace App\Support;

use App\Services\Indicators\PriceChannelAdaptiveIndicatorService;
use Illuminate\Http\Request;

/** Форма PriceChannelAdaptive — Adx Period, Ratio, цвета up/down. */
class PriceChannelAdaptiveSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'adx_period' => ['required', 'integer', 'min:1', 'max:500'],
            'ratio' => ['required', 'integer', 'min:1', 'max:10000'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'color_length' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(PriceChannelAdaptiveIndicatorService::defaultParams(), [
            'adx_period' => (int) $validated['adx_period'],
            'ratio' => (int) $validated['ratio'],
            'show_up' => $request->boolean('show_up'),
            'show_down' => $request->boolean('show_down'),
            'show_length' => $request->boolean('show_length'),
            'color_up' => trim((string) $validated['color_up']),
            'color_down' => trim((string) $validated['color_down']),
            'color_length' => trim((string) $validated['color_length']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
