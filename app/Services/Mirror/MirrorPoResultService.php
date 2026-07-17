<?php

namespace App\Services\Mirror;

use App\Models\BotTrade;
use App\Models\ProxyEndpoint;
use App\Models\UserTradingSetting;
use App\Services\PocketOptionsProxyClient;
use App\Services\ProxyRotationService;
use App\Support\PocketMirrorProxy;
use App\Support\PocketSsidNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Итог сделки на Pocket Option (successcloseOrder) для мартингейла / перекрытий.
 *
 * Paper-результат считается отдельно по свечам; шаг мартингейла — по PO.
 * Если после всех попыток PO-результат не получен, считаем вход на PO неподтверждённым
 * (as if order did not open) и НЕ используем paper как замену PO для мартингейла.
 */
class MirrorPoResultService
{
    /**
     * Источник результата, когда order-result не получили ни на одной попытке:
     * считаем зеркало неподтверждённым (PO open не доказан), поэтому downstream
     * должен НЕ эскалировать мартингейл по paper fallback.
     */
    public const SOURCE_ASSUME_NOT_OPENED = 'po_assume_not_opened';

    /** Секунд после expiry, прежде чем спрашивать итог PO (кадр close может опоздать). */
    public const PO_RESULT_DELAY_SEC = 10;

    /** Пауза между попытками (1-й запрос + 3 retry). */
    public const RETRY_SLEEP_USEC = 4_000_000;

    /** Всего опросов: 1 первичный + 3 повторных. */
    public const MAX_FETCH_ATTEMPTS = 4;

    public function __construct(
        private readonly PocketOptionsProxyClient $proxyClient,
    ) {}

    /**
     * @return array{
     *   result: ?string,
     *   source: string,
     *   profit: ?float,
     *   deal_id: ?string,
     *   raw: array<string, mixed>|null
     * }
     */
    public function resolveForTrade(BotTrade $trade, string $paperResult): array
    {
        $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
        $mirrorStatus = (string) ($meta['mirror_status'] ?? '');
        $orderId = (string) ($meta['pocket_deal_id'] ?? $meta['pocket_order_id'] ?? '');

        if ($mirrorStatus !== 'executed' || $orderId === '') {
            return [
                'result' => null,
                'source' => 'skipped_not_executed',
                'profit' => null,
                'deal_id' => null,
                'raw' => null,
            ];
        }

        $trade->loadMissing('bot.user.tradingSettings');
        /** @var UserTradingSetting|null $settings */
        $settings = $trade->bot?->user?->tradingSettings;
        if ($settings === null) {
            return [
                'result' => null,
                'source' => 'skipped_no_settings',
                'profit' => null,
                'deal_id' => null,
                'raw' => null,
            ];
        }

        $ssid = PocketSsidNormalizer::normalize(
            (string) ($settings->pocket_ssid ?? ''),
            (bool) $settings->is_demo
        );
        if ($ssid === null) {
            return [
                'result' => null,
                'source' => 'skipped_invalid_ssid',
                'profit' => null,
                'deal_id' => null,
                'raw' => null,
            ];
        }

        // Стартуем с прокси, на котором ушёл place-order (sticky / meta).
        $endpoint = $this->resolveInitialEndpoint($settings, $trade, $meta);
        $usedEndpointIds = [];
        $attempts = [];
        $last = [];

        for ($attempt = 1; $attempt <= self::MAX_FETCH_ATTEMPTS; $attempt++) {
            $proxyUrl = $endpoint['url'] ?? null;
            $endpointId = isset($endpoint['id']) ? (int) $endpoint['id'] : null;
            if ($endpointId !== null && $endpointId > 0) {
                $usedEndpointIds[] = $endpointId;
            }

            $payload = $this->fetchOnce($ssid, $orderId, (bool) $settings->is_demo, $proxyUrl);
            $attempts[] = $payload;
            $last = $payload;

            if ($this->isCompletedWinLoss($payload)) {
                return $this->pack($payload, $attempt === 1 ? 'po' : 'po_retry');
            }

            // Connect/WS fail на промежуточной попытке → failure_count + другой прокси на retry.
            if ($this->isConnectivityFailure($payload)) {
                $error = (string) ($payload['error'] ?? 'order-result connect failed');
                $this->recordProxyFailure(
                    $endpointId,
                    $proxyUrl,
                    $error,
                    (string) $trade->instrument,
                    'mirror_po_result_connect'
                );

                if ($attempt < self::MAX_FETCH_ATTEMPTS) {
                    $alternate = PocketMirrorProxy::pickAlternateForMirror(
                        $settings,
                        $usedEndpointIds,
                        (string) $trade->instrument
                    );
                    if ($alternate !== null) {
                        PocketMirrorProxy::forgetSticky($settings);
                        PocketMirrorProxy::rememberAutoPick($settings, (int) $alternate['id']);
                        $endpoint = $alternate;
                        Log::info('Mirror PO result retry on alternate proxy', [
                            'trade_id' => $trade->id,
                            'attempt' => $attempt,
                            'from_endpoint_id' => $endpointId,
                            'to_endpoint_id' => $alternate['id'],
                        ]);
                    }
                }
            }
            // pending / not_found: тот же sticky — successclose приходит на WS place-order.

            if ($attempt < self::MAX_FETCH_ATTEMPTS) {
                $this->retryPause();
            }
        }

        Log::warning('Mirror PO result unresolved; assume PO not opened', [
            'trade_id' => $trade->id,
            'order_id' => $orderId,
            'paper_result' => $paperResult,
            'attempts' => $attempts,
        ]);

        // Финальный paper_fallback тоже в счётчик прокси сделки / последнего endpoint.
        $fallbackEndpointId = isset($meta['mirror_proxy_endpoint_id'])
            ? (int) $meta['mirror_proxy_endpoint_id']
            : ($endpoint['id'] ?? null);
        $fallbackProxyUrl = isset($meta['mirror_proxy_url']) && is_string($meta['mirror_proxy_url'])
            ? (string) $meta['mirror_proxy_url']
            : ($endpoint['url'] ?? null);
        $this->recordProxyFailure(
            $fallbackEndpointId !== null && (int) $fallbackEndpointId > 0 ? (int) $fallbackEndpointId : null,
            $fallbackProxyUrl,
            'PO result not received after '.self::MAX_FETCH_ATTEMPTS.' attempts (assume_not_opened)',
            (string) $trade->instrument,
            'mirror_po_result_fallback'
        );

        // Ключевой момент риск-логики:
        // если не смогли подтвердить PO-result после всех retry, НЕ считаем это paper_fallback
        // для мартингейла, а маркируем как "PO open не подтверждён". Иначе шаг мартингейла
        // может расти по сделке, которую на стороне PO фактически не было.
        return [
            'result' => null,
            'source' => self::SOURCE_ASSUME_NOT_OPENED,
            'profit' => null,
            'deal_id' => isset($last['deal_id']) ? (string) $last['deal_id'] : null,
            'raw' => $last ?: null,
        ];
    }

