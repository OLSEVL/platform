<?php

namespace App\Support;

use App\Services\Indicators\LinRegChannelFastIndicatorService;
use App\Services\Indicators\OsEngineCandleMath;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма Linear Regression Channel Fast — период, отклонения, стили up/mid/down. */
class LinRegChannelFastSettingsForm
{
    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'period' => ['required', 'integer', 'min:2', 'max:2000'],
            'candle_point' => ['required', 'string', Rule::in(OsEngineCandleMath::CANDLE_POINTS)],
            'up_deviation' => ['required', 'numeric', 'min:0', 'max:50'],
            'down_deviation' => ['required', 'numeric', 'min:0', 'max:50'],
            'color_up' => ['required', 'string', 'max:32'],
            'color_mid' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function merge(array $validated, Request $request): array
    {
        return array_replace(LinRegChannelFastIndicatorService::defaultParams(), [
            'period' => (int) $validated['period'],
            'candle_point' => (string) $validated['candle_point'],
            'up_deviation' => (float) $validated['up_deviation'],
            'down_deviation' => (float) $validated['down_deviation'],
            'show_up' => $request->boolean('show_up'),
            'show_mid' => $request->boolean('show_mid'),
            'show_down' => $request->boolean('show_down'),
            'color_up' => trim((string) $validated['color_up']),
            'color_mid' => trim((string) $validated['color_mid']),
            'color_down' => trim((string) $validated['color_down']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function forView(?array $stored = null): array
    {
        return array_replace(LinRegChannelFastIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
