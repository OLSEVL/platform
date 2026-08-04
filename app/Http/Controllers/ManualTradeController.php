<?php

namespace App\Http\Controllers;

use App\Services\Trading\ManualPocketOrderService;
use App\Support\MartingaleAmountsParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ручной вход CALL/PUT на Pocket Option с главного графика.
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

        return response()->json($result, $status);
    }
}
