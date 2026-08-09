<?php

namespace App\Services\Trading;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\Bots\BotDataService;
use App\Services\Mirror\MirrorWaitPreviousService;
use App\Services\PocketOptionsProxyClient;
use App\Support\ManualTradeConfig;
use App\Support\MartingaleAmountsParser;
use App\Support\MirrorProxyMeta;
use App\Support\PocketMirrorProxy;
use App\Support\PocketSsidNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ручной вход в сделку на Pocket Option с графика.
 * Как у ботов: paper BotTrade у «Ручная / Manual» + place-order на PO.
 * SSID / demo / прокси — из профиля; mirror_trades_enabled не требуется.
 */
class ManualPocketOrderService
{
        public const MIN_DURATION_SEC = 3;

    public const MAX_DURATION_SEC = 43200;

    public function __construct(
        private readonly PocketOptionsProxyClient $proxyClient,
        private readonly BotDataService $botDataService,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   order_id: ?string,
     *   trade_id: ?int,
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

        // Сумма: либо UI, либо текущий шаг мартингейла — по тумблеру в модалке.
        if ((bool) $settings->manual_amount_from_martingale) {
            $amount = $settings->currentMirrorAmount();
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

        $exists = PocketInstrument::query()
            ->where('instrument', $asset)
            ->where('is_active', true)
            ->exists();
        if (! $exists) {
            return $this->fail('instrument_unknown', 'Неизвестный или неактивный инструмент: '.$asset);
        }

        // Wait previous для ручной: блокируем до settle предыдущего executed open.
        $waitPrevious = app(MirrorWaitPreviousService::class);
        if ($waitPrevious->shouldSkipManualPlace($settings, (int) $user->id)) {
            return $this->fail(
                'wait_previous',
                'На PO уже есть открытая сделка. Дождитесь результата или выключите «Ждать предыдущую» в настройках ручной торговли.'
            );
        }

        $resolved = PocketMirrorProxy::resolveForMirrorEndpoint($settings, $asset);
        $proxyUrl = $resolved['url'] ?? null;
        $proxyEndpointId = $resolved['id'] ?? null;

        $this->tryWarm($ssid, (bool) $settings->is_demo, $proxyUrl);

        $bot = $this->ensureManualBot($user);
        $entryAt = Carbon::now();
        $expiryAt = $entryAt->copy()->addSeconds($durationSec);
        $entryPrice = $this->resolveEntryPrice($asset);
        $payoutSnapshot = PocketInstrument::query()
            ->where('instrument', $asset)
            ->value('current_payout');

        $meta = array_merge(
            [
                'source' => ManualTradeConfig::META_SOURCE,
                'expiry_sec_used' => $durationSec,
                'mirror_amount' => $amount,
                'manual_amount_from_martingale' => (bool) $settings->manual_amount_from_martingale,
                'manual_advance_martingale' => (bool) $settings->manual_advance_martingale,
                'confidence' => 1.0,
            ],
            MirrorProxyMeta::forTradeMeta($proxyUrl)
        );
        if ($proxyEndpointId !== null) {
            $meta['mirror_proxy_endpoint_id'] = (int) $proxyEndpointId;
        }

        // Paper сначала (как BotEngine), затем place-order; статус зеркала — в meta.
        $trade = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'bot_signal_id' => null,
            'instrument' => $asset,
            'side' => $side,
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => $entryAt,
            'expiry_time' => $expiryAt,
            'entry_price' => $entryPrice,
            'payout_snapshot' => $payoutSnapshot !== null ? (float) $payoutSnapshot : null,
            'meta_json' => $meta,
        ]);

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
                'trade_id' => $trade->id,
                'instrument' => $asset,
                'error' => $e->getMessage(),
            ]);
            $this->markMirrorFailed($trade, $meta, 'Прокси PO недоступен: '.$e->getMessage());

            return $this->fail(
                'place_failed',
                'Прокси PO недоступен: '.$e->getMessage(),
                $trade->id
            );
        }
        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $meta['mirror_latency_ms'] = $latencyMs;

        $ok = (bool) ($result['ok'] ?? false);
        $confirmed = ($result['confirmed'] ?? true) === true;

        if ($ok && $confirmed) {
            $meta['mirror_status'] = 'executed';
            $meta['mirrored_at'] = Carbon::now()->toIso8601String();
            $meta['mirror_ssid_source'] = 'profile';
            $meta['mirror_is_demo'] = (bool) $settings->is_demo;
            if (isset($result['confirmed_via'])) {
                $meta['mirror_confirmed_via'] = (string) $result['confirmed_via'];
            }
            if (isset($result['order_id'])) {
                $meta['pocket_order_id'] = (string) $result['order_id'];
            }
            $meta['mirror_proxy_response'] = $result;
            $trade->meta_json = $meta;
            $trade->save();

            Log::info('Manual PO order placed', [
                'user_id' => $user->id,
                'trade_id' => $trade->id,
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
                'trade_id' => (int) $trade->id,
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

        $this->markMirrorFailed($trade, $meta, $error, $result);

        Log::warning('Manual PO order failed', [
            'user_id' => $user->id,
            'trade_id' => $trade->id,
            'instrument' => $asset,
            'side' => $side,
            'amount' => $amount,
            'duration' => $durationSec,
            'error' => $error,
            'latency_ms' => $latencyMs,
        ]);

        return $this->fail('place_failed', $error, $trade->id);
    }

    /**
     * Сохранить три тумблера ручной торговли (модалка на главной).
     *
     * @param  array{
     *   manual_respect_wait_previous?: bool,
     *   manual_advance_martingale?: bool,
     *   manual_amount_from_martingale?: bool
     * }  $flags
     * @return array{
     *   ok: bool,
     *   manual_respect_wait_previous: bool,
     *   manual_advance_martingale: bool,
     *   manual_amount_from_martingale: bool,
     *   default_amount: float,
     *   martingale_amount: float
     * }
     */
    public function updateFlags(User $user, array $flags): array
    {
        $settings = $this->settingsForUser((int) $user->id);

        if (array_key_exists('manual_respect_wait_previous', $flags)) {
            $settings->manual_respect_wait_previous = (bool) $flags['manual_respect_wait_previous'];
        }
        if (array_key_exists('manual_advance_martingale', $flags)) {
            $settings->manual_advance_martingale = (bool) $flags['manual_advance_martingale'];
        }
        if (array_key_exists('manual_amount_from_martingale', $flags)) {
            $settings->manual_amount_from_martingale = (bool) $flags['manual_amount_from_martingale'];
        }
        $settings->save();

        return array_merge(['ok' => true], $this->flagPayload($settings));
    }

    /**
     * Настройки для UI панели: можно ли торговать, дефолтная сумма, demo/real, тумблеры.
     *
     * @return array{
     *   ssid_ready: bool,
     *   is_demo: bool,
     *   default_amount: float,
     *   martingale_amount: float,
     *   manual_respect_wait_previous: bool,
     *   manual_advance_martingale: bool,
     *   manual_amount_from_martingale: bool,
     *   profile_url: string,
     *   settings_url: string
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

        // Если сумма с мартингейла — подставляем шаг; иначе тот же шаг как подсказку/дефолт поля.
        $defaultAmount = (bool) $settings->manual_amount_from_martingale
            ? $amount
            : $amount;

        return array_merge([
            'ssid_ready' => $ssid !== null && PocketSsidNormalizer::isValidPocketAuthString($ssid),
            'is_demo' => (bool) $settings->is_demo,
            'default_amount' => $defaultAmount,
            'martingale_amount' => $amount,
            'profile_url' => route('profile.trading.edit'),
            'settings_url' => route('trading.manual-settings'),
        ], $this->flagPayload($settings));
    }

    /**
     * Бот-контейнер paper/mirror: не активен для скана, без auto-mirror гейта.
     */
    public function ensureManualBot(User $user): Bot
    {
        $bot = Bot::query()
            ->where('user_id', $user->id)
            ->where('type', ManualTradeConfig::BOT_TYPE)
            ->first();

        if ($bot) {
            // Имя/флаги могли устареть — мягко синхронизируем.
            $dirty = false;
            if ($bot->name !== ManualTradeConfig::BOT_NAME) {
                $bot->name = ManualTradeConfig::BOT_NAME;
                $dirty = true;
            }
            if ($bot->is_active) {
                $bot->is_active = false;
                $dirty = true;
            }
            $cfg = is_array($bot->config) ? $bot->config : [];
            if (($cfg['mirror_trades_enabled'] ?? null) !== false) {
                $cfg['mirror_trades_enabled'] = false;
                $bot->config = $cfg;
                $dirty = true;
            }
            if ($dirty) {
                $bot->save();
            }

            return $bot;
        }

        return Bot::query()->create([
            'user_id' => $user->id,
            'name' => ManualTradeConfig::BOT_NAME,
            'type' => ManualTradeConfig::BOT_TYPE,
            'is_active' => false,
            'config' => [
                'mirror_trades_enabled' => false,
            ],
        ]);
    }

    private function tryWarm(string $ssid, bool $isDemo, ?string $proxyUrl): void
    {
        try {
            $this->proxyClient->warmClient($ssid, $isDemo, $proxyUrl);
        } catch (\Throwable $e) {
            Log::debug('Manual PO warm skipped', ['error' => $e->getMessage()]);
        }
    }

    private function resolveEntryPrice(string $instrument): ?float
    {
        try {
            $series = $this->botDataService->series($instrument, 5, true, 120, 1);
            $partial = is_array($series['partial'] ?? null) ? $series['partial'] : null;
            if ($partial !== null && isset($partial['close'])) {
                return (float) $partial['close'];
            }
            $closed = is_array($series['closed'] ?? null) ? $series['closed'] : [];
            if ($closed !== []) {
                $last = end($closed);

                return isset($last['close']) ? (float) $last['close'] : null;
            }
        } catch (\Throwable $e) {
            Log::debug('Manual entry price skipped', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $proxyPayload
     */
    private function markMirrorFailed(BotTrade $trade, array $meta, string $error, ?array $proxyPayload = null): void
    {
        $meta['mirror_status'] = 'failed';
        $meta['mirrored_at'] = Carbon::now()->toIso8601String();
        $meta['mirror_error'] = mb_substr($error, 0, 2000);
        if ($proxyPayload !== null) {
            $meta['mirror_proxy_response'] = $proxyPayload;
        }
        $trade->meta_json = $meta;
        $trade->save();
    }

    /**
     * @return array{
     *   manual_respect_wait_previous: bool,
     *   manual_advance_martingale: bool,
     *   manual_amount_from_martingale: bool,
     *   default_amount?: float,
     *   martingale_amount?: float
     * }
     */
    private function flagPayload(UserTradingSetting $settings): array
    {
        $martingaleAmount = $settings->currentMirrorAmount();
        if ($martingaleAmount < MartingaleAmountsParser::MIN_AMOUNT) {
            $martingaleAmount = MartingaleAmountsParser::MIN_AMOUNT;
        }

        return [
            'manual_respect_wait_previous' => (bool) ($settings->manual_respect_wait_previous ?? false),
            'manual_advance_martingale' => (bool) ($settings->manual_advance_martingale ?? false),
            'manual_amount_from_martingale' => (bool) ($settings->manual_amount_from_martingale ?? false),
            'default_amount' => $martingaleAmount,
            'martingale_amount' => $martingaleAmount,
        ];
    }

    /**
     * @return array{
     *   ok: false,
     *   order_id: null,
     *   trade_id: ?int,
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
    private function fail(string $code, string $error, ?int $tradeId = null): array
    {
        return [
            'ok' => false,
            'order_id' => null,
            'trade_id' => $tradeId,
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
        $defaults = array_merge([
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'martingale_step' => 0,
            'mirror_trades_enabled' => false,
            'mirror_wait_previous_settled' => false,
        ], ManualTradeConfig::defaultFlags());

        $settings = UserTradingSetting::query()->firstOrCreate(
            ['user_id' => $userId],
            $defaults
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
