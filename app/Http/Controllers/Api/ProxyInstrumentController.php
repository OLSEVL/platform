<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Services\ProxyRotationService;
use App\Services\CurrentCandleCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProxyInstrumentController extends Controller
{
    public function instruments(ProxyRotationService $proxyRotation): JsonResponse
    {
        $autoRotationEnabled = $proxyRotation->isAutoRotationEnabled();

        $list = PocketInstrument::query()
            ->active()
            ->with('proxyEndpoint')
            ->orderByPriority()
            ->get()
            ->map(function (PocketInstrument $r) use ($autoRotationEnabled, $proxyRotation) {
                // При включённой опции авто-ротации гарантируем, что в выдачу попадёт
                // рабочий прокси: это автоматически "вылечивает" инструменты с dead/cooldown/NULL.
                if ($autoRotationEnabled) {
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
                'payout_source' => $r->payout_source,
            ];
            });

        return response()->json($list->values()->all());
    }

    public function ingestCandles(Request $request): JsonResponse
    {
        $request->validate([
            'instrument' => 'required|string|max:63',
            'timeframe' => 'required|integer|in:1',
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
            'last_payout_at' => 'nullable|date',
            'payout_source' => 'nullable|string|max:32',
            'is_stale' => 'nullable|boolean',
        ]);

        $instrument = PocketInstrument::query()->where('instrument', $data['instrument'])->first();
        if (! $instrument) {
            return response()->json(['ok' => false, 'error' => 'instrument_not_found'], 404);
        }

        $instrument->update([
            'current_payout' => $data['current_payout'] ?? null,
            'last_payout_at' => $data['last_payout_at'] ?? null,
            'payout_source' => $data['payout_source'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'instrument' => $instrument->instrument,
            'is_stale' => (bool) ($data['is_stale'] ?? false),
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
        // Частичная свеча должна попадать в тот же UTC timeline, что и закрытые свечи:
        // никаких промежуточных компенсаторов и ручных смещений не применяем.
        $data['time'] = max(1, (int) $data['time']);
        // Для time-debug сохраняем в кэше обе точки зрения:
        // - raw_feed_time: как прислал источник;
        // - effective_feed_time: как этот момент используется платформой.
        $data['raw_feed_time'] = max(0, (int) ($data['raw_feed_time'] ?? 0));
        $data['effective_feed_time'] = max(1, (int) ($data['effective_feed_time'] ?? $data['time']));
        $data['ingest_received_at'] = time();
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

    public function ingestProxyFailure(Request $request, ProxyRotationService $proxyRotation): JsonResponse
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
        $proxyRotation->markProxyFailure(
            $instrument,
            $data['error'],
            isset($data['streak']) ? (int) $data['streak'] : null
        );

        return response()->json([
            'ok' => true,
            'instrument' => $instrument->instrument,
            'auto_rotation_enabled' => $proxyRotation->isAutoRotationEnabled(),
            'streak' => (int) ($data['streak'] ?? 0),
        ]);
    }
}
