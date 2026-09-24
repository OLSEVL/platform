<?php

namespace App\Support;

use App\Services\Indicators\StratAssistantIndicatorService;
use Illuminate\Http\Request;

/** Форма Strat Assistant: типы баров, комбо и цвета маркеров. */
class StratAssistantSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'color_inside' => ['required', 'string', 'max:32'],
            'color_two_up' => ['required', 'string', 'max:32'],
            'color_two_down' => ['required', 'string', 'max:32'],
            'color_outside' => ['required', 'string', 'max:32'],
            'color_combo_bull' => ['required', 'string', 'max:32'],
            'color_combo_bear' => ['required', 'string', 'max:32'],
        ]);

        return array_replace(StratAssistantIndicatorService::defaultParams(), [
            'show_types' => $request->boolean('show_types'),
            'show_combos' => $request->boolean('show_combos'),
            'color_inside' => trim((string) $validated['color_inside']),
            'color_two_up' => trim((string) $validated['color_two_up']),
            'color_two_down' => trim((string) $validated['color_two_down']),
            'color_outside' => trim((string) $validated['color_outside']),
            'color_combo_bull' => trim((string) $validated['color_combo_bull']),
            'color_combo_bear' => trim((string) $validated['color_combo_bear']),
        ]);
    }
}
