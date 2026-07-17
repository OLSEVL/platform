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
     * Успех также регистрирует sticky warm-сессию на стороне proxy.
     *
     * @return array{ok: bool, error?: string|null, warm?: bool, connected?: bool}
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
            'warm' => (bool) ($data['warm'] ?? false),
            'connected' => (bool) ($data['connected'] ?? false),
        ];
    }

    /**
     * Прогрев sticky WS-канала зеркала (connect + keepalive registration).
     *
     * @return array{ok: bool, warm?: bool, connected?: bool, latency_ms?: int, error?: string|null}
     */
    public function warmClient(string $ssid, bool $isDemo = true, ?string $proxyUrl = null): array
    {
        $payload = [
            'ssid' => $ssid,
            'is_demo' => $isDemo,
        ];
        if ($proxyUrl !== null && $proxyUrl !== '') {
            $payload['proxy_url'] = $proxyUrl;
        }

        $response = Http::timeout(60)->post("{$this->baseUrl}/api/warm-client", $payload);

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();

            return [
                'ok' => false,
                'error' => is_string($detail) ? $detail : json_encode($detail),
            ];
        }

        $data = $response->json() ?? [];

        return [
            'ok' => (bool) ($data['ok'] ?? false),
            'warm' => (bool) ($data['warm'] ?? false),
            'connected' => (bool) ($data['connected'] ?? false),
            'latency_ms' => isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * Снять сессию с keepalive (зеркалирование выключили).
     *
     * @return array{ok: bool, removed?: bool, error?: string|null}
     */
    public function unwarmClient(string $ssid, bool $isDemo = true, ?string $proxyUrl = null): array
    {
        $payload = [
            'ssid' => $ssid,
            'is_demo' => $isDemo,
        ];
        if ($proxyUrl !== null && $proxyUrl !== '') {
            $payload['proxy_url'] = $proxyUrl;
        }

        $response = Http::timeout(15)->post("{$this->baseUrl}/api/unwarm-client", $payload);

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();

            return [
                'ok' => false,
                'error' => is_string($detail) ? $detail : json_encode($detail),
            ];
        }

        $data = $response->json() ?? [];

        return [
            'ok' => (bool) ($data['ok'] ?? true),
            'removed' => (bool) ($data['removed'] ?? false),
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * Статус warm без нового connect (для UI).
     *
     * @return array{ok: bool, state?: string, connected?: bool, registered?: bool, error?: string|null}
     */
    public function clientStatus(string $ssid, bool $isDemo = true, ?string $proxyUrl = null): array
    {
        $payload = [
            'ssid' => $ssid,
            'is_demo' => $isDemo,
        ];
        if ($proxyUrl !== null && $proxyUrl !== '') {
            $payload['proxy_url'] = $proxyUrl;
        }

        $response = Http::timeout(10)->post("{$this->baseUrl}/api/client-status", $payload);

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();

            return [
                'ok' => false,
                'state' => 'cold',
                'connected' => false,
                'registered' => false,
                'error' => is_string($detail) ? $detail : json_encode($detail),
            ];
        }

        $data = $response->json() ?? [];

        return [
            'ok' => (bool) ($data['ok'] ?? false),
            'state' => (string) ($data['state'] ?? 'cold'),
            'connected' => (bool) ($data['connected'] ?? false),
            'registered' => (bool) ($data['registered'] ?? false),
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
            'confirmed_via' => isset($data['confirmed_via']) ? (string) $data['confirmed_via'] : null,
            'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
            'status' => isset($data['status']) ? (string) $data['status'] : null,
            'instrument' => $data['instrument'] ?? $instrument,
            'side' => $data['side'] ?? strtoupper($side),
            'duration' => isset($data['duration']) ? (int) $data['duration'] : $durationSec,
        ];
    }

    /**
     * Активные (ещё не истёкшие) ордера на PO — тот же WebSocket-клиент, что и place-order.
     *
     * @return array{ok:bool,count:int,orders?:list<array<string,mixed>>,error?:string}
     */
    public function getActiveOrders(string $ssid, bool $isDemo = true, ?string $proxyUrl = null): array
    {
        $payload = [
            'ssid' => $ssid,
            'is_demo' => $isDemo,
        ];
        if ($proxyUrl !== null && $proxyUrl !== '') {
            $payload['proxy_url'] = $proxyUrl;
        }

        try {
            $response = Http::timeout(30)->post("{$this->baseUrl}/api/active-orders", $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'count' => 0, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $detail = $response->json('detail') ?? $response->body();

            return [
                'ok' => false,
                'count' => 0,
                'error' => is_string($detail) ? $detail : json_encode($detail),
            ];
        }

        $data = $response->json();

        return [
            'ok' => (bool) ($data['ok'] ?? false),
            'count' => (int) ($data['count'] ?? 0),
            'orders' => is_array($data['orders'] ?? null) ? $data['orders'] : [],
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * Итог закрытой сделки на PO (win/loss) из кэша successcloseOrder.
     *
     * @return array{
     *   ok: bool,
     *   found?: bool,
     *   completed?: bool,
     *   result?: string|null,
     *   profit?: float|null,
     *   order_id?: string|null,
     *   deal_id?: string|null,
     *   request_id?: string|null,
     *   source?: string|null,
     *   error?: string|null
     * }
     */
    public function getOrderResult(
        string $ssid,
        string $orderId,
        bool $isDemo = true,
        ?string $proxyUrl = null,
    ): array {
        $payload = [
            'ssid' => $ssid,
            'order_id' => $orderId,
            'is_demo' => $isDemo,
        ];
        if ($proxyUrl !== null && $proxyUrl !== '') {
            $payload['proxy_url'] = $proxyUrl;
        }

        try {
            $response = Http::timeout(20)->post("{$this->baseUrl}/api/order-result", $payload);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'found' => false,
                'completed' => false,
                'error' => $e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            $detail = $response->json('detail') ?? $response->body();

            return [
                'ok' => false,
                'found' => false,
                'completed' => false,
                'error' => is_string($detail) ? $detail : json_encode($detail),
            ];
        }

        $data = $response->json() ?? [];

        return [
            'ok' => (bool) ($data['ok'] ?? false),
            'found' => (bool) ($data['found'] ?? false),
            'completed' => (bool) ($data['completed'] ?? false),
            'result' => isset($data['result']) ? (string) $data['result'] : null,
            'profit' => isset($data['profit']) && is_numeric($data['profit']) ? (float) $data['profit'] : null,
            'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
            'deal_id' => isset($data['deal_id']) ? (string) $data['deal_id'] : null,
            'request_id' => isset($data['request_id']) ? (string) $data['request_id'] : null,
            'source' => isset($data['source']) ? (string) $data['source'] : null,
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }
}
