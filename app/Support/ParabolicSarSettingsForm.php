<?php

namespace App\Support;

use App\Services\Indicators\ParabolicSarIndicatorService;
use Illuminate\Http\Request;

/** Форма Parabolic SAR — Step / MaxStep + цвет точек. */
class ParabolicSarSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'step' => ['required', 'numeric', 'min:0.001', 'max:1'],
            'max_step' => ['required', 'numeric', 'min:0.001', 'max:1'],
            'color' => ['required', 'string', 'max:32'],
            'point_radius' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(ParabolicSarIndicatorService::defaultParams(), [
            'step' => (float) $validated['step'],
            'max_step' => (float) $validated['max_step'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'point_radius' => (int) $validated['point_radius'],
        ]);
    }
}
