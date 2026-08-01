<?php

namespace App\Support;

use App\Services\Indicators\PriceChannelOffsetIndicatorService;
use Illuminate\Http\Request;

/** Форма PriceChannelOffset — Length, Offset %, внутренние/внешние линии. */
class PriceChannelOffsetSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'offset_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'color_up_border' => ['required', 'string', 'max:32'],
            'color_down_border' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(PriceChannelOffsetIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'offset_percent' => (float) $validated['offset_percent'],
            'show_up' => $request->boolean('show_up'),
            'show_down' => $request->boolean('show_down'),
            'show_up_border' => $request->boolean('show_up_border'),
            'show_down_border' => $request->boolean('show_down_border'),
            'color_up' => trim((string) $validated['color_up']),
            'color_down' => trim((string) $validated['color_down']),
            'color_up_border' => trim((string) $validated['color_up_border']),
            'color_down_border' => trim((string) $validated['color_down_border']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
