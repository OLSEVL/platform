<?php

namespace App\Support;

use App\Services\Indicators\EngulfingLiquidityIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Валидация настроек оверлея Engulfing Liquidity (отдельно от торгового config бота). */
class EngulfingLiquiditySettingsForm
{
    /** @return array<string,mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'color_step1' => ['required', 'string', 'max:32'],
            'color_step2' => ['required', 'string', 'max:32'],
            'color_step3_call' => ['required', 'string', 'max:32'],
            'color_step3_put' => ['required', 'string', 'max:32'],
            'label_size' => ['required', Rule::in(['tiny', 'small', 'normal'])],
        ]);

        return array_replace(EngulfingLiquidityIndicatorService::defaultParams(), [
            'allow_classic' => $request->boolean('allow_classic'),
            'allow_same_color' => $request->boolean('allow_same_color'),
            'show_step_markers' => $request->boolean('show_step_markers'),
            'show_entries' => $request->boolean('show_entries'),
            'color_step1' => trim((string) $validated['color_step1']),
            'color_step2' => trim((string) $validated['color_step2']),
            'color_step3_call' => trim((string) $validated['color_step3_call']),
            'color_step3_put' => trim((string) $validated['color_step3_put']),
            'label_size' => (string) $validated['label_size'],
        ]);
    }
}
