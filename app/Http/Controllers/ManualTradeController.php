<?php

namespace App\Http\Controllers;

use App\Services\Trading\ManualPocketOrderService;
use App\Support\MartingaleAmountsParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ручной вход CALL/PUT на Pocket Option с главного графика + тумблеры панели.
 */
class ManualTradeController extends Controller
{
    public function store(Request $request, ManualPocketOrderService $orders): JsonResponse
    {
        $validated = $request->validate([
            'instrument' => ['required', 'string', 'max:128'],
            'side' => ['required', 'string', 'in:CALL,PUT,call,put'],
            'amount' => [
                'required',
                'numeric',
                'min:'.MartingaleAmountsParser::MIN_AMOUNT,
                'max:'.MartingaleAmountsParser::MAX_AMOUNT,
            ],
            'duration' => [
                'required',
                'integer',
                'min:'.ManualPocketOrderService::MIN_DURATION_SEC,
                'max:'.ManualPocketOrderService::MAX_DURATION_SEC,
            ],
        ]);

        $result = $orders->place(
            user: $request->user(),
            instrument: (string) $validated['instrument'],
            side: (string) $validated['side'],
            amount: (float) $validated['amount'],
            durationSec: (int) $validated['duration'],
        );

        $status = ($result['ok'] ?? false) ? 200 : 422;
        // Ошибка прокси / сети — отдаём 503, чтобы UI отличал от валидации.
        if (! ($result['ok'] ?? false) && ($result['code'] ?? '') === 'place_failed') {
            $status = 503;
        }
        // Wait previous — 409 Conflict (сделка не открыта, но это не валидация полей).
        if (! ($result['ok'] ?? false) && ($result['code'] ?? '') === 'wait_previous') {
            $status = 409;
        }

        return response()->json($result, $status);
    }

    /** Сохранение трёх тумблеров ручной торговли из модалки на главной. */
    public function updateSettings(Request $request, ManualPocketOrderService $orders): JsonResponse
    {
        $validated = $request->validate([
            'manual_respect_wait_previous' => ['required', 'boolean'],
            'manual_advance_martingale' => ['required', 'boolean'],
            'manual_amount_from_martingale' => ['required', 'boolean'],
        ]);

        $payload = $orders->updateFlags($request->user(), [
            'manual_respect_wait_previous' => (bool) $validated['manual_respect_wait_previous'],
            'manual_advance_martingale' => (bool) $validated['manual_advance_martingale'],
            'manual_amount_from_martingale' => (bool) $validated['manual_amount_from_martingale'],
        ]);

        return response()->json($payload);
    }
}
