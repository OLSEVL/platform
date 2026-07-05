<?php

namespace App\Support;

/**
 * Снимок HTTP-прокси для зеркала в meta_json сделки (диагностика бана по IP vs прокси).
 * Пароль в meta не пишем — только host и маскированный URL.
 */
final class MirrorProxyMeta
{
    /**
     * @return array{
     *   mirror_proxy_url: ?string,
     *   mirror_proxy_host: ?string,
     *   mirror_proxy_port: ?int,
     *   mirror_proxy_direct: bool
     * }
     */
    public static function forTradeMeta(?string $proxyUrl): array
    {
        if ($proxyUrl === null || trim($proxyUrl) === '') {
            return [
                'mirror_proxy_url' => null,
                'mirror_proxy_host' => null,
                'mirror_proxy_port' => null,
                'mirror_proxy_direct' => true,
            ];
        }

        $parts = parse_url($proxyUrl);
        if (! is_array($parts)) {
            return [
                'mirror_proxy_url' => null,
                'mirror_proxy_host' => null,
                'mirror_proxy_port' => null,
                'mirror_proxy_direct' => true,
            ];
        }

        $host = isset($parts['host']) ? (string) $parts['host'] : null;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        return [
            'mirror_proxy_url' => self::redactProxyUrl($proxyUrl),
            'mirror_proxy_host' => $host !== '' ? $host : null,
            'mirror_proxy_port' => $port,
            'mirror_proxy_direct' => false,
        ];
    }

    /** URL без пароля: http://user:***@host:port */
    public static function redactProxyUrl(string $proxyUrl): ?string
    {
        $parts = parse_url(trim($proxyUrl));
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = (string) ($parts['scheme'] ?? 'http');
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $auth = '';
        if (isset($parts['user']) && (string) $parts['user'] !== '') {
            $auth = rawurlencode((string) $parts['user']).':***@';
        }

        return "{$scheme}://{$auth}{$host}{$port}";
    }
}
