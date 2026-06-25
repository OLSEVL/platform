<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Services\CandleService;
use App\Services\CurrentCandleCacheService;
use App\Services\ProxyDeadProbeService;
use App\Services\ProxyRotationService;
use App\Support\FeedTimeNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProxyInstrumentController extends Controller
{
    public function poolVersion(ProxyRotationService $proxyRotation): JsonResponse
    {
        return response()->json([
            'version' => $proxyRotation->getPoolVersion(),
        ]);
    }

    public function instruments(ProxyRotationService $proxyRotation, ProxyDeadProbeService $deadProbe): JsonResponse
    {
        $autoRotationEnabled = $proxyRotation->isAutoRotationEnabled();

        // Активные инструменты + контрольная пара во время dead-пробы (даже если is_active=false).
        $probeLock = $deadProbe->getActiveLock();
        $probeInstrumentId = (int) ($probeLock['instrument_id'] ?? 0);

        $list = PocketInstrument::query()
            ->where(function ($query) use ($probeInstrumentId): void {
                $query->where('is_active', true);
                if ($probeInstrumentId > 0) {
                    $query->orWhere('id', $probeInstrumentId);
                }
            })
            ->with('proxyEndpoint')
            ->orderByPriority()
            ->get()
            ->map(function (PocketInstrument $r) use ($autoRotationEnabled, $proxyRotation, $deadProbe) {
                // Во время авто-пробы dead-прокси инструмент временно на dead endpoint:
                // авто-ротация не должна откатывать назначение до окончания окна ожидания PO/WS.
                if ($autoRotationEnabled && ! $deadProbe->isInstrumentProbeLocked($r)) {
                    // Сначала stale PO → dead+ротация (если включено в настройках), затем снятие с уже dead endpoint.
                    $proxyRotation->handleStalePoProxy($r);
                    $proxyRotation->ensureInstrumentHasReadyProxy($r);
                    $r->load('proxyEndpoint');
                }

                return [
                'instrument' => $r->instrument,
                'display_name' => $r->display_name,
                'priority' => $r->priority,
                'session_token' => $r->session_token,
                'uid' => $r->uid,
                'is_demo' => $r->is_demo,
                // Сохраняем поле proxy_url в API для обратной совместимости ingest-кода.
                // Источник теперь новый: relation proxyEndpoint->url, старого столбца proxy_url больше нет.
                'proxy_url' => $r->proxyEndpoint?->url,
                'current_payout' => $r->current_payout,
                'last_payout_at' => optional($r->last_payout_at)?->toIso8601String(),
                'last_payout_event_at' => optional($r->last_payout_event_at)?->toIso8601String(),
                'payout_is_stale' => (bool) $r->payout_is_stale,
                'payout_source' => $r->payout_source,
            ];
            });

        return response()->json($list->values()->all());
    }

    public function ingestCandles(Request $request): JsonResponse
    {
        $request->validate([
            'instrument' => 'required|string|max:63',
            // Разрешаем ingest как классического M1 (1), так и "точного" секундного
            // таймфрейма S5 (4005). Остальные сек.таймфреймы (S15/S30) строятся агрегацией.
            'timeframe' => 'required|integer|in:1,'.CandleService::TF_S5,
            'candles' => 'required|array',
            'candles.*.time' => 'required|numeric',
            'candles.*.open' => 'required|numeric',
            'candles.*.high' => 'required|numeric',
            'candles.*.low' => 'required|numeric',
            'candles.*.close' => 'required|numeric',
            'candles.*.volume' => 'nullable|numeric',
        ]);
        $instrument = $request->input('instrument');
        $timeframe = (int) $request->input('timeframe');
        $candles = $request->input('candles');
        // Строгая UTC-политика без ручных сдвигов:
        // - принимаем unix-time как "истину" из источника;
        // - сохраняем в БД без +/-(часы);
        // - все последующие слои (API/UI/аналитика) работают с тем же timestamp.
        // Это гарантирует "ноль ручных сдвигов" и исключает каскадные рассинхроны.
        foreach ($candles as $c) {
            $time = max(1, (int) $c['time']);
            InstrumentCandle::updateOrCreate(
                ['instrument' => $instrument, 'timeframe' => $timeframe, 'time' => $time],
                ['open' => (float) $c['open'], 'high' => (float) $c['high'], 'low' => (float) $c['low'], 'close' => (float) $c['close'], 'volume' => (float) ($c['volume'] ?? 0)]
            );
        }

        return response()->json([
            'ok' => true,
            'count' => count($candles),
        ]);
    }

    public function ingestPayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instrument' => 'required|string|max:63',
            'current_payout' => 'nullable|numeric|min:0|max:100',
            // last_payout_at — время успешного POST ingest (heartbeat), не событие PO.
            'last_payout_at' => 'nullable|date',
            // last_payout_event_at — время последнего payout_update/json_data от Pocket Options.
            'last_payout_event_at' => 'nullable|date',
            'payout_source' => 'nullable|string|max:32',
            'is_stale' => 'nullable|boolean',
        ]);

        $instrument = PocketInstrument::query()->where('instrument', $data['instrument'])->first();
        if (! $instrument) {
            return response()->json(['ok' => false, 'error' => 'instrument_not_found'], 404);
        }

        $isStale = (bool) ($data['is_stale'] ?? false);

        $update = [
            'current_payout' => $data['current_payout'] ?? null,
            'payout_source' => $data['payout_source'] ?? null,
            'payout_is_stale' => $isStale,
        ];

        if (array_key_exists('last_payout_at', $data) && $data['last_payout_at'] !== null) {
            $update['last_payout_at'] = $data['last_payout_at'];
        }

        // При свежем событии от PO обновляем момент события; при stale — не затираем «последний живой» timestamp.
        if (! $isStale && array_key_exists('last_payout_event_at', $data) && $data['last_payout_event_at'] !== null) {
            $update['last_payout_event_at'] = $data['last_payout_event_at'];
        }

        $instrument->update($update);

        return response()->json([
            'ok' => true,
            'instrument' => $instrument->instrument,
            'is_stale' => $isStale,
            'payout_is_stale' => $isStale,
        ]);
    }

    public function ingestCurrentCandle(Request $request, CurrentCandleCacheService $cache): JsonResponse
    {
        $data = $request->validate([
            'instrument' => 'required|string|max:63',
            'time' => 'required|integer|min:1',
            // Сырой timestamp как пришёл от feed (для диагностики рассинхрона).
            'raw_feed_time' => 'nullable|integer|min:1',
            // Явный effective timestamp (если ingest применяет нормализацию на своей стороне).
            'effective_feed_time' => 'nullable|integer|min:1',
            'open' => 'required|numeric',
            'high' => 'required|numeric',
            'low' => 'required|numeric',
            'close' => 'required|numeric',
            'volume' => 'nullable|numeric',
            'updated_at' => 'nullable|integer|min:1',
            'ttl_sec' => 'nullable|integer|min:30|max:600',
        ]);

        $ttlSec = (int) ($data['ttl_sec'] ?? 180);
        $data['time'] = max(1, (int) $data['time']);
        $data['raw_feed_time'] = max(0, (int) ($data['raw_feed_time'] ?? 0));
        $data['effective_feed_time'] = max(1, (int) ($data['effective_feed_time'] ?? $data['time']));
        $data['ingest_received_at'] = time();
        // Коррекция оси времени (если ingest без proxy.env); OHLC не трогаем — только timestamp.
        $data = FeedTimeNormalizer::normalizePartial($data);
        $cache->putPartial($data['instrument'], $data, $ttlSec);

        return response()->json([
            'ok' => true,
            'instrument' => $data['instrument'],
            'time' => (int) $data['time'],
            'raw_feed_time' => (int) $data['raw_feed_time'],
            'effective_feed_time' => (int) $data['effective_feed_time'],
            'is_partial' => true,
        ]);
    }

    public function ingestProxyFailure(Request $request, ProxyRotationService $proxyRotation, ProxyDeadProbeService $deadProbe): JsonResponse
    {
        $data = $request->validate([
            'instrument' => 'required|string|max:63',
            'error' => 'required|string|max:1000',
            'streak' => 'nullable|integer|min:1',
        ]);

        $instrument = PocketInstrument::query()
            ->where('instrument', $data['instrument'])
            ->first();

        if (! $instrument) {
            return response()->json(['ok' => false, 'error' => 'instrument_not_found'], 404);
        }

        // В ingest фиксируются факты проблемного подключения через конкретный прокси.
        // Здесь централизованно обновляем health прокси и, если включено,
        // автоматически переключаем инструмент на другой endpoint.
        $this->applyInstrumentWsError($instrument, $data['error']);

        if (! $deadProbe->isInstrumentProbeLocked($instrument)) {
            $proxyRotation->markProxyFailure(
                $instrument,
                $data['error'],
                isset($data['streak']) ? (int) $data['streak'] : null
            );
        }

        return response()->json([
            'ok' => true,
            'instrument' => $instrument->instrument,
            'auto_rotation_enabled' => $proxyRotation->isAutoRotationEnabled(),
            'streak' => (int) ($data['streak'] ?? 0),
        ]);
    }

    public function ingestProxyHealth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instrument' => 'required|string|max:63',
            'event' => 'required|string|in:connected,connect_failed,not_connected_after_wait,connection_lost,worker_exception',
            'error' => 'nullable|string|max:1000',
            'streak' => 'nullable|integer|min:1',
        ]);

        $instrument = PocketInstrument::query()
            ->where('instrument', $data['instrument'])
            ->first();

        if (! $instrument) {
            return response()->json(['ok' => false, 'error' => 'instrument_not_found'], 404);
        }

        $event = (string) $data['event'];

        if ($event === 'connected') {
            $instrument->update([
                'last_ws_connected_at' => now(),
                'last_ws_error' => null,
                'last_ws_error_at' => null,
            ]);
        } else {
            $this->applyInstrumentWsError($instrument, (string) ($data['error'] ?? $event));
        }

        return response()->json([
            'ok' => true,
            'instrument' => $instrument->instrument,
            'event' => $event,
        ]);
    }

    private function applyInstrumentWsError(PocketInstrument $instrument, string $error): void
    {
        $instrument->update([
            'last_ws_error' => mb_substr($error, 0, 1000),
            'last_ws_error_at' => now(),
        ]);
    }
}
