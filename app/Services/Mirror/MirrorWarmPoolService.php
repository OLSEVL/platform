<?php

namespace App\Services\Mirror;

use App\Models\ProxyEndpoint;
use App\Models\UserTradingSetting;
use App\Services\PocketOptionsProxyClient;
use App\Services\ProxyRotationService;
use App\Support\MirrorProxyMeta;
use App\Support\PocketMirrorProxy;
use App\Support\PocketSsidNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Sticky warm pool: заранее держим WS PO по (ssid, sticky_proxy),
 * чтобы place-order не ловил cold start (~6–7 с).
 */
class MirrorWarmPoolService
{
    public function __construct(
        private readonly PocketOptionsProxyClient $proxyClient,
    ) {}

    /**
     * Статус для UI (/profile/trading, /profile/mirror-proxies).
     * Не вызывает resolve с новым pick — только peek sticky + client-status.
     *
     * @return array{
     *   state: string,
     *   label: string,
     *   detail: ?string,
     *   proxy_host: ?string,
     *   proxy_port: ?int,
     *   proxy_endpoint_id: ?int,
     *   connected: bool,
     *   registered: bool
     * }
     */
    public function describeStatus(UserTradingSetting $settings): array
    {
        if (! $settings->mirror_trades_enabled) {
            return $this->statusPayload(
                state: 'disabled',
                label: 'Зеркало выключено',
                detail: 'Включите зеркалирование на странице «Торговля».',
            );
        }

        $ssid = PocketSsidNormalizer::normalize(
            (string) ($settings->pocket_ssid ?? ''),
            (bool) $settings->is_demo
        );
        if ($ssid === null || ! PocketSsidNormalizer::isValidPocketAuthString($ssid)) {
            return $this->statusPayload(
                state: 'incomplete',
                label: 'SSID не готов',
                detail: 'Сохраните валидный 42["auth",...] — без него warm невозможен.',
            );
        }

        $sticky = PocketMirrorProxy::peekStickyEndpoint($settings);
        $proxyUrl = $sticky['url'] ?? null;
        $endpointId = $sticky['id'] ?? null;
        $proxyMeta = MirrorProxyMeta::forTradeMeta($proxyUrl);

        if ($proxyUrl === null || $proxyUrl === '') {
            return $this->statusPayload(
                state: 'cold',
                label: 'Канал ещё не выбран',
                detail: 'Sticky-прокси появится после прогрева или первой сделки.',
                proxyHost: null,
                proxyPort: null,
                endpointId: null,
            );
        }

        try {
            $remote = $this->proxyClient->clientStatus(
                $ssid,
                (bool) $settings->is_demo,
                $proxyUrl,
            );
        } catch (\Throwable $e) {
            return $this->statusPayload(
                state: 'error',
                label: 'Прокси PO недоступен',
                detail: $e->getMessage(),
                proxyHost: $proxyMeta['mirror_proxy_host'] ?? null,
                proxyPort: $proxyMeta['mirror_proxy_port'] ?? null,
                endpointId: $endpointId,
            );
        }

        $connected = (bool) ($remote['connected'] ?? false);
        $registered = (bool) ($remote['registered'] ?? false);
        $remoteState = (string) ($remote['state'] ?? ($connected ? 'warm' : 'cold'));

        if ($connected) {
            return $this->statusPayload(
                state: 'warm',
                label: 'Тёплый канал',
                detail: $registered
                    ? 'WS к PO подключён, keepalive активен.'
                    : 'WS подключён (сессия в кэше).',
                proxyHost: $proxyMeta['mirror_proxy_host'] ?? null,
                proxyPort: $proxyMeta['mirror_proxy_port'] ?? null,
                endpointId: $endpointId,
                connected: true,
                registered: $registered,
            );
        }

        return $this->statusPayload(
            state: $remoteState === 'warm' ? 'warm' : 'cold',
            label: 'Холодный канал',
            detail: 'Прогрев при сохранении настроек, cron mirror:warm-pool или первой сделке.',
            proxyHost: $proxyMeta['mirror_proxy_host'] ?? null,
            proxyPort: $proxyMeta['mirror_proxy_port'] ?? null,
            endpointId: $endpointId,
            connected: false,
            registered: $registered,
        );
    }

    /**
     * Выбрать sticky-прокси (если нужно), прогреть WS и зарегистрировать keepalive.
     * При connect fail — failure_count, сброс sticky и один retry на другой endpoint.
     *
     * @return array{ok: bool, error?: string|null, latency_ms?: int|null, status: array<string, mixed>}
     */
    public function warm(UserTradingSetting $settings): array
    {
        if (! $settings->mirror_trades_enabled) {
            return ['ok' => false, 'error' => 'mirror disabled', 'status' => $this->describeStatus($settings)];
        }

        $ssid = PocketSsidNormalizer::normalize(
            (string) ($settings->pocket_ssid ?? ''),
            (bool) $settings->is_demo
        );
        if ($ssid === null || ! PocketSsidNormalizer::isValidPocketAuthString($ssid)) {
            return ['ok' => false, 'error' => 'invalid ssid', 'status' => $this->describeStatus($settings)];
        }

        $result = $this->warmOnce($settings, $ssid);
        if ($result['ok'] ?? false) {
            return $result;
        }

        $error = (string) ($result['error'] ?? '');
        if ($this->isConnectivityError($error)) {
            $endpointId = $result['endpoint_id'] ?? null;
            $this->recordConnectivityFailure(
                is_int($endpointId) ? $endpointId : null,
                $result['proxy_url'] ?? null,
                $error,
            );
            // Sticky доказанно не коннектится — берём другой active для тёплого канала.
            PocketMirrorProxy::forgetSticky($settings->fresh());
            $retry = $this->warmOnce($settings->fresh(), $ssid);
            if ($retry['ok'] ?? false) {
                return $retry;
            }

            return [
                'ok' => false,
                'error' => (string) ($retry['error'] ?? $error),
                'latency_ms' => $retry['latency_ms'] ?? $result['latency_ms'] ?? null,
                'status' => $this->describeStatus($settings->fresh()),
            ];
        }

        return [
            'ok' => false,
            'error' => $error !== '' ? $error : 'warm failed',
            'latency_ms' => $result['latency_ms'] ?? null,
            'status' => $this->describeStatus($settings->fresh()),
        ];
    }

