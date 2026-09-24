<?php

namespace App\Support;

/**
 * Конфиг live-ленты графика (PR2): Redis pub/sub → WS hub → браузер.
 * Poll /candles остаётся fallback, если hub недоступен или флаг выключен.
 */
class ChartLiveConfig
{
    public const REDIS_CHANNEL = 'chart:s5';

    /** Pub/sub: смена payout → hub fan-out всем WS-клиентам (сайдбар без HTTP poll). */
    public const REDIS_PAYOUT_CHANNEL = 'instruments:payout';

    /** Pub/sub: open/settle paper-сделки бота → UI stats/open-trades без частого poll. */
    public const REDIS_BOT_EVENTS_CHANNEL = 'bot:events';

    public static function enabled(): bool
    {
        return (bool) config('chart.live_enabled', env('CHART_LIVE_ENABLED', true));
    }

    public static function hubPublicUrl(): string
    {
        $explicit = trim((string) config('chart.live_ws_url', env('CHART_LIVE_WS_URL', '')));
        if ($explicit !== '') {
            return $explicit;
        }

        // По умолчанию тот же хост, что у APP_URL, путь /chart-live (nginx → hub).
        $appUrl = rtrim((string) config('app.url', ''), '/');
        if ($appUrl === '') {
            return '/chart-live';
        }

        $parts = parse_url($appUrl);
        $scheme = (($parts['scheme'] ?? 'http') === 'https') ? 'wss' : 'ws';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.'/chart-live';
    }

    /**
     * Короткоживущий HMAC-токен для WS hub (без сессии PHP на стороне Python).
     *
     * @return array{token: string, exp: int}
     */
    public static function mintToken(?int $userId = null): array
    {
        $uid = $userId ?? (int) (auth()->id() ?? 0);
        $exp = time() + (int) config('chart.live_token_ttl_sec', 12 * 3600);
        $payload = $uid.'|'.$exp;
        $sig = hash_hmac('sha256', $payload, (string) config('app.key'));

        return [
            'token' => $payload.'.'.$sig,
            'exp' => $exp,
        ];
    }

    public static function verifyToken(string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, (string) config('app.key'));
        if (! hash_equals($expected, $sig)) {
            return false;
        }
        $bits = explode('|', $payload, 2);
        if (count($bits) !== 2) {
            return false;
        }
        $exp = (int) $bits[1];

        return $exp >= time();
    }
}
