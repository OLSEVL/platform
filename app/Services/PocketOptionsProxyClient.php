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
}
