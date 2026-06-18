<?php

namespace App\Support;

use App\Services\Indicators\OrderBlocksIndicatorService;
use Illuminate\Http\Request;

/**
 * Разбор HTTP-запроса страницы настроек ордерблоков в массив параметров сервиса.
 */
class OrderBlocksSettingsForm
{
    /**
     * @return array<string, mixed>
     */
    public static function buildFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'length' => ['required', 'integer', 'min:3', 'max:100'],
            'show_bull' => ['required', 'integer', 'min:0', 'max:20'],
            'show_bear' => ['required', 'integer', 'min:0', 'max:20'],
            'color_bull_bg' => ['required', 'string', 'max:32'],
            'color_bull_bg_alpha' => ['required', 'integer', 'min:0', 'max:100'],
            'color_bull_border' => ['required', 'string', 'max:32'],
            'color_bull_break_bg' => ['required', 'string', 'max:32'],
            'color_bull_break_bg_alpha' => ['required', 'integer', 'min:0', 'max:100'],
            'color_bull_break_border' => ['required', 'string', 'max:32'],
            'color_bear_bg' => ['required', 'string', 'max:32'],
            'color_bear_bg_alpha' => ['required', 'integer', 'min:0', 'max:100'],
            'color_bear_border' => ['required', 'string', 'max:32'],
            'color_bear_break_bg' => ['required', 'string', 'max:32'],
            'color_bear_break_bg_alpha' => ['required', 'integer', 'min:0', 'max:100'],
            'color_bear_break_border' => ['required', 'string', 'max:32'],
        ]);

        $defaults = OrderBlocksIndicatorService::defaultParams();

        return array_replace($defaults, [
            'length' => (int) $validated['length'],
            'show_bull' => (int) $validated['show_bull'],
            'show_bear' => (int) $validated['show_bear'],
            'use_body' => $request->boolean('use_body'),
            'color_bull_bg' => trim((string) $validated['color_bull_bg']),
            'color_bull_bg_alpha' => (int) $validated['color_bull_bg_alpha'],
            'color_bull_border' => trim((string) $validated['color_bull_border']),
            'color_bull_break_bg' => trim((string) $validated['color_bull_break_bg']),
            'color_bull_break_bg_alpha' => (int) $validated['color_bull_break_bg_alpha'],
            'color_bull_break_border' => trim((string) $validated['color_bull_break_border']),
            'color_bear_bg' => trim((string) $validated['color_bear_bg']),
            'color_bear_bg_alpha' => (int) $validated['color_bear_bg_alpha'],
            'color_bear_border' => trim((string) $validated['color_bear_border']),
            'color_bear_break_bg' => trim((string) $validated['color_bear_break_bg']),
            'color_bear_break_bg_alpha' => (int) $validated['color_bear_break_bg_alpha'],
            'color_bear_break_border' => trim((string) $validated['color_bear_break_border']),
        ]);
    }
}
