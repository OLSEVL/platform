<?php

namespace App\Support;

use App\Services\Indicators\QstickIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Форма QStick — Length, Type MA (SMA|EMA), цвет линии. */
class QstickSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'type_ma' => ['required', Rule::in(['SMA', 'EMA'])],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(QstickIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'type_ma' => (string) $validated['type_ma'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
