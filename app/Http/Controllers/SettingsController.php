<?php

namespace App\Http\Controllers;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Services\CurrentCandleCacheService;
use App\Services\DataCleanupService;
use App\Services\ProxyDiagnosticsService;
use App\Services\ProxyRotationService;
use App\Support\BotScanCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    private const SETTING_PAYOUT_STALE_MINUTES_THRESHOLD = 'payout_stale_minutes_threshold';
    private const SETTING_PROXY_REACTIVATION_PROBE_INSTRUMENT = 'proxy_reactivation_probe_instrument';
    private const SETTING_PROXY_AUTO_TEST_ENABLED = 'proxy_auto_test_enabled';
    private const SETTING_PROXY_AUTO_TEST_ALL_ENABLED = 'proxy_auto_test_all_enabled';
    private const SETTING_PROXY_PROBE_WINDOW_SEC = 'proxy_probe_window_sec';
    private const DEFAULT_PAYOUT_STALE_MINUTES_THRESHOLD = 5;

    public function index(
        ProxyRotationService $proxyRotation,
        ProxyDiagnosticsService $proxyDiagnostics,
        \Illuminate\Http\Request $request,
    ): \Illuminate\View\View {
        $payoutStaleMinutesThreshold = $this->getPayoutStaleMinutesThreshold();
        $healthFilter = (string) $request->query('health_filter', 'all');

        return view('settings.index', [
            'proxyAutoRotationEnabled' => $proxyRotation->isAutoRotationEnabled(),
            'proxyDeadOnPoStaleEnabled' => $proxyRotation->isDeadOnPoStaleEnabled(),
            'proxyDeadOnPoStaleMinutes' => $proxyRotation->getDeadOnPoStaleMinutes(),
            'proxyDeadStreakThreshold' => $proxyRotation->getDeadStreakThreshold(),
            'proxyMaxInstrumentsPerEndpoint' => $proxyRotation->getMaxInstrumentsPerProxy(),
            'payoutStaleMinutesThreshold' => $payoutStaleMinutesThreshold,
            'proxyHealthFilter' => $healthFilter,
            'proxyPoolHealth' => $proxyDiagnostics->buildPoolHealth($healthFilter, $payoutStaleMinutesThreshold),
            'proxyPoolCapacity' => $proxyRotation->getPoolCapacitySummary(),
            // Все инструменты для выбора контрольной пары (в т.ч. is_active=false):
            // пробу можно вести отдельной «выключенной» парой, не включая её в основной ingest-пул.
            'proxyReactivationProbeInstruments' => PocketInstrument::query()
                ->orderByDesc('is_active')
                ->orderBy('instrument')
                ->get(['instrument', 'display_name', 'is_active']),
            'proxyReactivationProbeInstrument' => $this->getProxyReactivationProbeInstrument(),
            'proxyAutoTestEnabled' => $this->isProxyAutoTestEnabled(),
            'proxyAutoTestAllEnabled' => $this->isProxyAutoTestAllEnabled(),
            'proxyProbeWindowSec' => $this->getProxyProbeWindowSec(),
            'proxyStats' => [
                'total' => ProxyEndpoint::query()->count(),
                'active' => ProxyEndpoint::query()->where('status', ProxyEndpoint::STATUS_ACTIVE)->count(),
                'dead' => ProxyEndpoint::query()->where('status', ProxyEndpoint::STATUS_DEAD)->count(),
            ],
            'dataAutoCleanupRetentionHours' => app(DataCleanupService::class)->getAutoCleanupRetentionHours(),
            // Watchdog bots:scan — порог wall-time и снимок живых процессов.
            'scanWatchdogMaxElapsedSec' => BotScanCoordinator::getScanMaxElapsedSec(),
            'scanWatchdogAllowedElapsedSec' => BotScanCoordinator::ALLOWED_WATCHDOG_MAX_ELAPSED_SEC,
            'scanWatchdogRunning' => BotScanCoordinator::runningScanStatusRows(),
        ]);
    }

    public function refreshProxies(
        ProxyRotationService $proxyRotation,
        CurrentCandleCacheService $currentCandleCache,
    )
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
            // Полная замена пула: старые endpoint удаляются из БД (у инструментов proxy_endpoint_id → NULL).
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

        $assigned = $proxyRotation->assignRoundRobinToActiveInstruments();

        // Сигнал ingest: немедленно перечитать proxy_url и перезапустить WebSocket-воркеры.
        $proxyRotation->markPoolRefreshed();

        // Сбрасываем partial-свечи в Redis — иначе график ещё ~3 мин показывает старый хвост
        // от прежних прокси, пока не истечёт TTL current_candle:*.
        $currentCandleCache->clearAllPartials(
            PocketInstrument::query()->active()->pluck('instrument')->all()
        );

        return back()->with(
            'success',
            sprintf(
                'Прокси полностью обновлены: назначено %d активным инструментам из %d прокси в новом пуле. '
                .'Ingest подхватит новые endpoint в течение ~15 с; partial-кэш свечей сброшен.',
                $assigned,
                $proxyCount,
            )
        );
    }

    public function updateProxyRotation(\Illuminate\Http\Request $request, ProxyRotationService $proxyRotation)
    {
        $validated = $request->validate([
            'proxy_dead_streak_threshold' => 'nullable|integer|min:1|max:1000',
            'proxy_max_instruments_per_endpoint' => 'nullable|integer|min:1|max:100',
            'proxy_dead_on_po_stale_minutes' => 'nullable|integer|min:1|max:1440',
            'proxy_reactivation_probe_instrument' => 'nullable|string|max:63',
            'proxy_probe_window_sec' => 'nullable|integer|min:5|max:180',
        ]);

        // Явная boolean-настройка через checkbox "on/off".
        $enabled = $request->boolean('proxy_auto_rotation_enabled');
        $deadOnPoStaleEnabled = $request->boolean('proxy_dead_on_po_stale_enabled');
        $threshold = (int) ($validated['proxy_dead_streak_threshold'] ?? $proxyRotation->getDeadStreakThreshold());
        $deadOnPoStaleMinutes = (int) ($validated['proxy_dead_on_po_stale_minutes'] ?? $proxyRotation->getDeadOnPoStaleMinutes());
        $maxInstrumentsPerProxy = (int) ($validated['proxy_max_instruments_per_endpoint'] ?? $proxyRotation->getMaxInstrumentsPerProxy());
        $proxyRotation->setAutoRotationEnabled($enabled);
        $proxyRotation->setDeadOnPoStaleEnabled($deadOnPoStaleEnabled);
        $proxyRotation->setDeadOnPoStaleMinutes($deadOnPoStaleMinutes);
        $proxyRotation->setDeadStreakThreshold($threshold);
        $proxyRotation->setMaxInstrumentsPerProxy($maxInstrumentsPerProxy);
        // Параметр "контрольного инструмента" для авто-реанимации dead:
        // возвращаем прокси в active только если по этой паре есть признаки живого PO/WS.
        $probeInstrument = trim((string) ($validated['proxy_reactivation_probe_instrument'] ?? ''));
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_PROXY_REACTIVATION_PROBE_INSTRUMENT],
            [
                'value' => $probeInstrument,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        // «Все прокси» и «только dead» взаимоисключительны: при all режим dead-флаг не сохраняем.
        $allProxiesTest = $request->boolean('proxy_auto_test_all_enabled');
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_PROXY_AUTO_TEST_ALL_ENABLED],
            [
                'value' => $allProxiesTest ? '1' : '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_PROXY_AUTO_TEST_ENABLED],
            [
                'value' => (! $allProxiesTest && $request->boolean('proxy_auto_test_enabled')) ? '1' : '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $probeWindowSec = max(5, min(180, (int) ($validated['proxy_probe_window_sec'] ?? $this->getProxyProbeWindowSec())));
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_PROXY_PROBE_WINDOW_SEC],
            [
                'value' => (string) $probeWindowSec,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $poStalePart = $deadOnPoStaleEnabled
            ? " Dead по stale PO: включено, порог {$deadOnPoStaleMinutes} мин."
            : ' Dead по stale PO: выключено.';

        return back()->with(
            'success',
            ($enabled
                ? "Автоматическая ротация прокси включена. Порог dead: streak >= {$threshold}, лимит нагрузки: до {$maxInstrumentsPerProxy} инструмент(ов) на 1 прокси."
                : "Автоматическая ротация прокси выключена. Порог сохранён: streak >= {$threshold}, лимит нагрузки: до {$maxInstrumentsPerProxy} инструмент(ов) на 1 прокси.")
            .$poStalePart
        );
    }

    public function reactivateProxy(ProxyEndpoint $proxy)
    {
        // dead → active: сбрасываем streak и текст ошибки — endpoint оценивается заново с нуля.
        $proxy->update(ProxyEndpoint::deadToActiveResetAttributes());

        return back()->with('success', 'Прокси возвращён в active-пул.');
    }

    /**
     * Ручная пометка прокси dead в таблице пула на /settings.
     */
    public function markProxyDead(ProxyEndpoint $proxy, ProxyRotationService $proxyRotation)
    {
        $result = $proxyRotation->markProxyDeadManually($proxy);

        if ($result['already_dead']) {
            return back()->with('success', 'Прокси уже в статусе dead.');
        }

        $message = 'Прокси помечен как dead.';
        if ($result['rotated'] > 0) {
            $message .= " Переназначено инструментов: {$result['rotated']}.";
        }
        if ($result['instruments_left'] > 0) {
            $message .= " Осталось на dead без замены: {$result['instruments_left']} (нет свободных слотов).";
        }

        return back()->with('success', $message);
    }

    public function reactivateAllProblemProxies()
    {
        // Реанимируем dead → active и сразу обнуляем счётчик/ошибки у каждого такого endpoint.
        $reactivated = ProxyEndpoint::query()
            ->where('status', ProxyEndpoint::STATUS_DEAD)
            ->update(ProxyEndpoint::deadToActiveResetAttributes());

        // Дополнительно сбрасываем streak у active-прокси, которые ещё не dead, но копят ошибки.
        $resetCounters = ProxyEndpoint::query()
            ->where('status', ProxyEndpoint::STATUS_ACTIVE)
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
            "Реанимировано (dead→active): {$reactivated} шт.; сброшено счётчиков ошибок: {$resetCounters} шт."
        );
    }

    public function updateDataAutoCleanup(\Illuminate\Http\Request $request, DataCleanupService $dataCleanup): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'auto_cleanup_retention_hours' => 'nullable|string|max:8',
        ]);

        $raw = trim((string) ($validated['auto_cleanup_retention_hours'] ?? ''));
        if ($raw !== '' && ! in_array((int) $raw, DataCleanupService::ALLOWED_AUTO_CLEANUP_RETENTION_HOURS, true)) {
            return back()->with('error', 'Недопустимый период автоматической очистки.');
        }

        $hours = $dataCleanup->normalizeAutoCleanupRetentionHours($raw !== '' ? $raw : null);
        $dataCleanup->setAutoCleanupRetentionHours($hours);

        return back()->with(
            'success',
            $hours === null
                ? 'Автоматическая очистка данных отключена.'
                : 'Автоматическая очистка по крону: удалять данные старше '
                    .$dataCleanup->autoCleanupRetentionLabel($hours)
                    .' (команда data:auto-cleanup, каждый час).',
        );
    }

    /**
     * Порог wall-time для bots:scan-watchdog (убить зависший scan и перезапустить).
     */
    public function updateScanWatchdog(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        $allowed = BotScanCoordinator::ALLOWED_WATCHDOG_MAX_ELAPSED_SEC;
        $validated = $request->validate([
            'scan_watchdog_max_elapsed_sec' => 'required|integer|in:'.implode(',', $allowed),
        ]);

        $sec = (int) $validated['scan_watchdog_max_elapsed_sec'];
        BotScanCoordinator::setScanMaxElapsedSec($sec);

        return back()->with(
            'success',
            'Watchdog bots:scan: убивать процесс дольше '
                .BotScanCoordinator::scanMaxElapsedLabel($sec)
                .' (команда bots:scan-watchdog каждые 5 мин).',
        );
    }

    public function purgeOldData(\Illuminate\Http\Request $request, DataCleanupService $dataCleanup): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            // Окно хранения из селекта рядом с кнопкой «Удалить все данные».
            'retention_hours' => 'nullable|integer|in:'.implode(',', DataCleanupService::ALLOWED_RETENTION_HOURS),
        ]);

        $retentionHours = $dataCleanup->normalizeRetentionHours(
            (int) ($validated['retention_hours'] ?? DataCleanupService::DEFAULT_RETENTION_HOURS)
        );

        $result = $dataCleanup->purgeOlderThan($retentionHours);

        return back()->with(
            'success',
            $dataCleanup->formatResultMessage($result),
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
            "Порог stale для колонки «Событие payout (PO)» сохранён: {$thresholdMinutes} мин."
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

    private function getProxyReactivationProbeInstrument(): string
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_PROXY_REACTIVATION_PROBE_INSTRUMENT)
            ->value('value');

        // Сохраняем пустую строку как "режим выключен": фоновая команда просто пропустит реанимацию.
        return trim((string) ($value ?? ''));
    }

    private function isProxyAutoTestEnabled(): bool
    {
        if ($this->isProxyAutoTestAllEnabled()) {
            return false;
        }

        $value = DB::table('app_settings')
            ->where('key', self::SETTING_PROXY_AUTO_TEST_ENABLED)
            ->value('value');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private function isProxyAutoTestAllEnabled(): bool
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_PROXY_AUTO_TEST_ALL_ENABLED)
            ->value('value');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private function getProxyProbeWindowSec(): int
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_PROXY_PROBE_WINDOW_SEC)
            ->value('value');

        $sec = (int) ($value ?? \App\Services\ProxyDeadProbeService::DEFAULT_PROBE_WINDOW_SEC);

        return max(5, min(180, $sec));
    }

    public function testQuickProxyAjax(ProxyEndpoint $proxy, ProxyDiagnosticsService $proxyDiagnostics): JsonResponse
    {
        return $this->runProxyHealthCheck($proxy, $proxyDiagnostics, 1, 8, 'quick');
    }

    public function testDeepProxyAjax(ProxyEndpoint $proxy, ProxyDiagnosticsService $proxyDiagnostics): JsonResponse
    {
        // Deep-check без очереди: синхронно в одном AJAX-запросе с окном 45 секунд.
        return $this->runProxyHealthCheck($proxy, $proxyDiagnostics, 45, 8, 'deep');
    }

    public function proxyHealthRowAjax(ProxyEndpoint $proxy, ProxyDiagnosticsService $proxyDiagnostics): JsonResponse
    {
        $row = $proxyDiagnostics->buildProxyRowById($proxy->id, $this->getPayoutStaleMinutesThreshold());

        if (! $row) {
            return response()->json(['ok' => false, 'error' => 'proxy_not_found'], 404);
        }

        return response()->json([
            'ok' => true,
            'row' => $this->serializeHealthRow($row),
        ]);
    }

    public function proxyHealthLiveAjax(ProxyDiagnosticsService $proxyDiagnostics): JsonResponse
    {
        $poolHealth = $proxyDiagnostics->buildPoolHealth('all', $this->getPayoutStaleMinutesThreshold());
        $rows = collect($poolHealth['rows'] ?? [])
            ->map(fn (array $row): array => $this->serializeHealthRow($row))
            ->values()
            ->all();
        $currentProbe = DB::table('app_settings')
            ->where('key', 'proxy_auto_test_current')
            ->value('value');

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'rows' => $rows,
            'current_probe' => is_string($currentProbe) && $currentProbe !== ''
                ? json_decode($currentProbe, true)
                : null,
        ]);
    }

    private function runProxyHealthCheck(
        ProxyEndpoint $proxy,
        ProxyDiagnosticsService $proxyDiagnostics,
        int $durationSec,
        int $requestTimeoutSec,
        string $mode,
    ): JsonResponse
    {
        $durationSec = max(1, $durationSec);
        $requestTimeoutSec = max(1, $requestTimeoutSec);
        $startedAt = microtime(true);
        $loopStartedAt = time();
        $successCount = 0;
        $lastLatencyMs = null;
        $lastStatusCode = null;

        try {
            while ((time() - $loopStartedAt) < $durationSec) {
                $singleRequestStartedAt = microtime(true);

                $response = Http::timeout($requestTimeoutSec)
                    ->withOptions([
                        'proxy' => $proxy->url,
                        'verify' => false,
                    ])
                    ->acceptJson()
                    ->get('https://api.ipify.org?format=json');

                $lastLatencyMs = (int) round((microtime(true) - $singleRequestStartedAt) * 1000);
                $lastStatusCode = (int) $response->status();

                if ($lastStatusCode === 200) {
                    $successCount++;
                }

                if ($durationSec === 1) {
                    break;
                }

                usleep(800000);
            }
        } catch (\Throwable) {
            $proxyDiagnostics->persistHttpCheckResult($proxy, 'fail', $lastStatusCode, $lastLatencyMs, $mode);

            $healthRow = $proxyDiagnostics->buildProxyRowById($proxy->id, $this->getPayoutStaleMinutesThreshold());

            return response()->json([
                'ok' => true,
                'status' => 'fail',
                'details' => 'fail - timeout/conn error/auth error.',
                'latency_ms' => $lastLatencyMs,
                'duration_sec' => (int) round(microtime(true) - $startedAt),
                'events_count' => $successCount,
                'http_status' => $lastStatusCode,
                'saved_to_db' => true,
                'health' => $healthRow ? [
                    'http' => $healthRow['http'],
                    'ws' => $healthRow['ws'],
                    'payout' => $healthRow['payout'],
                    'recommendation' => $healthRow['recommendation'],
                ] : null,
            ]);
        }

        $classification = $this->classifyProxyCheckResult($lastStatusCode, $lastLatencyMs);
        $proxyDiagnostics->persistHttpCheckResult(
            $proxy,
            $classification['status'],
            $lastStatusCode,
            $lastLatencyMs,
            $mode,
        );

        $healthRow = $proxyDiagnostics->buildProxyRowById($proxy->id, $this->getPayoutStaleMinutesThreshold());

        return response()->json([
            'ok' => true,
            'status' => $classification['status'],
            'details' => $classification['details'],
            'latency_ms' => $lastLatencyMs,
            'duration_sec' => (int) round(microtime(true) - $startedAt),
            'events_count' => $successCount,
            'http_status' => $lastStatusCode,
            'saved_to_db' => true,
            'health' => $healthRow ? [
                'http' => $healthRow['http'],
                'ws' => $healthRow['ws'],
                'payout' => $healthRow['payout'],
                'recommendation' => $healthRow['recommendation'],
            ] : null,
        ]);
    }

    private function classifyProxyCheckResult(?int $statusCode, ?int $latencyMs): array
    {
        if ($statusCode !== 200) {
            return [
                'status' => 'fail',
                'details' => 'fail - timeout/conn error/auth error.',
            ];
        }

        if (($latencyMs ?? PHP_INT_MAX) < 2000) {
            return [
                'status' => 'ok',
                'details' => 'ok - ответ 200 и latency в разумном диапазоне.',
            ];
        }

        return [
            'status' => 'warn',
            'details' => 'warn - 200, но медленно.',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeHealthRow(array $row): array
    {
        /** @var ProxyEndpoint $proxy */
        $proxy = $row['proxy'];

        return [
            'proxy_id' => (int) ($row['proxy_id'] ?? $proxy->id),
            'status' => (string) ($row['status'] ?? $proxy->status),
            'failure_count' => (int) ($row['failure_count'] ?? $proxy->failure_count),
            'last_error' => (string) ($row['last_error'] ?? ''),
            'last_failed_at' => $proxy->last_failed_at?->toIso8601String(),
            'latest_dead_reason' => $row['latest_dead_reason'] ?? $proxy->latestDeadReasonEntry(),
            'recommendation' => (string) ($row['recommendation'] ?? ''),
            'is_false_dead' => (bool) ($row['is_false_dead'] ?? false),
            'http' => $row['http'] ?? null,
            'ws' => $row['ws'] ?? null,
            'payout' => $row['payout'] ?? null,
            'last_auto_test' => is_array($proxy->last_auto_test) ? $proxy->last_auto_test : null,
        ];
    }
}