    /**
     * Прокси place-order из meta, иначе текущий resolveForMirror.
     *
     * @param  array<string, mixed>  $meta
     * @return array{id: ?int, url: ?string}
     */
    private function resolveInitialEndpoint(
        UserTradingSetting $settings,
        BotTrade $trade,
        array $meta,
    ): array {
        $metaId = isset($meta['mirror_proxy_endpoint_id']) ? (int) $meta['mirror_proxy_endpoint_id'] : 0;
        if ($metaId > 0) {
            $row = ProxyEndpoint::query()->whereKey($metaId)->first(['id', 'url']);
            if ($row !== null && is_string($row->url) && $row->url !== '') {
                return ['id' => (int) $row->id, 'url' => (string) $row->url];
            }
        }

        $metaUrl = isset($meta['mirror_proxy_url']) ? (string) $meta['mirror_proxy_url'] : '';
        if ($metaUrl !== '') {
            $byUrl = PocketMirrorProxy::findEndpointByUrl($metaUrl);
            if ($byUrl !== null && is_string($byUrl->url) && $byUrl->url !== '') {
                return ['id' => (int) $byUrl->id, 'url' => (string) $byUrl->url];
            }

            return ['id' => null, 'url' => $metaUrl];
        }

        return PocketMirrorProxy::resolveForMirrorEndpoint($settings, (string) $trade->instrument);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{result:?string,source:string,profit:?float,deal_id:?string,raw:array<string,mixed>|null}
     */
    private function pack(array $payload, string $source): array
    {
        $result = strtolower((string) ($payload['result'] ?? ''));
        if ($result === 'won') {
            $result = BotTrade::RESULT_WIN;
        } elseif ($result === 'lose' || $result === 'lost') {
            $result = BotTrade::RESULT_LOSS;
        }

        return [
            'result' => in_array($result, [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS, BotTrade::RESULT_DRAW], true)
                ? $result
                : null,
            'source' => $source,
            'profit' => isset($payload['profit']) && is_numeric($payload['profit'])
                ? (float) $payload['profit']
                : null,
            'deal_id' => isset($payload['deal_id'])
                ? (string) $payload['deal_id']
                : (isset($payload['order_id']) ? (string) $payload['order_id'] : null),
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isCompletedWinLoss(array $payload): bool
    {
        if (! ($payload['ok'] ?? false) || ! ($payload['completed'] ?? false)) {
            return false;
        }
        $result = strtolower((string) ($payload['result'] ?? ''));

        return in_array($result, ['win', 'loss', 'draw', 'won', 'lose', 'lost'], true);
    }

    /**
     * Connect/WS ошибка к PO через HTTP-прокси (как place-order / warm).
     *
     * @param  array<string, mixed>  $payload
     */
    private function isConnectivityFailure(array $payload): bool
    {
        $error = mb_strtolower((string) ($payload['error'] ?? ''));
        if ($error === '') {
            return false;
        }

        return str_contains($error, 'connect returned false')
            || str_contains($error, 'pocket option connect error')
            || str_contains($error, 'failed to connect to pocket option')
            || str_contains($error, 'websocket не подключ')
            || str_contains($error, 'not connected after auth')
            || str_contains($error, 'client not connected after auth')
            || str_contains($error, 'connection refused')
            || str_contains($error, 'timed out')
            || str_contains($error, 'curl error');
    }

    private function recordProxyFailure(
        ?int $proxyEndpointId,
        ?string $proxyUrl,
        string $error,
        string $instrument,
        string $source,
    ): void {
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
            [
                'source' => $source,
                'instrument' => $instrument,
            ],
        );
    }

    /**
     * Пауза между попытками; в testing не спим (иначе unit-тесты по 12+ с).
     */
    private function retryPause(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        usleep(self::RETRY_SLEEP_USEC);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOnce(string $ssid, string $orderId, bool $isDemo, ?string $proxyUrl): array
    {
        try {
            return $this->proxyClient->getOrderResult($ssid, $orderId, $isDemo, $proxyUrl);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'completed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
