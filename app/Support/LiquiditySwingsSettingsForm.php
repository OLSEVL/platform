<?php

namespace App\Support;

use App\Services\Indicators\LiquiditySwingsIndicatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Разбор HTTP-запроса страницы настроек Liquidity Swings в массив параметров сервиса.
 * Intrabar Precision в форме отсутствует — на сервере не поддерживается.
 */
class LiquiditySwingsSettingsForm
{
    /**
     * @return array<string, mixed>
     */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'area' => ['required', Rule::in(['wick', 'full'])],
            'filter_by' => ['required', Rule::in(['count', 'volume'])],
            'filter_value' => ['required', 'numeric'],
            'color_high_line' => ['required', 'string', 'max:32'],
            'color_high_fill' => ['required', 'string', 'max:64'],
            'color_low_line' => ['required', 'string', 'max:32'],
            'color_low_fill' => ['required', 'string', 'max:64'],
            'label_size' => ['required', Rule::in(['tiny', 'small', 'normal'])],
        ]);

        $defaults = LiquiditySwingsIndicatorService::defaultParams();

        return array_replace($defaults, [
            'length' => (int) $validated['length'],
            'area' => (string) $validated['area'],
            'filter_by' => (string) $validated['filter_by'],
            'filter_value' => (float) $validated['filter_value'],
            'show_swing_high' => $request->boolean('show_swing_high'),
            'show_swing_low' => $request->boolean('show_swing_low'),
            'color_high_line' => trim((string) $validated['color_high_line']),
            'color_high_fill' => trim((string) $validated['color_high_fill']),
            'color_low_line' => trim((string) $validated['color_low_line']),
            'color_low_fill' => trim((string) $validated['color_low_fill']),
            'label_size' => (string) $validated['label_size'],
        ]);
    }
}
