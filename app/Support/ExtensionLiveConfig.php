<?php

namespace App\Support;

/**
 * Конфиг Chrome-расширения сигналов: Redis pub/sub → WS hub /extension-live.
 * Auth нет (LAN/быстрый старт); путь /extension-live фиксирован.
 */
class ExtensionLiveConfig
{
    public const REDIS_CHANNEL = 'extension:signals';

    public const WS_PATH = '/extension-live';

    /**
     * Публичный WS URL (быстрый старт: ws://host:8080/extension-live).
     */
    public static function hubPublicUrl(): string
    {
        $explicit = trim((string) config('extension.live_ws_url', env('EXTENSION_LIVE_WS_URL', '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');
        if ($appUrl === '') {
            return self::WS_PATH;
        }

        $parts = parse_url($appUrl);
        $scheme = (($parts['scheme'] ?? 'http') === 'https') ? 'wss' : 'ws';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.self::WS_PATH;
    }
}
