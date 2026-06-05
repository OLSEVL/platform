<?php

namespace App\Services\Bots;

use App\Models\BotTrade;
use App\Models\UserTradingSetting;
use App\Services\PocketOptionsProxyClient;
use App\Support\PocketMirrorProxy;
use App\Support\PocketSsidNormalizer;
use Illuminate\Support\Carbon;

class BotTradeMirrorService
{
    public function __construct(
        private readonly PocketOptionsProxyClient $proxyClient,
    ) {}

    /**
     * Отправляет на Pocket Option ордер 1:1 с paper-сделкой бота (без дополнительных лимитов).
     */
    public function mirrorTrade(BotTrade $trade): void
    {
        $trade->loadMissing('bot.user.tradingSettings');

        $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
        $existingStatus = (string) ($meta['mirror_status'] ?? '');

        // Успешно зеркалили ранее; повторно не шлём (кроме явного сброса mirror_status в retry-команде).
        if ($existingStatus === 'executed') {
            return;
        }

        // Повторная попытка после failed (например, протухший SSID или Invalid asset).
        if ($existingStatus === 'failed' && ! (bool) ($meta['mirror_retry_allowed'] ?? false)) {
            return;
        }

        // Не дублируем на PO уже закрытые paper-сделки (очередь могла отстать).
        if ($trade->status !== BotTrade::STATUS_OPEN) {
            $this->markMirror($trade, $meta, 'skipped_trade_not_open', null, null);

            return;
        }

        if ($trade->expiry_time && $trade->expiry_time->isPast()) {
            $this->markMirror($trade, $meta, 'skipped_expired', null, null);

            return;
        }

        $user = $trade->bot?->user;
        if (! $user) {
            $this->markMirror($trade, $meta, 'skipped_no_user', null, 'Bot user not found');

            return;
        }

        /** @var UserTradingSetting|null $settings */
        $settings = $user->tradingSettings;

        if (! $settings || ! $settings->canMirrorTrades()) {
            $reason = ! $settings
                ? 'skipped_no_settings'
                : (! $settings->mirror_trades_enabled
                    ? 'skipped_disabled'
                    : 'skipped_incomplete_settings');

            $this->markMirror($trade, $meta, $reason, null, null);

            return;
        }

        $durationSec = $this->resolveDurationSec($trade, $meta);
        if ($durationSec <= 0) {
            $this->markMirror($trade, $meta, 'failed', null, 'Invalid trade duration');

            return;
        }

        $side = strtoupper((string) $trade->side);
        if (! in_array($side, ['CALL', 'PUT'], true)) {
            $this->markMirror($trade, $meta, 'failed', null, 'Invalid side: '.$trade->side);

            return;
        }

        $profileSsid = PocketSsidNormalizer::normalize((string) $settings->pocket_ssid, (bool) $settings->is_demo);
        if ($profileSsid === null) {
            $this->markMirror($trade, $meta, 'failed', null, 'Invalid SSID format in profile settings');

            return;
        }

        // Только SSID из профиля пользователя — session_token инструментов для ingest, другой кабинет PO.
        // PO с сервера часто доступен только через тот же HTTP-прокси, что и ingest.
        $proxyUrl = PocketMirrorProxy::resolveForInstrument((string) $trade->instrument);

        $mirrorStarted = microtime(true);
        $meta['mirror_dispatch'] = config('services.mirror.dispatch', 'sync');
        // Мартингейл: сумма по текущему шагу цепочки (фиксируем в meta для истории).
        $amounts = $settings->orderAmountsList();
        $step = max(0, (int) $settings->martingale_step);
        $stepIndex = $amounts !== [] ? ($step % count($amounts)) : 0;
        $mirrorAmount = $amounts !== [] ? (float) $amounts[$stepIndex] : (float) $settings->order_amount;
        $meta['mirror_amount'] = $mirrorAmount;
        $meta['martingale_step_used'] = $stepIndex;

        $result = $this->proxyClient->placeOrder(
            ssid: $profileSsid,
            instrument: (string) $trade->instrument,
            side: $side,
            amount: $mirrorAmount,
            durationSec: $durationSec,
            isDemo: (bool) $settings->is_demo,
            proxyUrl: $proxyUrl,
        );

        $confirmed = ($result['confirmed'] ?? true) === true;
        $meta['mirror_latency_ms'] = (int) round((microtime(true) - $mirrorStarted) * 1000);

        if (($result['ok'] ?? false) && $confirmed) {
            $meta['mirror_ssid_source'] = 'profile';
            $meta['mirror_is_demo'] = (bool) $settings->is_demo;
            $this->markMirror(
                $trade,
                $meta,
                'executed',
                $result['order_id'] ?? null,
                null,
                $result
            );

            return;
        }

        $error = (string) ($result['error'] ?? '');
        if ($error === '' && ($result['ok'] ?? false) && ! $confirmed) {
            $error = 'Pocket Option не подтвердил ордер (сделки нет в кабинете)';
        }
        if ($error === '') {
            $error = 'Proxy place-order failed';
        }

        $this->markMirror(
            $trade,
            $meta,
            'failed',
            null,
            $error,
            $result
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $proxyPayload
     */
    private function markMirror(
        BotTrade $trade,
        array $meta,
        string $status,
        ?string $pocketOrderId,
        ?string $error,
        ?array $proxyPayload = null,
    ): void {
        $meta['mirror_status'] = $status;
        $meta['mirrored_at'] = Carbon::now()->toIso8601String();
        if ($pocketOrderId !== null) {
            $meta['pocket_order_id'] = $pocketOrderId;
        }
        if ($error !== null) {
            $meta['mirror_error'] = mb_substr($error, 0, 2000);
        }
        if ($proxyPayload !== null) {
            $meta['mirror_proxy_response'] = $proxyPayload;
        }

        $trade->meta_json = $meta;
        $trade->save();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    /**
     * @param  array<string, mixed>  $meta
     */
    /**
     * Длительность ордера на PO: полный expiry_sec сделки (как в paper), без укорочения «остатком» до expiry_time.
     */
    private function resolveDurationSec(BotTrade $trade, array $meta): int
    {
        if (isset($meta['expiry_sec_used']) && is_numeric($meta['expiry_sec_used'])) {
            return max(1, (int) $meta['expiry_sec_used']);
        }

        if ($trade->entry_time && $trade->expiry_time) {
            return max(1, (int) $trade->expiry_time->diffInSeconds($trade->entry_time));
        }

        return 0;
    }
}
