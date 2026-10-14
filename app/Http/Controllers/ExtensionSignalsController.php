<?php

namespace App\Http\Controllers;

use App\Models\BotSignal;
use App\Models\PocketInstrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP API для Chrome-расширения: история сигналов (без auth, LAN).
 */
class ExtensionSignalsController extends Controller
{
    /**
     * Последние сигналы для первичной загрузки списка в плавающем окне.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(500, max(1, (int) $request->query('limit', 100)));
        // after_id — лёгкий poll расширения: только сигналы новее уже известных.
        $afterId = max(0, (int) $request->query('after_id', 0));

        $query = BotSignal::query()
            ->with(['bot:id,name,type'])
            ->orderByDesc('id')
            ->limit($limit);
        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query->get(['id', 'bot_id', 'instrument', 'side', 'confidence', 'payout_snapshot', 'signal_at', 'expiry_time', 'reasons_json']);

        // display_name для клика на PO (ищет «EUR/USD OTC», не код EURUSD_otc).
        $codes = $rows->pluck('instrument')->unique()->filter()->values()->all();
        /** @var array<string, string> $displayMap */
        $displayMap = [];
        if ($codes !== []) {
            $displayMap = PocketInstrument::query()
                ->whereIn('instrument', $codes)
                ->pluck('display_name', 'instrument')
                ->map(fn ($v) => is_string($v) ? trim($v) : '')
                ->filter()
                ->all();
        }

        $signals = $rows->map(function (BotSignal $s) use ($displayMap) {
            $reasons = is_array($s->reasons_json) ? $s->reasons_json : [];
            $reasonParts = [];
            foreach (array_slice($reasons, 0, 3) as $r) {
                $t = trim((string) $r);
                if ($t !== '') {
                    $reasonParts[] = $t;
                }
            }
            $expirySec = null;
            if ($s->signal_at && $s->expiry_time) {
                $expirySec = (int) max(0, $s->signal_at->diffInSeconds($s->expiry_time, false));
                if ($expirySec <= 0) {
                    $expirySec = null;
                }
            }
            $code = (string) $s->instrument;
            $display = $displayMap[$code] ?? null;

            return [
                'op' => 'signal',
                'signal_id' => (int) $s->id,
                'bot_id' => (int) $s->bot_id,
                'bot_name' => (string) ($s->bot?->name ?? ''),
                'bot_type' => (string) ($s->bot?->type ?? ''),
                'instrument' => $code,
                'display_name' => $display,
                'side' => strtolower((string) $s->side),
                'confidence' => $s->confidence !== null ? (float) $s->confidence : null,
                'payout' => $s->payout_snapshot !== null ? (float) $s->payout_snapshot : null,
                'expiry_sec' => $expirySec,
                'reason' => $reasonParts !== [] ? implode('; ', $reasonParts) : null,
                'ts' => $s->signal_at?->getTimestamp() ?? $s->created_at?->getTimestamp() ?? time(),
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'signals' => $signals,
        ]);
    }

    /**
     * Резолв display_name по коду инструмента (для клика на PO).
     */
    public function resolve(Request $request): JsonResponse
    {
        $instrument = trim((string) $request->query('instrument', ''));
        if ($instrument === '') {
            return response()->json(['ok' => false, 'error' => 'empty_instrument'], 422);
        }

        $display = PocketInstrument::query()
            ->where('instrument', $instrument)
            ->value('display_name');
        $display = is_string($display) ? trim($display) : '';

        return response()->json([
            'ok' => true,
            'instrument' => $instrument,
            'display_name' => $display !== '' ? $display : null,
        ]);
    }
}