    /**
     * Один attempt: resolve sticky + warm-client.
     *
     * @return array{ok: bool, error?: string|null, latency_ms?: int|null, endpoint_id?: ?int, proxy_url?: ?string, status?: array<string, mixed>}
     */
    private function warmOnce(UserTradingSetting $settings, string $ssid): array
    {
        $resolved = PocketMirrorProxy::resolveForMirrorEndpoint($settings, null);
        $proxyUrl = $resolved['url'] ?? null;
        $endpointId = $resolved['id'] ?? null;

        if ($proxyUrl === null || $proxyUrl === '') {
            return [
                'ok' => false,
                'error' => 'no mirror proxy available',
                'endpoint_id' => null,
                'proxy_url' => null,
                'status' => $this->describeStatus($settings->fresh()),
            ];
        }

        try {
            $remote = $this->proxyClient->warmClient(
                $ssid,
                (bool) $settings->is_demo,
                $proxyUrl,
            );
        } catch (\Throwable $e) {
            Log::warning('Mirror warm-client failed', [
                'user_id' => $settings->user_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'endpoint_id' => $endpointId,
                'proxy_url' => $proxyUrl,
            ];
        }

        $ok = (bool) ($remote['ok'] ?? false);

        return [
            'ok' => $ok,
            'error' => $ok ? null : (string) ($remote['error'] ?? 'warm failed'),
            'latency_ms' => $remote['latency_ms'] ?? null,
            'endpoint_id' => $endpointId,
            'proxy_url' => $proxyUrl,
            'status' => $this->describeStatus($settings->fresh()),
        ];
    }

    /**
     * Снять keepalive при выключении зеркала.
     */
    public function cool(UserTradingSetting $settings): void
    {
        $ssid = PocketSsidNormalizer::normalize(
            (string) ($settings->pocket_ssid ?? ''),
            (bool) $settings->is_demo
        );
        if ($ssid === null) {
            return;
        }

        $sticky = PocketMirrorProxy::peekStickyEndpoint($settings);
        $proxyUrl = $sticky['url'] ?? null;

        try {
            $this->proxyClient->unwarmClient($ssid, (bool) $settings->is_demo, $proxyUrl);
        } catch (\Throwable $e) {
            Log::debug('Mirror unwarm-client failed', [
                'user_id' => $settings->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Фоновый прогрев всех пользователей с включённым зеркалом (после рестарта proxy).
     */
    public function warmAllEnabled(): int
    {
        $warmed = 0;
        UserTradingSetting::query()
            ->where('mirror_trades_enabled', true)
            ->orderBy('id')
            ->each(function (UserTradingSetting $settings) use (&$warmed): void {
                $result = $this->warm($settings);
                if ($result['ok'] ?? false) {
                    $warmed++;
                }
            });

        return $warmed;
    }

    private function isConnectivityError(string $error): bool
    {
        $normalized = mb_strtolower($error);

        return str_contains($normalized, 'connect returned false')
            || str_contains($normalized, 'pocket option connect error')
            || str_contains($normalized, 'failed to connect to pocket option')
            || str_contains($normalized, 'not connected after auth')
            || str_contains($normalized, 'client not connected after auth');
    }

    private function recordConnectivityFailure(?int $proxyEndpointId, ?string $proxyUrl, string $error): void
    {
        $endpoint = null;
        if ($proxyEndpointId !== null && $proxyEndpointId > 0) {
            $endpoint = ProxyEndpoint::query()->find($proxyEndpointId);
        }
        if ($endpoint === null) {
            $endpoint = PocketMirrorProxy::findEndpointByUrl($proxyUrl);
        }
        if ($endpoint === null) {
            return;
        }

        app(ProxyRotationService::class)->markProxyEndpointFailure(
            $endpoint,
            $error,
            null,
            null,
            ['source' => 'mirror_warm'],
        );
    }

    /**
     * @return array{
     *   state: string,
     *   label: string,
     *   detail: ?string,
     *   proxy_host: ?string,
     *   proxy_port: ?int,
     *   proxy_endpoint_id: ?int,
     *   connected: bool,
     *   registered: bool
     * }
     */
    private function statusPayload(
        string $state,
        string $label,
        ?string $detail = null,
        ?string $proxyHost = null,
        ?int $proxyPort = null,
        ?int $endpointId = null,
        bool $connected = false,
        bool $registered = false,
    ): array {
        return [
            'state' => $state,
            'label' => $label,
            'detail' => $detail,
            'proxy_host' => $proxyHost,
            'proxy_port' => $proxyPort,
            'proxy_endpoint_id' => $endpointId,
            'connected' => $connected,
            'registered' => $registered,
        ];
    }
}
