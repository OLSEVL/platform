<?php

namespace App\Support;

use App\Services\Indicators\IchimokuIndicatorService;
use Illuminate\Http\Request;

/** Форма Ichimoku — Tenkan/Kijun/Senkou/Chinkou параметры и видимость линий. */
class IchimokuSettingsForm
{
    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'tenkan' => ['required', 'integer', 'min:1', 'max:500'],
            'kijun' => ['required', 'integer', 'min:1', 'max:500'],
            'senkou_b' => ['required', 'integer', 'min:1', 'max:500'],
            'displacement' => ['required', 'integer', 'min:0', 'max:500'],
            'chinkou_span' => ['required', 'integer', 'min:0', 'max:500'],
            'color_tenkan' => ['required', 'string', 'max:32'],
            'color_kijun' => ['required', 'string', 'max:32'],
            'color_senkou_a' => ['required', 'string', 'max:32'],
            'color_senkou_b' => ['required', 'string', 'max:32'],
            'color_chinkou' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
            'chinkou_point_radius' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        return array_replace(IchimokuIndicatorService::defaultParams(), [
            'tenkan' => (int) $validated['tenkan'],
            'kijun' => (int) $validated['kijun'],
            'senkou_b' => (int) $validated['senkou_b'],
            'displacement' => (int) $validated['displacement'],
            'chinkou_span' => (int) $validated['chinkou_span'],
            'show_tenkan' => $request->boolean('show_tenkan'),
            'show_kijun' => $request->boolean('show_kijun'),
            'show_senkou_a' => $request->boolean('show_senkou_a'),
            'show_senkou_b' => $request->boolean('show_senkou_b'),
            'show_chinkou' => $request->boolean('show_chinkou'),
            'color_tenkan' => trim((string) $validated['color_tenkan']),
            'color_kijun' => trim((string) $validated['color_kijun']),
            'color_senkou_a' => trim((string) $validated['color_senkou_a']),
            'color_senkou_b' => trim((string) $validated['color_senkou_b']),
            'color_chinkou' => trim((string) $validated['color_chinkou']),
            'line_width' => (int) $validated['line_width'],
            'chinkou_point_radius' => (int) $validated['chinkou_point_radius'],
        ]);
    }
}
