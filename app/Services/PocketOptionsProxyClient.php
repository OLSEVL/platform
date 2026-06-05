<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PocketOptionsProxyClient
{
    public function __construct(protected string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @return array{ candles: array, instrument: string, timeframe: string }
     */
    public function getCandles(string $instrument, int $timeframe, string $sessionToken, string $uid, int $count = 100): array
    {
        $response = Http::timeout(30)->get("{$this->baseUrl}/api/candles", [
            'instrument' => $instrument,
            'timeframe' => $timeframe,
            'sessionToken' => $sessionToken,
            'uid' => $uid,
            'count' => $count,
        ]);
        $response->throw();
        $data = $response->json();
        $candles = $data['candles'] ?? [];
        $normalized = [];
        foreach ($candles as $c) {
            if (is_array($c) && isset($c[0])) {
                $normalized[] = ['time' => $c[0] ?? 0, 'open' => (float) ($c[1] ?? 0), 'high' => (float) ($c[2] ?? 0), 'low' => (float) ($c[3] ?? 0), 'close' => (float) ($c[4] ?? 0), 'volume' => (float) ($c[5] ?? 0)];
            } else {
                $normalized[] = ['time' => $c['time'] ?? 0, 'open' => (float) ($c['open'] ?? 0), 'high' => (float) ($c['high'] ?? 0), 'low' => (float) ($c['low'] ?? 0), 'close' => (float) ($c['close'] ?? 0), 'volume' => (float) ($c['volume'] ?? 0)];
            }
        }
        return ['candles' => $normalized, 'instrument' => $data['instrument'] ?? $instrument, 'timeframe' => (string) ($data['timeframe'] ?? $timeframe)];
    }

    /**
     * Зеркало paper-сделки: один ордер на Pocket Option через proxy.
     *
     * @return array{ok: bool, order_id: ?string, status: ?string, instrument?: string, side?: string, duration?: int, error?: string}
     */
    /**
     * Проверка SSID без размещения ордера (auth + WebSocket).
     *
     * @return array{ok: bool, error?: string}
     */
    public function testAuth(string $ssid, bool $isDemo = true, ?string $proxyUrl = null): array
    {
        $payload = [
            'ssid' => $ssid,
            'is_demo' => $isDemo,
        ];
        if ($proxyUrl !== null && $proxyUrl !== '') {
            $payload['proxy_url'] = $proxyUrl;
        }

        $response = Http::timeout(45)->post("{$this->baseUrl}/api/test-auth", $payload);

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();

            return [
                'ok' => false,
                'error' => is_string($detail) ? $detail : json_encode($detail),
            ];
        }

        $data = $response->json();

        return [
            'ok' => (bool) ($data['ok'] ?? false),
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    public function placeOrder(
        string $ssid,
        string $instrument,
        string $side,
        float $amount,
        int $durationSec,
        bool $isDemo = true,
        ?string $proxyUrl = null,
    ): array {
        $payload = [
            'ssid' => $ssid,
            'instrument' => $instrument,
            'side' => strtoupper($side),
            'amount' => $amount,
            'duration' => $durationSec,
            'is_demo' => $isDemo,
        ];
        if ($proxyUrl !== null && $proxyUrl !== '') {
            $payload['proxy_url'] = $proxyUrl;
        }

        $response = Http::timeout(120)->post("{$this->baseUrl}/api/place-order", $payload);

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();

            return [
                'ok' => false,
                'order_id' => null,
                'status' => null,
                'error' => is_string($detail) ? $detail : json_encode($detail),
            ];
        }

        $data = $response->json();

        return [
            'ok' => (bool) ($data['ok'] ?? false),
            'confirmed' => (bool) ($data['confirmed'] ?? true),
            'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
            'status' => isset($data['status']) ? (string) $data['status'] : null,
            'instrument' => $data['instrument'] ?? $instrument,
            'side' => $data['side'] ?? strtoupper($side),
            'duration' => isset($data['duration']) ? (int) $data['duration'] : $durationSec,
        ];
    }
}
