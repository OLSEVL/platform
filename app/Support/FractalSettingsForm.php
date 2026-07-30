<?php

namespace App\Support;

use App\Services\Indicators\FractalIndicatorService;
use Illuminate\Http\Request;

/** Форма Fractal — цвета up/down маркеров. */
class FractalSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'color_up' => ['required', 'string', 'max:32'],
            'color_down' => ['required', 'string', 'max:32'],
        ]);

        return array_replace(FractalIndicatorService::defaultParams(), [
            'show_up' => $request->boolean('show_up'),
            'show_down' => $request->boolean('show_down'),
            'color_up' => trim((string) $validated['color_up']),
            'color_down' => trim((string) $validated['color_down']),
        ]);
    }
}