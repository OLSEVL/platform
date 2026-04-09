<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Throwable;

class CurrentCandleCacheService
{
    private const KEY_PREFIX = 'current_candle:';

    public function putPartial(string $instrument, array $candle, int $ttlSec = 180): void
    {
        $payload = [
            'instrument' => $instrument,
            'time' => (int) ($candle['time'] ?? 0),
            'open' => (float) ($candle['open'] ?? 0),
            'high' => (float) ($candle['high'] ?? 0),
            'low' => (float) ($candle['low'] ?? 0),
            'close' => (float) ($candle['close'] ?? 0),
            'volume' => (float) ($candle['volume'] ?? 0),
            'is_partial' => true,
            'updated_at' => (int) ($candle['updated_at'] ?? time()),
        ];

        try {
            Redis::setex($this->key($instrument), $ttlSec, json_encode($payload, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // Redis может быть временно недоступен/не настроен в окружении.
        }
    }

    public function getPartial(string $instrument): ?array
    {
        try {
            $raw = Redis::get($this->key($instrument));
        } catch (Throwable) {
            return null;
        }
        if (!$raw || !is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['time'])) {
            return null;
        }

        return [
            'instrument' => (string) ($decoded['instrument'] ?? $instrument),
            'time' => (int) ($decoded['time'] ?? 0),
            'open' => (float) ($decoded['open'] ?? 0),
            'high' => (float) ($decoded['high'] ?? 0),
            'low' => (float) ($decoded['low'] ?? 0),
            'close' => (float) ($decoded['close'] ?? 0),
            'volume' => (float) ($decoded['volume'] ?? 0),
            'is_partial' => true,
            'updated_at' => (int) ($decoded['updated_at'] ?? 0),
        ];
    }

    public function isFresh(array $partial, int $staleAfterSec): bool
    {
        $updatedAt = (int) ($partial['updated_at'] ?? 0);
        if ($updatedAt <= 0) {
            return false;
        }

        return (time() - $updatedAt) <= $staleAfterSec;
    }

    private function key(string $instrument): string
    {
        return self::KEY_PREFIX.$instrument;
    }
}

