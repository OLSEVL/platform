<?php

namespace App\Services\Trading;

use App\Models\PocketInstrument;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\PocketOptionsProxyClient;
use App\Support\MartingaleAmountsParser;
use App\Support\PocketMirrorProxy;
use App\Support\PocketSsidNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Ручной вход в сделку на Pocket Option с графика.
 * SSID / demo / прокси — только из профиля (/profile/trading), не из UI панели.
 * Флаг mirror_trades_enabled не требуется (это гейт зеркала ботов).
 */
class ManualPocketOrderService
{
    public const MIN_DURATION_SEC = 5;

    public const MAX_DURATION_SEC = 43200;

    public function __construct(
        private readonly PocketOptionsProxyClient $proxyClient,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   order_id: ?string,
     *   status: ?string,
     *   confirmed_via: ?string,
     *   instrument: ?string,
     *   side: ?string,
     *   amount: ?float,
     *   duration: ?int,
     *   is_demo: ?bool,
     *   error: ?string,
     *   code: ?string
     * }
     */
    public function place(
        User $user,
        string $instrument,
        string $side,
        float $amount,
        int $durationSec,
    ): array {
        $settings = $this->settingsForUser((int) $user->id);

        $ssid = PocketSsidNormalizer::normalize(
            (string) ($settings->pocket_ssid ?? ''),
            (bool) $settings->is_demo
        );
        if ($ssid === null || ! PocketSsidNormalizer::isValidPocketAuthString($ssid)) {
            return $this->fail(
                'ssid_invalid',
                'SSID не готов. Сохраните валидный 42["auth",...] на странице Профиль → Торговля.'
            );
        }

        $side = strtoupper(trim($side));
        if (! in_array($side, ['CALL', 'PUT'], true)) {
            return $this->fail('side_invalid', 'Направление должно быть CALL (купить) или PUT (продать).');
        }

        if ($amount < MartingaleAmountsParser::MIN_AMOUNT || $amount > MartingaleAmountsParser::MAX_AMOUNT) {
            return $this->fail(
                'amount_invalid',
                sprintf(
                    'Сумма должна быть от %s до %s.',
                    MartingaleAmountsParser::formatAmount(MartingaleAmountsParser::MIN_AMOUNT),
                    MartingaleAmountsParser::formatAmount(MartingaleAmountsParser::MAX_AMOUNT)
                )
            );
        }

        if ($durationSec < self::MIN_DURATION_SEC || $durationSec > self::MAX_DURATION_SEC) {
            return $this->fail(
                'duration_invalid',
                sprintf(
                    'Время экспирации: от %d до %d секунд.',
                    self::MIN_DURATION_SEC,
                    self::MAX_DURATION_SEC
                )
            );
        }

        $asset = trim($instrument);
        if ($asset === '') {
            return $this->fail('instrument_invalid', 'Выберите инструмент на графике.');
        }

        // Разрешаем только активные инструменты из нашей БД (как в сайдбаре графика).
        $exists = PocketInstrument::query()
            ->where('instrument', $asset)
            ->where('is_active', true)
            ->exists();
        if (! $exists) {
            return $this->fail('instrument_unknown', 'Неизвестный или неактивный инструмент: '.$asset);
        }

        $resolved = PocketMirrorProxy::resolveForMirrorEndpoint($settings, $asset);
        $proxyUrl = $resolved['url'] ?? null;

        // Best-effort warm: не блокируем вход, если warm упал — place-order сам поднимет WS.
        $this->tryWarm($ssid, (bool) $settings->is_demo, $proxyUrl);

        $started = microtime(true);
        try {
            $result = $this->proxyClient->placeOrder(
                ssid: $ssid,
                instrument: $asset,
                side: $side,
                amount: $amount,
                durationSec: $durationSec,
                isDemo: (bool) $settings->is_demo,
                proxyUrl: $proxyUrl,
            );
        } catch (\Throwable $e) {
            Log::warning('Manual PO place-order transport error', [
                'user_id' => $user->id,
                'instrument' => $asset,
                'error' => $e->getMessage(),
            ]);

            return $this->fail(
                'place_failed',
                'Прокси PO недоступен: '.$e->getMessage()
            );
        }
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        $ok = (bool) ($result['ok'] ?? false);
        $confirmed = ($result['confirmed'] ?? true) === true;

        if ($ok && $confirmed) {
            Log::info('Manual PO order placed', [
                'user_id' => $user->id,
                'instrument' => $asset,
                'side' => $side,
                'amount' => $amount,
                'duration' => $durationSec,
                'order_id' => $result['order_id'] ?? null,
                'latency_ms' => $latencyMs,
                'is_demo' => (bool) $settings->is_demo,
            ]);

            return [
                'ok' => true,
                'order_id' => isset($result['order_id']) ? (string) $result['order_id'] : null,
                'status' => isset($result['status']) ? (string) $result['status'] : 'open',
                'confirmed_via' => isset($result['confirmed_via']) ? (string) $result['confirmed_via'] : null,
                'instrument' => $asset,
                'side' => $side,
                'amount' => $amount,
                'duration' => $durationSec,
                'is_demo' => (bool) $settings->is_demo,
                'error' => null,
                'code' => null,
            ];
        }

        $error = (string) ($result['error'] ?? '');
        if ($error === '' && $ok && ! $confirmed) {
            $error = 'Pocket Option не подтвердил ордер (сделки нет в кабинете).';
        }
        if ($error === '') {
            $error = 'Не удалось разместить ордер на Pocket Option.';
        }

        Log::warning('Manual PO order failed', [
            'user_id' => $user->id,
            'instrument' => $asset,
            'side' => $side,
            'amount' => $amount,
            'duration' => $durationSec,
            'error' => $error,
            'latency_ms' => $latencyMs,
        ]);

        return $this->fail('place_failed', $error);
    }

