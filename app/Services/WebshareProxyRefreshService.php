<?php

namespace App\Services;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Полностью заменяет локальный пул endpoint-ов актуальным списком Webshare.
 *
 * Единственный сервис для кнопки в /settings и ночной команды: это гарантирует,
 * что оба способа одинаково валидируют ответ, переназначают инструменты и
 * уведомляют ingest о смене proxy URL.
 */
final class WebshareProxyRefreshService
{
    public function __construct(
        private readonly ProxyRotationService $proxyRotation,
        private readonly CurrentCandleCacheService $currentCandleCache,
    ) {}

    /**
     * Загружает валидные прокси Webshare, атомарно заменяет пул и переназначает
     * endpoint каждому активному инструменту.
     *
     * @return array{assigned: int, proxy_count: int}
     */
    public function refresh(): array
    {
        $apiKey = (string) config('services.webshare.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('WEBSHARE_API_KEY не задан в .env');
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => "Token {$apiKey}",
                ])
                ->get('https://proxy.webshare.io/api/v2/proxy/list/', [
                    'mode' => 'direct',
                    'page' => 1,
                    'page_size' => 1000,
                ]);

            $response->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Ошибка запроса к Webshare API: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $proxyPool = $this->buildProxyPool($response->json('results'));
        if ($proxyPool === []) {
            throw new RuntimeException('Нет валидных прокси для назначения.');
        }

        $activeInstrumentsCount = PocketInstrument::query()->active()->count();
        if ($activeInstrumentsCount === 0) {
            throw new RuntimeException('Нет активных инструментов.');
        }

        DB::transaction(function () use ($proxyPool): void {
            // Удаление endpoint-ов также освобождает FK у инструментов, чтобы новый
            // список ниже стал единственным источником назначений.
            ProxyEndpoint::query()->delete();

            $now = now();
            foreach ($proxyPool as $url) {
                ProxyEndpoint::query()->create([
                    'url' => $url,
                    'status' => ProxyEndpoint::STATUS_ACTIVE,
                    'failure_count' => 0,
                    'last_error' => null,
                    'last_failed_at' => null,
                    'last_success_at' => null,
                    'last_http_check_at' => null,
                    'last_http_status' => null,
                    'last_http_latency_ms' => null,
                    'last_http_check_result' => null,
                    'last_http_check_mode' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        $assigned = $this->proxyRotation->assignRoundRobinToActiveInstruments();

        // Ingest проверяет версию пула и быстро перезапускает WS-воркеры
        // с новыми URL, не дожидаясь естественного reconnect.
        $this->proxyRotation->markPoolRefreshed();

        // Старые partial-свечи могли прийти через удалённые endpoint-ы.
        // Их очистка не даёт графику показывать устаревший хвост до истечения TTL.
        $this->currentCandleCache->clearAllPartials(
            PocketInstrument::query()->active()->pluck('instrument')->all()
        );

        return [
            'assigned' => $assigned,
            'proxy_count' => count($proxyPool),
        ];
    }

    /**
     * @param  mixed  $rows  JSON-поле results из ответа Webshare
     * @return list<string>
     */
    private function buildProxyPool(mixed $rows): array
    {
        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('Webshare вернул пустой список прокси.');
        }

        $proxyPool = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $username = $row['username'] ?? null;
            $password = $row['password'] ?? null;
            $address = $row['proxy_address'] ?? null;
            $port = $row['port'] ?? null;
            $isValid = $row['valid'] ?? true;

            if (! $isValid || ! $username || ! $password || ! $address || ! $port) {
                continue;
            }

            // Данные авторизации кодируются отдельно: спецсимволы в логине/пароле
            // не должны ломать URL, который передаётся HTTP и WS-клиентам.
            $proxyPool[] = sprintf(
                'http://%s:%s@%s:%s',
                rawurlencode((string) $username),
                rawurlencode((string) $password),
                (string) $address,
                (string) $port,
            );
        }

        return $proxyPool;
    }
}
