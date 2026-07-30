<?php

namespace App\Support;

use App\Services\Indicators\AlligatorIndicatorService;
use Illuminate\Http\Request;

/** Форма Alligator — длины/сдвиги Jaw/Teeth/Lips, цвета линий. */
class AlligatorSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length_jaw' => ['required', 'integer', 'min:1', 'max:500'],
            'length_teeth' => ['required', 'integer', 'min:1', 'max:500'],
            'length_lips' => ['required', 'integer', 'min:1', 'max:500'],
            'shift_jaw' => ['required', 'integer', 'min:0', 'max:500'],
            'shift_teeth' => ['required', 'integer', 'min:0', 'max:500'],
            'shift_lips' => ['required', 'integer', 'min:0', 'max:500'],
            'color_jaw' => ['required', 'string', 'max:32'],
            'color_teeth' => ['required', 'string', 'max:32'],
            'color_lips' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(AlligatorIndicatorService::defaultParams(), [
            'length_jaw' => (int) $validated['length_jaw'],
            'length_teeth' => (int) $validated['length_teeth'],
            'length_lips' => (int) $validated['length_lips'],
            'shift_jaw' => (int) $validated['shift_jaw'],
            'shift_teeth' => (int) $validated['shift_teeth'],
            'shift_lips' => (int) $validated['shift_lips'],
            'show_jaw' => $request->boolean('show_jaw'),
            'show_teeth' => $request->boolean('show_teeth'),
            'show_lips' => $request->boolean('show_lips'),
            'color_jaw' => trim((string) $validated['color_jaw']),
            'color_teeth' => trim((string) $validated['color_teeth']),
            'color_lips' => trim((string) $validated['color_lips']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
