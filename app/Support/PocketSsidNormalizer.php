<?php

namespace App\Support;

/**
 * Приводит ввод пользователя к полной строке SSID для Pocket Option API (42["auth",...]).
 * Поддерживает: полный SSID, JSON {"sessionToken","uid"}, голый session token.
 */
class PocketSsidNormalizer
{
    /**
     * @param  int|null  $fallbackUid  uid из ранее сохранённого SSID, если вводят только session-токен
     */
    public static function normalize(?string $raw, bool $isDemo = true, ?int $fallbackUid = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '42["auth",')) {
            return self::repairAuthMessage($raw, $isDemo) ?? $raw;
        }

        if (str_starts_with($raw, '{') || str_contains($raw, 'sessionToken')) {
            $data = self::parseSessionJson($raw);
            if ($data !== null) {
                $token = $data['sessionToken'] ?? $data['session'] ?? null;
                if (is_string($token) && $token !== '') {
                    $uid = (int) ($data['uid'] ?? $data['userId'] ?? 0);
                    if ($uid <= 0 && $fallbackUid !== null && $fallbackUid > 0) {
                        $uid = $fallbackUid;
                    }
                    $demo = isset($data['isDemo']) ? (int) $data['isDemo'] : ($isDemo ? 1 : 0);

                    return self::buildAuthString($token, $uid, $demo === 1);
                }
            }
        }

        // Голый session token (hex) — PO ждёт полное сообщение 42["auth",...]; uid без DevTools = 0.
        if (preg_match('/^[a-zA-Z0-9_-]{16,}$/', $raw)) {
            $uid = ($fallbackUid !== null && $fallbackUid > 0) ? $fallbackUid : 0;

            return self::buildAuthString($raw, $uid, $isDemo);
        }

        return null;
    }

    /** Ввод — только session, без 42["auth" и без JSON. */
    public static function isBareSessionToken(string $raw): bool
    {
        $raw = trim($raw);

        return $raw !== ''
            && ! str_starts_with($raw, '42["auth",')
            && ! str_starts_with($raw, '{')
            && ! str_contains($raw, 'sessionToken')
            && (bool) preg_match('/^[a-zA-Z0-9_-]{16,}$/', $raw);
    }

    public static function extractUid(string $ssid): int
    {
        if (preg_match('/"uid"\s*:\s*"?(\d+)"?/', $ssid, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseSessionJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        // Частый артефакт копирования из браузера: лишний ]} в конце.
        $trimmed = preg_replace('/\}\]+\s*$/', '}', $trimmed) ?? $trimmed;
        if (! str_ends_with($trimmed, '}')) {
            $trimmed .= '}';
        }

        $data = json_decode($trimmed, true);
        if (is_array($data)) {
            return $data;
        }

        $token = null;
        $uid = 0;
        if (preg_match('/"sessionToken"\s*:\s*"([^"]+)"/', $raw, $m)) {
            $token = $m[1];
        } elseif (preg_match('/"session"\s*:\s*"([^"]+)"/', $raw, $m)) {
            $token = $m[1];
        }
        if (preg_match('/"uid"\s*:\s*"?(\d+)"?/', $raw, $m)) {
            $uid = (int) $m[1];
        }
        if ($token !== null) {
            return ['sessionToken' => $token, 'uid' => $uid];
        }

        return null;
    }

    /**
     * PO принимает только {"session":"...", "uid", "isDemo", ...} — не sessionToken/lang/isChart.
     */
    public static function repairAuthMessage(string $raw, bool $defaultDemo): ?string
    {
        if (! str_starts_with($raw, '42["auth",')) {
            return null;
        }

        $token = null;
        if (preg_match('/"session"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $raw, $m)) {
            $token = stripcslashes($m[1]);
        } elseif (preg_match('/"sessionToken"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $raw, $m)) {
            $token = stripcslashes($m[1]);
        }

        if ($token === null || $token === '') {
            return null;
        }

        $uid = 0;
        if (preg_match('/"uid"\s*:\s*"?(\d+)"?/', $raw, $m)) {
            $uid = (int) $m[1];
        }

        $isDemo = $defaultDemo;
        if (preg_match('/"isDemo"\s*:\s*(\d+)/', $raw, $m)) {
            $isDemo = (int) $m[1] === 1;
        }

        return self::buildAuthString($token, $uid, $isDemo);
    }

    /** Корректный auth для PO: ключ session, не sessionToken. */
    public static function isValidPocketAuthString(string $ssid): bool
    {
        return str_starts_with($ssid, '42["auth",')
            && str_contains($ssid, '"session":"')
            && ! str_contains($ssid, '"sessionToken"');
    }

    public static function buildAuthString(string $sessionToken, int $uid, bool $isDemo): string
    {
        $sessionEscaped = addcslashes($sessionToken, '"\\');

        return sprintf(
            '42["auth",{"session":"%s","uid":%d,"isDemo":%d,"platform":2,"isFastHistory":true,"isOptimized":true}]',
            $sessionEscaped,
            $uid,
            $isDemo ? 1 : 0
        );
    }
}
