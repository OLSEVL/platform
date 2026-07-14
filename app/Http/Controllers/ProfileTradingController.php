<?php

namespace App\Http\Controllers;

use App\Jobs\MirrorBotTradeJob;
use App\Models\BotTrade;
use App\Models\UserTradingSetting;
use App\Services\Mirror\UserMartingaleService;
use App\Services\PocketOptionsProxyClient;
use App\Support\MartingaleAmountsParser;
use App\Support\PocketMirrorProxy;
use App\Support\PocketSsidNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class ProfileTradingController extends Controller
{
    public function edit(Request $request): View
    {
        $settings = $this->settingsForUser($request->user()->id);

        return view('profile.trading', [
            'settings' => $settings,
            'martingale' => $settings->martingaleStatus(),
            'systemStatus' => $this->systemStatus($settings),
        ]);
    }

    /**
     * @return array{proxy_ok: bool, proxy_has_place_order: bool, pending_jobs: int, ssid_ok: bool, ssid_format_ok: bool, last_mirror_error: ?string}
     */
    private function systemStatus(UserTradingSetting $settings): array
    {
        $proxyOk = false;
        $hasPlaceOrder = false;
        $baseUrl = rtrim((string) config('services.pocket_options_proxy.url', ''), '/');
        if ($baseUrl !== '') {
            try {
                $body = Http::timeout(3)->get("{$baseUrl}/")->json();
                $proxyOk = is_array($body);
                $endpoints = json_encode($body['endpoints'] ?? [], JSON_UNESCAPED_UNICODE);
                $hasPlaceOrder = str_contains($endpoints, 'place-order')
                    && str_contains($endpoints, 'test-auth')
                    && str_contains($endpoints, 'active-orders');
            } catch (\Throwable) {
                $proxyOk = false;
            }
        }

        $normalized = PocketSsidNormalizer::normalize(
            (string) ($settings->pocket_ssid ?? ''),
            (bool) $settings->is_demo
        );

        $lastMirrorError = BotTrade::query()
            ->whereHas('bot', fn ($q) => $q->where('user_id', $settings->user_id))
            ->where('meta_json->mirror_status', 'failed')
            ->orderByDesc('id')
            ->value('meta_json');

        $lastErrorText = null;
        if (is_array($lastMirrorError)) {
            $lastErrorText = isset($lastMirrorError['mirror_error'])
                ? (string) $lastMirrorError['mirror_error']
                : null;
        }

        return [
            'proxy_ok' => $proxyOk,
            'proxy_has_place_order' => $hasPlaceOrder,
            'pending_jobs' => (int) DB::table('jobs')->count(),
            'ssid_format_ok' => $normalized !== null && PocketSsidNormalizer::isValidPocketAuthString($normalized),
            'ssid_ok' => self::ssidIsReady($settings),
            'last_mirror_error' => $lastErrorText,
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pocket_ssid' => ['nullable', 'string', 'min:20', 'max:8192'],
            'order_amounts' => ['required', 'string', 'max:65535'],
            'is_demo' => ['sometimes', 'boolean'],
            'mirror_trades_enabled' => ['sometimes', 'boolean'],
            'mirror_wait_previous_settled' => ['sometimes', 'boolean'],
        ]);

        $settings = $this->settingsForUser($request->user()->id);

        $parsed = MartingaleAmountsParser::parse((string) $validated['order_amounts']);
        if ($parsed['error'] !== null) {
            return redirect()
                ->route('profile.trading.edit')
                ->withInput()
                ->withErrors(['order_amounts' => $parsed['error']]);
        }

        /** @var list<float> $newAmounts */
        $newAmounts = $parsed['amounts'];
        $previousAmounts = $settings->orderAmountsList();
        $listChanged = json_encode($newAmounts) !== json_encode($previousAmounts);

        $previousMirror = (bool) $settings->mirror_trades_enabled;
        $previousDemo = (bool) $settings->is_demo;
        $mirrorEnabled = $request->boolean('mirror_trades_enabled');
        $isDemo = $request->boolean('is_demo');

        if ($request->filled('pocket_ssid')) {
            $rawSsid = (string) $validated['pocket_ssid'];
            $fallbackUid = PocketSsidNormalizer::extractUid((string) ($settings->pocket_ssid ?? ''));
            $normalized = PocketSsidNormalizer::normalize(
                $rawSsid,
                $isDemo,
                $fallbackUid > 0 ? $fallbackUid : null,
            );
            if ($normalized === null) {
                return redirect()
                    ->route('profile.trading.edit')
                    ->withInput()
                    ->withErrors([
                        'pocket_ssid' => 'Неверный формат. Вставьте полный SSID 42["auth",...] или JSON с sessionToken и uid.',
                    ]);
            }
            if (PocketSsidNormalizer::isBareSessionToken($rawSsid)
                && PocketSsidNormalizer::extractUid($normalized) <= 0) {
                return redirect()
                    ->route('profile.trading.edit')
                    ->withInput()
                    ->withErrors([
                        'pocket_ssid' => 'Одного session недостаточно: нужен uid. Скопируйте из DevTools полную строку 42["auth",...] или JSON {"sessionToken":"...","uid":ВАШ_ID}.',
                    ]);
            }
            $settings->pocket_ssid = $normalized;
        }

        $settings->order_amounts = $newAmounts;
        $settings->syncPrimaryOrderAmountFromList();
        $settings->is_demo = $isDemo;
        $settings->mirror_trades_enabled = $mirrorEnabled;
        $settings->mirror_wait_previous_settled = $request->boolean('mirror_wait_previous_settled');

        // Сброс шага мартингейла: новый список, вкл. зеркала, смена Demo/Real.
        if ($listChanged || (! $previousMirror && $mirrorEnabled) || $isDemo !== $previousDemo) {
            $settings->martingale_step = 0;
        } else {
            $settings->normalizeMartingaleStep();
        }

        if (! $request->filled('pocket_ssid') && is_string($settings->pocket_ssid) && $settings->pocket_ssid !== '') {
            $repaired = PocketSsidNormalizer::normalize((string) $settings->pocket_ssid, $settings->is_demo);
            if ($repaired !== null) {
                $settings->pocket_ssid = $repaired;
            }
        }

        $settings->save();

        $flash = 'Настройки торговли сохранены.';
        if ($settings->mirror_trades_enabled && self::ssidIsReady($settings) && ! app()->runningUnitTests()) {
            $ssid = PocketSsidNormalizer::normalize((string) $settings->pocket_ssid, (bool) $settings->is_demo);
            $proxyUrl = PocketMirrorProxy::resolveForMirror($settings);
            try {
                $auth = app(PocketOptionsProxyClient::class)->testAuth((string) $ssid, (bool) $settings->is_demo, $proxyUrl);
            } catch (\Throwable $e) {
                $auth = ['ok' => false, 'error' => $e->getMessage()];
            }
            if ($auth['ok'] ?? false) {
                $this->allowMirrorRetryForUser($settings->user_id);
                $flash .= ' Подключение к Pocket Option: OK.';
            } else {
                $err = (string) ($auth['error'] ?? 'auth failed');
                $flash .= ' Внимание: PO не принял SSID — '.$err
                    .'. Скопируйте новый auth из DevTools того кабинета, где смотрите сделки.';
            }
        }

        return redirect()
            ->route('profile.trading.edit')
            ->with('status', $flash);
    }

    public function resetMartingale(Request $request): RedirectResponse
    {
        app(UserMartingaleService::class)->resetStepForUser($request->user()->id);

        return redirect()
            ->route('profile.trading.edit')
            ->with('status', 'Мартингейл сброшен: следующая ставка — первая сумма в цепочке.');
    }

    public function testConnection(Request $request, PocketOptionsProxyClient $proxy): JsonResponse
    {
        $validated = $request->validate([
            'pocket_ssid' => ['nullable', 'string', 'max:8192'],
            'is_demo' => ['sometimes', 'boolean'],
        ]);

        $settings = $this->settingsForUser($request->user()->id);

        $rawSsid = $request->filled('pocket_ssid')
            ? (string) $validated['pocket_ssid']
            : (string) ($settings->pocket_ssid ?? '');

        if (trim($rawSsid) === '') {
            return response()->json([
                'ok' => false,
                'format_ok' => false,
                'error' => 'Вставьте SSID в поле выше.',
            ], 422);
        }

        $isDemo = $request->has('is_demo')
            ? $request->boolean('is_demo')
            : (bool) $settings->is_demo;

        $fallbackUid = PocketSsidNormalizer::extractUid((string) ($settings->pocket_ssid ?? ''));
        $ssid = PocketSsidNormalizer::normalize(
            $rawSsid,
            $isDemo,
            $fallbackUid > 0 ? $fallbackUid : null,
        );

        if ($ssid === null || ! PocketSsidNormalizer::isValidPocketAuthString($ssid)) {
            return response()->json([
                'ok' => false,
                'format_ok' => false,
                'error' => 'Неверный формат. Нужен 42["auth",...] с "session" и uid, или JSON с sessionToken.',
            ], 422);
        }

        $proxyUrl = PocketMirrorProxy::resolveForMirror($settings);

        try {
            $result = $proxy->testAuth($ssid, $isDemo, $proxyUrl);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'format_ok' => true,
                'error' => 'Прокси недоступен: '.$e->getMessage(),
                'proxy_used' => $proxyUrl !== null && $proxyUrl !== '',
            ], 503);
        }

        if ($result['ok'] ?? false) {
            $this->allowMirrorRetryForUser($settings->user_id);
        }

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'format_ok' => true,
            'error' => $result['error'] ?? null,
            'proxy_used' => $proxyUrl !== null && $proxyUrl !== '',
        ]);
    }

    private function allowMirrorRetryForUser(int $userId): void
    {
        BotTrade::query()
            ->whereHas('bot', fn ($q) => $q->where('user_id', $userId))
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->where('meta_json->mirror_status', 'failed')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->each(function (BotTrade $trade): void {
                $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
                $meta['mirror_retry_allowed'] = true;
                $trade->meta_json = $meta;
                $trade->save();
                MirrorBotTradeJob::dispatch($trade->id);
            });
    }

    private static function ssidIsReady(UserTradingSetting $settings): bool
    {
        $normalized = PocketSsidNormalizer::normalize(
            (string) ($settings->pocket_ssid ?? ''),
            (bool) $settings->is_demo
        );

        return $normalized !== null && PocketSsidNormalizer::isValidPocketAuthString($normalized);
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
