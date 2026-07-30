<?php

namespace App\Support;

use App\Services\Indicators\PriceChannelIndicatorService;
use Illuminate\Http\Request;

/** Форма PriceChannel — Length up/down, цвета границ. */
class PriceChannelSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_up' => ['required', 'integer', 'min:1', 'max:500'],
            'length_down' => ['required', 'integer', 'min:1', 'max:500'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(PriceChannelIndicatorService::defaultParams(), [
            'length_up' => (int) $validated['length_up'],
            'length_down' => (int) $validated['length_down'],
            'show_up' => $request->boolean('show_up'),
            'show_down' => $request->boolean('show_down'),
            'color_up' => trim((string) $validated['color_up']),
            'color_down' => trim((string) $validated['color_down']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
