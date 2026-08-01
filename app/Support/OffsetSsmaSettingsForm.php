<?php

namespace App\Support;

use App\Services\Indicators\OffsetSsmaIndicatorService;
use App\Services\Indicators\OsEngineCandleMath;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Форма Offset SSMA — Length / Offset / Candle Point + стиль.
 * API: validate() → merge() → forView(); buildFromRequest() — совместимость с остальными формами.
 */
class OffsetSsmaSettingsForm
{
    /**
     * Правила валидации HTTP-полей (без boolean show_* — их читает merge через boolean()).
     *
     * @return array<string, mixed>
     */
    public static function validate(Request $request): array
    {
        return $request->validate([
            'length' => ['required', 'integer', 'min:1', 'max:500'],
            'offset' => ['required', 'integer', 'min:0', 'max:500'],
            'candle_point' => ['required', 'string', Rule::in(OsEngineCandleMath::CANDLE_POINTS)],
            'color' => ['required', 'string', 'max:32'],
            'line_width' => ['required', 'integer', 'min:1', 'max:8'],
        ]);
    }

    /**
     * Слияние валидированных полей с дефолтами сервиса.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function merge(array $validated, Request $request): array
    {
        return array_replace(OffsetSsmaIndicatorService::defaultParams(), [
            'length' => (int) $validated['length'],
            'offset' => (int) $validated['offset'],
            'candle_point' => (string) $validated['candle_point'],
            'show_line' => $request->boolean('show_line'),
            'color' => trim((string) $validated['color']),
            'line_width' => (int) $validated['line_width'],
        ]);
    }

    /**
     * Параметры для blade/API: defaults + сохранённые настройки бота.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function forView(?array $stored = null): array
    {
        return array_replace(OffsetSsmaIndicatorService::defaultParams(), $stored ?? []);
    }

    /** @return array<string, mixed> */
    public static function buildFromRequest(Request $request): array
    {
        return self::merge(self::validate($request), $request);
    }
}
