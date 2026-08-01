<?php

namespace App\Support;

use App\Services\Indicators\NrtrWatrIndicatorService;
use Illuminate\Http\Request;

/** Форма NRTR_WATR — Length, ATR multiplicator, цвет линии. */
class NrtrWatrSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'atr_multiplicator' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(NrtrWatrIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'atr_multiplicator' => (float) $validated['atr_multiplicator'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }
}
