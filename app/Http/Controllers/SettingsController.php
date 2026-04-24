<?php

namespace App\Http\Controllers;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Services\ProxyRotationService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    private const SETTING_PAYOUT_STALE_MINUTES_THRESHOLD = 'payout_stale_minutes_threshold';
    private const DEFAULT_PAYOUT_STALE_MINUTES_THRESHOLD = 5;

    public function index(ProxyRotationService $proxyRotation): \Illuminate\View\View
    {
        return view('settings.index', [
            'proxyAutoRotationEnabled' => $proxyRotation->isAutoRotationEnabled(),
            'proxyCooldownStreakThreshold' => $proxyRotation->getCooldownStreakThreshold(),
            'proxyMaxInstrumentsPerEndpoint' => $proxyRotation->getMaxInstrumentsPerProxy(),
            'payoutStaleMinutesThreshold' => $this->getPayoutStaleMinutesThreshold(),
            'proxyStats' => [
                'total' => ProxyEndpoint::query()->count(),
                'active' => ProxyEndpoint::query()->where('status', ProxyEndpoint::STATUS_ACTIVE)->count(),
                'cooldown' => ProxyEndpoint::query()->where('status', ProxyEndpoint::STATUS_COOLDOWN)->count(),
                'dead' => ProxyEndpoint::query()->where('status', ProxyEndpoint::STATUS_DEAD)->count(),
            ],
            // Блок диагностики: последние "проблемные" прокси для быстрого triage прямо из UI.
            'recentProblemProxies' => ProxyEndpoint::query()
                ->whereNotNull('last_error')
                ->orWhereNotNull('last_failed_at')
                ->orderByDesc('failure_count')
                ->orderByDesc('last_failed_at')
                ->orderByDesc('updated_at')
                ->get(['id', 'url', 'status', 'failure_count', 'last_error', 'last_failed_at', 'cooldown_until']),
        ]);
    }

    public function refreshProxies(ProxyRotationService $proxyRotation)
    {
        $apiKey = (string) config('services.webshare.api_key', '');
        if ($apiKey === '') {
            return back()->with('error', 'WEBSHARE_API_KEY не задан в .env');
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
        } catch (RequestException $e) {
            return back()->with('error', 'Ошибка запроса к Webshare API: '.$e->getMessage());
        }

        $rows = $response->json('results');
        if (! is_array($rows) || count($rows) === 0) {
            return back()->with('error', 'Webshare вернул пустой список прокси.');
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

            $proxyPool[] = sprintf(
                'http://%s:%s@%s:%s',
                rawurlencode((string) $username),
                rawurlencode((string) $password),
                (string) $address,
                (string) $port,
            );
        }

        if (count($proxyPool) === 0) {
            return back()->with('error', 'Нет валидных прокси для назначения.');
        }

        $activeInstrumentsCount = PocketInstrument::query()->active()->count();
        if ($activeInstrumentsCount === 0) {
            return back()->with('error', 'Нет активных инструментов.');
        }

        $proxyCount = count($proxyPool);
        DB::transaction(function () use ($proxyPool): void {
            // Поведение кнопки сохраняем: "Обновить прокси" полностью пересобирает пул.
            // 1) Помечаем все существующие прокси как dead (архив), чтобы не назначать их случайно.
            // 2) Upsert нового пула как active и обнуляем счётчики ошибок.
            ProxyEndpoint::query()->update([
                'status' => ProxyEndpoint::STATUS_DEAD,
                'updated_at' => now(),
            ]);

            foreach ($proxyPool as $url) {
                DB::table('proxy_endpoints')->updateOrInsert(
                    ['url' => $url],
                    [
                        'status' => ProxyEndpoint::STATUS_ACTIVE,
                        'failure_count' => 0,
                        'last_error' => null,
                        'last_failed_at' => null,
                        'cooldown_until' => null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        });

        $assigned = $proxyRotation->assignRoundRobinToActiveInstruments();

        return back()->with(
            'success',
            sprintf(
                'Прокси полностью обновлены: назначено %d активным инструментам из %d прокси в новом пуле.',
                $assigned,
                $proxyCount,
            )
        );
    }

    public function updateProxyRotation(\Illuminate\Http\Request $request, ProxyRotationService $proxyRotation)
    {
        $validated = $request->validate([
            'proxy_cooldown_streak_threshold' => 'nullable|integer|min:1|max:1000',
            'proxy_max_instruments_per_endpoint' => 'nullable|integer|min:1|max:100',
        ]);

        // Явная boolean-настройка через checkbox "on/off".
        $enabled = $request->boolean('proxy_auto_rotation_enabled');
        $threshold = (int) ($validated['proxy_cooldown_streak_threshold'] ?? $proxyRotation->getCooldownStreakThreshold());
        $maxInstrumentsPerProxy = (int) ($validated['proxy_max_instruments_per_endpoint'] ?? $proxyRotation->getMaxInstrumentsPerProxy());
        $proxyRotation->setAutoRotationEnabled($enabled);
        $proxyRotation->setCooldownStreakThreshold($threshold);
        $proxyRotation->setMaxInstrumentsPerProxy($maxInstrumentsPerProxy);

        return back()->with(
            'success',
            $enabled
                ? "Автоматическая ротация прокси включена. Порог cooldown: streak >= {$threshold}, лимит нагрузки: до {$maxInstrumentsPerProxy} инструмент(ов) на 1 прокси."
                : "Автоматическая ротация прокси выключена. Порог сохранён: streak >= {$threshold}, лимит нагрузки: до {$maxInstrumentsPerProxy} инструмент(ов) на 1 прокси."
        );
    }

    public function reactivateProxy(ProxyEndpoint $proxy)
    {
        // "Реанимация" прокси: возвращаем endpoint в рабочий пул.
        // При ручной реанимации начинаем "новый цикл жизни" прокси:
        // обнуляем счётчик ошибок, чтобы endpoint заново оценивался с нуля.
        $proxy->update([
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 0,
            'last_error' => null,
            'last_failed_at' => null,
            'cooldown_until' => null,
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Прокси возвращён в active-пул.');
    }

    public function reactivateAllProblemProxies()
    {
        // 1) Реанимируем статусы dead/cooldown -> active.
        $reactivated = ProxyEndpoint::query()
            ->whereIn('status', [ProxyEndpoint::STATUS_DEAD, ProxyEndpoint::STATUS_COOLDOWN])
            ->update([
                'status' => ProxyEndpoint::STATUS_ACTIVE,
                'last_error' => null,
                'last_failed_at' => null,
                'cooldown_until' => null,
                'updated_at' => now(),
            ]);

        // 2) Сбрасываем счётчик ошибок у всех proxy с failure_count > 0,
        // независимо от текущего статуса (active/cooldown/dead).
        $resetCounters = ProxyEndpoint::query()
            ->where('failure_count', '>', 0)
            ->update([
                'failure_count' => 0,
                'last_error' => null,
                'last_failed_at' => null,
                'updated_at' => now(),
            ]);

        if ($reactivated === 0 && $resetCounters === 0) {
            return back()->with('error', 'Нет прокси для реанимации или сброса счётчиков.');
        }

        return back()->with(
            'success',
            "Реанимировано (dead/cooldown→active): {$reactivated} шт.; сброшено счётчиков ошибок: {$resetCounters} шт."
        );
    }

    public function updatePayoutMonitoring(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'payout_stale_minutes_threshold' => 'required|integer|min:1|max:1440',
        ]);

        $thresholdMinutes = (int) $validated['payout_stale_minutes_threshold'];

        // Храним порог "устаревания" payout в app_settings, чтобы UI "Инструменты"
        // мог динамически подсвечивать stale-значения без хардкода на 5 минут.
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_PAYOUT_STALE_MINUTES_THRESHOLD],
            [
                'value' => (string) $thresholdMinutes,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return back()->with(
            'success',
            "Порог stale для колонки «Обновлён Payout» сохранён: {$thresholdMinutes} мин."
        );
    }

    private function getPayoutStaleMinutesThreshold(): int
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_PAYOUT_STALE_MINUTES_THRESHOLD)
            ->value('value');

        $minutes = (int) ($value ?? self::DEFAULT_PAYOUT_STALE_MINUTES_THRESHOLD);

        // Защищаемся от некорректных значений при ручном редактировании БД.
        return max(1, min(1440, $minutes));
    }
}
