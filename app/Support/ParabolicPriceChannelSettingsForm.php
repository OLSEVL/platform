<?php

namespace App\Support;

use App\Services\Indicators\ParabolicPriceChannelIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма ParabolicPriceChannel — Length up/down + волатильность. */
class ParabolicPriceChannelSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_up' => ['required', 'integer', 'min:1', 'max:500'],
            'length_down' => ['required', 'integer', 'min:1', 'max:500'],
            'volatility_period' => ['required', 'string', Rule::in(['Day', 'Week', 'Month'])],
            'averaging_period' => ['required', 'integer', 'min:1', 'max:500'],
            'volatility_multi' => ['required', 'numeric', 'min:0.001', 'max:10'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'color_parabolic' => ['required', 'string', 'max:32'],
            'color_volatility' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'point_radius' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(ParabolicPriceChannelIndicatorService::defaultParams(), [
            'length_up' => (int) $validated['length_up'],
            'length_down' => (int) $validated['length_down'],
            'volatility_period' => (string) $validated['volatility_period'],
            'averaging_period' => (int) $validated['averaging_period'],
            'volatility_multi' => (float) $validated['volatility_multi'],
            'show_up' => $request->boolean('show_up'),
            'show_down' => $request->boolean('show_down'),
            'show_parabolic' => $request->boolean('show_parabolic'),
            'show_volatility' => $request->boolean('show_volatility'),
            'color_up' => trim((string) $validated['color_up']),
            'color_down' => trim((string) $validated['color_down']),
            'color_parabolic' => trim((string) $validated['color_parabolic']),
            'color_volatility' => trim((string) $validated['color_volatility']),
            'line_width' => (int) $validated['line_width'],
            'point_radius' => (int) $validated['point_radius'],
        ]);
    }
}