    /**
     * Настройки для UI панели: можно ли торговать, дефолтная сумма, demo/real.
     *
     * @return array{
     *   ssid_ready: bool,
     *   is_demo: bool,
     *   default_amount: float,
     *   profile_url: string
     * }
     */
    public function panelDefaults(User $user): array
    {
        $settings = $this->settingsForUser((int) $user->id);
        $ssid = PocketSsidNormalizer::normalize(
            (string) ($settings->pocket_ssid ?? ''),
            (bool) $settings->is_demo
        );
        $amount = $settings->currentMirrorAmount();
        if ($amount < MartingaleAmountsParser::MIN_AMOUNT) {
            $amount = MartingaleAmountsParser::MIN_AMOUNT;
        }

        return [
            'ssid_ready' => $ssid !== null && PocketSsidNormalizer::isValidPocketAuthString($ssid),
            'is_demo' => (bool) $settings->is_demo,
            'default_amount' => $amount,
            'profile_url' => route('profile.trading.edit'),
        ];
    }

    private function tryWarm(string $ssid, bool $isDemo, ?string $proxyUrl): void
    {
        try {
            $this->proxyClient->warmClient($ssid, $isDemo, $proxyUrl);
        } catch (\Throwable $e) {
            Log::debug('Manual PO warm skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{
     *   ok: false,
     *   order_id: null,
     *   status: null,
     *   confirmed_via: null,
     *   instrument: null,
     *   side: null,
     *   amount: null,
     *   duration: null,
     *   is_demo: null,
     *   error: string,
     *   code: string
     * }
     */
    private function fail(string $code, string $error): array
    {
        return [
            'ok' => false,
            'order_id' => null,
            'status' => null,
            'confirmed_via' => null,
            'instrument' => null,
            'side' => null,
            'amount' => null,
            'duration' => null,
            'is_demo' => null,
            'error' => $error,
            'code' => $code,
        ];
    }

    private function settingsForUser(int $userId): UserTradingSetting
    {
        $settings = UserTradingSetting::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'is_demo' => true,
                'order_amount' => 1,
                'order_amounts' => [1.0],
                'martingale_step' => 0,
                'mirror_trades_enabled' => false,
                'mirror_wait_previous_settled' => false,
            ]
        );

        if ($settings->orderAmountsList() === []) {
            $settings->order_amounts = [(float) max(1, $settings->order_amount)];
            $settings->martingale_step = 0;
            $settings->syncPrimaryOrderAmountFromList();
            $settings->save();
        }

        return $settings;
    }
}
