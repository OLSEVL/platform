<?php

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Bots\BotEngineService;
use App\Services\Bots\BotSettlementService;
use App\Services\Bots\BotVirtualTradeSettlementService;
use App\Services\Bots\OpenRouterClient;
use App\Services\DataCleanupService;
use App\Services\ProxyDeadProbeService;
use App\Services\ProxyRotationService;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramAnalystHandler;
use App\Services\Telegram\TelegramAnalystProcessManager;
use App\Services\WebshareProxyRefreshService;
use App\Support\BotScanCoordinator;
use App\Support\ChartAnalystBotTypes;
use App\Support\OpenRouterChartLevelsParser;
use App\Support\OpenRouterLlmConfig;
use App\Support\WebshareProxyRefreshSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Реальный процесс scan: читаем cmdline PHP, а не pgrep по всей строке bash/cursor.
 */
if (! function_exists('bots_scan_process_running')) {
    function bots_scan_process_running(?int $exceptPid = null, ?int $botId = null): bool
    {
        return BotScanCoordinator::isScanRunning($botId, $exceptPid);
    }
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('data:auto-cleanup', function (DataCleanupService $dataCleanup) {
    $hours = $dataCleanup->getAutoCleanupRetentionHours();
    if ($hours === null) {
        $this->info('Авто-очистка отключена в /settings — пропуск.');

        return;
    }

    $result = $dataCleanup->purgeOlderThan($hours);
    $this->info($dataCleanup->formatResultMessage($result));
})->purpose('Авто-удаление данных по расписанию (настройка /settings → Очистка данных)');

Artisan::command('proxies:refresh-webshare', function (WebshareProxyRefreshService $webshareRefresh) {
    // Команду запускает scheduler в 00:00, но она также доступна для безопасной
    // проверки вручную. Настройка читается во время запуска, поэтому переключатель
    // из /settings применяется без рестарта schedule:work или cron.
    if (! WebshareProxyRefreshSettings::isEnabled()) {
        $this->info('Ежедневное обновление Webshare выключено в /settings — пропуск.');

        return 0;
    }

    try {
        $result = $webshareRefresh->refresh();
    } catch (\RuntimeException $exception) {
        // Ненулевой exit code позволяет cron/systemd зафиксировать сбой запроса
        // и отличить его от штатного отключения настройки выше.
        $this->error('Обновление Webshare не выполнено: '.$exception->getMessage());

        return 1;
    }

    $this->info(sprintf(
        'Webshare обновлён: %d прокси в пуле, назначено %d активным инструментам.',
        $result['proxy_count'],
        $result['assigned'],
    ));

    return 0;
})->purpose('Полностью обновить пул прокси из Webshare (кнопка /settings и ежедневное расписание)');

Artisan::command('proxies:refresh-webshare-if-exhausted', function (
    WebshareProxyRefreshService $webshareRefresh,
    ProxyRotationService $proxyRotation,
) {
    // Почасовой fallback: не трогаем Webshare, пока в пуле ещё есть свободные слоты.
    // Критерий совпадает с баннером «Пул прокси исчерпан» на /settings (is_exhausted).
    if (! WebshareProxyRefreshSettings::isHourlyOnExhaustedEnabled()) {
        $this->info('Почасовое обновление Webshare при исчерпании пула выключено в /settings — пропуск.');

        return 0;
    }

    $capacity = $proxyRotation->getPoolCapacitySummary();
    if (! ($capacity['is_exhausted'] ?? false)) {
        $this->info(sprintf(
            'Пул не исчерпан (free_slots=%d, ready=%d) — обновление Webshare не требуется.',
            (int) ($capacity['free_slots'] ?? 0),
            (int) ($capacity['ready_proxies'] ?? 0),
        ));

        return 0;
    }

    $this->warn(sprintf(
        'Пул исчерпан (free_slots=0, active=%d, on_dead=%d) — запрос списка Webshare…',
        (int) ($capacity['active_instruments'] ?? 0),
        (int) ($capacity['instruments_on_dead_proxy'] ?? 0),
    ));

    try {
        $result = $webshareRefresh->refresh();
    } catch (\RuntimeException $exception) {
        $this->error('Обновление Webshare не выполнено: '.$exception->getMessage());

        return 1;
    }

    $this->info(sprintf(
        'Webshare обновлён после исчерпания пула: %d прокси, назначено %d активным инструментам.',
        $result['proxy_count'],
        $result['assigned'],
    ));

    return 0;
})->purpose('Обновить Webshare только если пул прокси исчерпан (hourly, настройка /settings)');

Artisan::command('candles:prune-old', function (DataCleanupService $dataCleanup) {
    // Обратная совместимость: делегируем в data:auto-cleanup с теми же настройками из БД.
    $hours = $dataCleanup->getAutoCleanupRetentionHours();
    if ($hours === null) {
        $this->info('Авто-очистка отключена в /settings — пропуск.');

        return;
    }

    $result = $dataCleanup->purgeOlderThan($hours);
    $this->info($dataCleanup->formatResultMessage($result));
})->purpose('Устаревший алиас data:auto-cleanup (свечи и прочие данные по настройке /settings)');

Artisan::command('candles:ingest-status', function () {
    $total = InstrumentCandle::query()->count();
    $this->line("Всего записей в candles: {$total}");
    $rows = InstrumentCandle::query()
        ->selectRaw('instrument, MAX(time) as last_time, MAX(updated_at) as last_write')
        ->groupBy('instrument')
        ->orderByDesc('last_write')
        ->limit(20)
        ->get();
    if ($rows->isEmpty()) {
        $this->warn('Нет свечей. Проверьте: сервис pocket-options-proxy с PLATFORM_URL, активные инструменты и session_token.');

        return;
    }
    foreach ($rows as $r) {
        $age = time() - (int) $r->last_time;
        $this->line(sprintf(
            '  %s  last_candle_ts=%s (%d min ago)  updated=%s',
            $r->instrument,
            $r->last_time,
            intdiv(max(0, $age), 60),
            $r->last_write
        ));
    }
})->purpose('Показать свежесть свечей в БД (проверка инжеста)');

Artisan::command('proxies:probe-once', function (ProxyDeadProbeService $probe) {
    $probe->releaseStaleLock();

    if (! $probe->isEnabled()) {
        $this->warn('Пропуск: авто-тест прокси выключен в настройках.');

        return;
    }

    if ($probe->getProbeInstrumentName() === null) {
        $this->warn('Пропуск: не выбран инструмент auto-test.');

        return;
    }

    $target = $probe->pickNextProbeProxy();
    if (! $target) {
        $this->info('Нет прокси для проверки (scope='.$probe->getProbeScope().').');

        return;
    }

    $result = $probe->runSingleProbe($target);
    $this->info(sprintf(
        'Probe proxy #%d: status=%s reason=%s instrument=%s',
        $target->id,
        $result['status'] ?? '—',
        $result['reason'] ?? '—',
        $result['instrument'] ?? '—',
    ));
})->purpose('Одна авто-проба следующего dead-прокси (временное назначение контрольной пары)');

Artisan::command('proxies:probe-dead {--limit=25}', function (ProxyDeadProbeService $probe) {
    $probe->releaseStaleLock();

    if (! $probe->isEnabled()) {
        $this->warn('Пропуск: авто-тест прокси выключен в настройках.');

        return;
    }

    if ($probe->getProbeInstrumentName() === null) {
        $this->warn('Пропуск: не выбран инструмент auto-test.');

        return;
    }

    $limit = max(1, min(200, (int) $this->option('limit')));
    $stats = ['ok' => 0, 'fail' => 0, 'skip' => 0];

    for ($i = 0; $i < $limit; $i++) {
        $target = $probe->pickNextProbeProxy();
        if (! $target) {
            break;
        }
        $result = $probe->runSingleProbe($target);
        $status = (string) ($result['status'] ?? 'skip');
        if (! isset($stats[$status])) {
            $stats[$status] = 0;
        }
        $stats[$status]++;
    }

    $this->info(sprintf(
        'Batch probe done: ok=%d fail=%d skip=%d (limit=%d, window=%ds)',
        $stats['ok'] ?? 0,
        $stats['fail'] ?? 0,
        $stats['skip'] ?? 0,
        $limit,
        $probe->getProbeWindowSec(),
    ));
})->purpose('Пакетная авто-проба dead-прокси (временное назначение контрольной пары)');

Artisan::command('proxies:probe-loop', function (ProxyDeadProbeService $probe) {
    $this->info('proxies:probe-loop started (Ctrl+C to stop).');

    while (true) {
        // releaseStaleLock вне try раньше валил весь daemon (FK на удалённый proxy).
        try {
            $probe->releaseStaleLock();
        } catch (\Throwable $e) {
            $this->error('Stale lock cleanup failed: '.$e->getMessage());
            try {
                $probe->forceReleaseLock();
            } catch (\Throwable) {
                // ignore secondary cleanup errors
            }
            sleep(2);

            continue;
        }

        if (! $probe->isEnabled() || $probe->getProbeInstrumentName() === null) {
            $probe->setCurrentProbeIdle();
            sleep(5);

            continue;
        }

        $target = $probe->pickNextProbeProxy();
        if (! $target) {
            $probe->setCurrentProbeIdle();
            sleep(2);

            continue;
        }

        try {
            $result = $probe->runSingleProbe($target);
            $this->line(sprintf(
                '[%s] proxy #%d %s / %s',
                now()->format('H:i:s'),
                $target->id,
                $result['status'] ?? '—',
                $result['reason'] ?? '—',
            ));
            // Мгновенный skip (нет инструмента и т.п.) — пауза, чтобы не забивать journal.
            if (($result['status'] ?? '') === 'skip') {
                sleep(2);
            }
        } catch (\Throwable $e) {
            try {
                $probe->forceReleaseLock();
            } catch (\Throwable $cleanupError) {
                $this->error('Lock cleanup failed: '.$cleanupError->getMessage());
            }
            $this->error('Probe error: '.$e->getMessage());
            sleep(2);
        }
    }
})->purpose('Бесконечный цикл авто-пробы dead-прокси по кругу (daemon)');

Artisan::command('bots:status', function () {
    $bots = Bot::query()->orderBy('id')->get(['id', 'name', 'type', 'is_active']);
    $this->line('Боты: '.$bots->count());
    foreach ($bots as $b) {
        $this->line(sprintf(
            '  #%d %s (%s) %s',
            $b->id,
            $b->name,
            $b->type,
            $b->is_active ? 'active' : 'inactive'
        ));
    }

    $activeInstruments = PocketInstrument::active()->count();
    $this->line('Активных инструментов (PocketInstrument): '.$activeInstruments);

    $signals = BotSignal::query()->count();
    $trades = BotTrade::query()->count();
    $open = BotTrade::query()->where('status', BotTrade::STATUS_OPEN)->count();
    $lastSignal = BotSignal::query()->latest('signal_at')->first();
    $this->line(sprintf('Сигналов: %d, сделок: %d, открытых сделок: %d', $signals, $trades, $open));
    if ($lastSignal) {
        $this->line('Последний сигнал: '.$lastSignal->signal_at->toIso8601String().' ('.$lastSignal->instrument.')');
    } else {
        $this->warn('Сигналов ещё не было. Запустите: php artisan bots:scan');
    }

    $this->newLine();
    $this->comment('Автоскан: в .env-среде должен работать планировщик (cron каждую минуту: php artisan schedule:run) или локально: php artisan schedule:work');
    $this->comment('Вручную: php artisan bots:scan-dispatch && php artisan bots:settle');
    $this->comment('Нет новых сделок при повторном скане — нормально, если по инструменту уже есть открытая сделка, сработал dedupe, или стратегия не даёт сигнал.');
})->purpose('Диагностика ботов: данные, открытые сделки, напоминание про планировщик');

Artisan::command('bots:bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Three Candles Bot', 'type' => 'three_candles_in_row'],
        [
            'is_active' => true,
            'config' => [
                'count' => 120,
                // Одна минута под стандартный M1: три свечи в одну сторону → вход на противоположную.
                'expiry_sec' => 60,
                'min_confidence' => 0.0,
                'min_payout' => 80.0,
                'stale_after_sec' => 45,
                'version' => 'v7_three_same_opposite',
            ],
        ]
    );

    $this->info("Бот готов: #{$bot->id} {$bot->name} ({$bot->type})");
})->purpose('Создать базового бота "3 свечи в ряд"');

Artisan::command('bots:rsi-contrtrend-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    // M1 OTC defaults (после sweep): SMA 10, RSI 14, уровни 60/40; expiry 3 мин.
    // OsEngine 50/20/65-35 на PO почти не стреляет — см. RsiContrtrendStrategy.
    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'RSI Contrtrend', 'type' => 'rsi_contrtrend'],
        [
            'is_active' => false,
            'config' => [
                'count' => 150,
                'expiry_sec' => 180,
                'min_confidence' => 0.0,
                'min_payout' => 75.0,
                'stale_after_sec' => 60,
                'sma_length' => 10,
                'rsi_length' => 14,
                'rsi_up' => 60.0,
                'rsi_down' => 40.0,
                'telegram_enabled' => false,
                'telegram_notify_on' => 'signal_trade',
                'telegram_alert_format' => 'brief',
                'telegram_send_chart' => true,
                'telegram_send_chart_m5' => true,
                'version' => 'rsi_contrtrend_v1',
            ],
        ]
    );

    $this->info("Готово: #{$bot->id} {$bot->name} ({$bot->type})");
})->purpose('Создать бота RSI Contrtrend (OsEngine → CALL/PUT)');

Artisan::command('bots:bollinger-contrtrend-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    // OsEngine StrategyBollinger: BB Length 12, Deviation 2; BO expiry 3 мин на M1.
    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Bollinger Contrtrend', 'type' => 'bollinger_contrtrend'],
        [
            'is_active' => false,
            'config' => [
                'count' => 150,
                'expiry_sec' => 180,
                'min_confidence' => 0.0,
                'min_payout' => 75.0,
                'stale_after_sec' => 60,
                'bb_length' => 12,
                'bb_deviation' => 2.0,
                'telegram_enabled' => false,
                'telegram_notify_on' => 'signal_trade',
                'telegram_alert_format' => 'brief',
                'telegram_send_chart' => true,
                'telegram_send_chart_m5' => true,
                'version' => 'bollinger_contrtrend_v1',
            ],
        ]
    );

    $this->info("Готово: #{$bot->id} {$bot->name} ({$bot->type})");
})->purpose('Создать бота Bollinger Contrtrend (OsEngine StrategyBollinger → CALL/PUT)');

Artisan::command('bots:os-engine-binary-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    // Общие runtime-дефолты как у bollinger_contrtrend; стратегия-специфичные ключи — из DEFAULT_* констант.
    $shared = [
        'count' => 150,
        'expiry_sec' => 180,
        'min_confidence' => 0.0,
        'min_payout' => 75.0,
        'stale_after_sec' => 60,
        'telegram_enabled' => false,
        'telegram_notify_on' => 'signal_trade',
        'telegram_alert_format' => 'brief',
        'telegram_send_chart' => true,
        'telegram_send_chart_m5' => true,
    ];

    $bots = [
        [
            'name' => 'PinBar Trade',
            'type' => 'pinbar_trade',
            'config' => array_merge($shared, [
                'sma_length' => \App\Services\Bots\Strategies\PinBarTradeStrategy::DEFAULT_SMA_LENGTH,
                'version' => 'pinbar_trade_v1',
            ]),
        ],
        [
            'name' => 'Williams Range Trade',
            'type' => 'williams_range_trade',
            'config' => array_merge($shared, [
                'wr_length' => \App\Services\Bots\Strategies\WilliamsRangeTradeStrategy::DEFAULT_WR_LENGTH,
                'wr_up' => \App\Services\Bots\Strategies\WilliamsRangeTradeStrategy::DEFAULT_WR_UP,
                'wr_down' => \App\Services\Bots\Strategies\WilliamsRangeTradeStrategy::DEFAULT_WR_DOWN,
                'require_cross' => true,
                'min_confidence' => 0.0,
                'min_payout' => 82.0,
                'version' => 'williams_range_trade_v1',
            ]),
        ],
        [
            'name' => 'Three Soldier',
            'type' => 'three_soldier',
            'config' => array_merge($shared, [
                'height_soldiers' => \App\Services\Bots\Strategies\ThreeSoldierStrategy::DEFAULT_HEIGHT_SOLDIERS,
                'min_height_one_soldier' => \App\Services\Bots\Strategies\ThreeSoldierStrategy::DEFAULT_MIN_HEIGHT_ONE_SOLDIER,
                'version' => 'three_soldier_v1',
            ]),
        ],
        [
            'name' => 'CCI Trade',
            'type' => 'cci_trade',
            'config' => array_merge($shared, [
                'cci_length' => \App\Services\Bots\Strategies\CciTradeStrategy::DEFAULT_CCI_LENGTH,
                'cci_up' => \App\Services\Bots\Strategies\CciTradeStrategy::DEFAULT_CCI_UP,
                'cci_down' => \App\Services\Bots\Strategies\CciTradeStrategy::DEFAULT_CCI_DOWN,
                'version' => 'cci_trade_v1',
            ]),
        ],
        [
            'name' => 'RSI Trade',
            'type' => 'rsi_trade',
            'config' => array_merge($shared, [
                'rsi_length' => \App\Services\Bots\Strategies\RsiTradeStrategy::DEFAULT_RSI_LENGTH,
                'rsi_up' => \App\Services\Bots\Strategies\RsiTradeStrategy::DEFAULT_RSI_UP,
                'rsi_down' => \App\Services\Bots\Strategies\RsiTradeStrategy::DEFAULT_RSI_DOWN,
                'version' => 'rsi_trade_v1',
            ]),
        ],
        [
            'name' => 'RVI Trade',
            'type' => 'rvi_trade',
            'config' => array_merge($shared, [
                'rvi_length' => \App\Services\Bots\Strategies\RviTradeStrategy::DEFAULT_RVI_LENGTH,
                'min_signal_abs' => \App\Services\Bots\Strategies\RviTradeStrategy::DEFAULT_MIN_SIGNAL_ABS,
                'require_cross' => true,
                'min_confidence' => 0.0,
                'min_payout' => 82.0,
                'version' => 'rvi_trade_v1',
            ]),
        ],
    ];

    foreach ($bots as $spec) {
        $bot = Bot::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => $spec['name'], 'type' => $spec['type']],
            [
                'is_active' => false,
                'config' => $spec['config'],
            ]
        );
        $this->info("Готово: #{$bot->id} {$bot->name} ({$bot->type})");
    }
})->purpose('Создать 6 OsEngine binary ботов (PinBar/WR/Soldiers/CCI/RSI/RVI), inactive');

Artisan::command('bots:wave-a-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    foreach (\App\Support\OsEngineWaveABotConfig::TYPES as $type) {
        $meta = \App\Support\OsEngineWaveABotConfig::meta($type);
        $bot = Bot::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => $meta['title'], 'type' => $type],
            [
                'is_active' => false,
                'config' => \App\Support\OsEngineWaveABotConfig::defaultConfig($type),
            ]
        );
        $this->info("Готово: #{$bot->id} {$bot->name} ({$bot->type})");
    }
})->purpose('Создать Wave A OsEngine→CALL/PUT ботов (9 шт.), inactive');

Artisan::command('bots:wave-b-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    foreach (\App\Support\OsEngineWaveBBotConfig::TYPES as $type) {
        $meta = \App\Support\OsEngineWaveBBotConfig::meta($type);
        $bot = Bot::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => $meta['title'], 'type' => $type],
            [
                'is_active' => false,
                'config' => \App\Support\OsEngineWaveBBotConfig::defaultConfig($type),
            ]
        );
        $this->info("Готово: #{$bot->id} {$bot->name} ({$bot->type})");
    }
})->purpose('Создать Wave B OsEngine→CALL/PUT (impulse/vola-adaptive, 3 шт.), inactive');

Artisan::command('bots:max-bot-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'MaxBot', 'type' => \App\Support\MaxBotConfig::BOT_TYPE],
        [
            'is_active' => false,
            'config' => \App\Support\MaxBotConfig::defaultConfig(),
        ]
    );

    $this->info("Готово: #{$bot->id} {$bot->name} ({$bot->type})");
})->purpose('Создать бота MaxBot (ratio тел к историческому максимуму), inactive');

Artisan::command('bots:reversal-confirm-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $defaultConfig = [
        'count' => 500,
        'min_closed_bars' => 200,
        'expiry_sec' => 300,
        'min_confidence' => 0.0,
        'min_payout' => 82.0,
        'stale_after_sec' => 45,
        'min_level_strength' => 58,
        'min_sources' => 1,
        'cluster_atr_mult_m5' => 0.28,
        'touch_atr_mult_m1' => 0.55,
        'bounce_min_wick_ratio_m5' => 0.28,
        'h1_flat_strength_extra' => 4,
        'm5_rejection_enabled' => true,
        'm5_rejection_require_direction' => true,
        'm1_reversal_enabled' => true,
        'm1_reversal_confirm_bars' => 1,
        'm1_reversal_min_wick_ratio' => 0.22,
        'm1_reversal_min_body_ratio' => 0.25,
        'm1_reversal_require_wick_dominance' => true,
        'm1_reversal_wick_scope' => 'last',
        'require_h1_alignment' => false,
        'm5_take' => 120,
        'm15_take' => 80,
        'h1_take' => 12,
        'version' => 'candle_reversal_confirm_v3_level_bounce',
    ];

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Candle Reversal + Confirm', 'type' => 'candle_reversal_confirm'],
        [
            'is_active' => false,
            'config' => $defaultConfig,
        ]
    );

    // При повторном запуске обновляем config на v3, сохраняя Telegram и прочие пользовательские поля.
    if ($bot->wasRecentlyCreated === false) {
        $old = is_array($bot->config) ? $bot->config : [];
        $preserveKeys = [
            'telegram_enabled', 'telegram_bot_token', 'telegram_chat_id', 'telegram_notify_on',
            'telegram_alert_format', 'telegram_send_chart', 'telegram_send_chart_m5',
            'telegram_analyst_llm_enabled', 'telegram_analyst_bot_id', 'telegram_analyst_llm_question',
            'analysis_runs_logging_enabled', 'allowed_instruments', 'tv_crosshair_enabled',
        ];
        $preserved = [];
        foreach ($preserveKeys as $key) {
            if (array_key_exists($key, $old)) {
                $preserved[$key] = $old[$key];
            }
        }
        $bot->config = array_merge($defaultConfig, $preserved);
        $bot->save();
        $this->info('Конфиг обновлён до v3 (Telegram-настройки сохранены).');
    }

    $this->info("Бот отбития от уровня: #{$bot->id} {$bot->name} ({$bot->type}) — при необходимости включите в настройках.");
})->purpose('Создать бота «Candle Reversal + Confirm» (отскок от уровня)');

Artisan::command('bots:smc-flow-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'SMC Flow bot', 'type' => 'smc_flow'],
        [
            'is_active' => false,
            'config' => [
                'count' => 500,
                'expiry_sec' => 180,
                'min_confidence' => 0.0,
                'min_payout' => 80.0,
                'stale_after_sec' => 45,
                'smc_three_br_confirm_max_bars' => 30,
                'smc_three_br_allow_pattern' => true,
                'smc_require_ob' => true,
                'version' => 'smc_flow_ut_3br_ob_v1',
            ],
        ]
    );

    $this->info("SMC Flow бот: #{$bot->id} {$bot->name} ({$bot->type}) — настройте SMC Flow индикатор и включите бота.");
})->purpose('Создать бота SMC Flow (UT + 3BR + OB, 3 мин)');

Artisan::command('bots:sweep-raid-liquidity-bootstrap', function () {
    $user = \App\Models\User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    // Бот создаётся выключенным: сначала проверьте метки SWEEP/RAID/PB
    // на графике и настройки payout на paper-сделках.
    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Sweep / Raid Liquidity', 'type' => 'sweep_raid_liquidity'],
        [
            'is_active' => false,
            'config' => [
                'count' => 500,
                'expiry_sec' => 60,
                'min_confidence' => 0.0,
                'min_payout' => 80.0,
                'stale_after_sec' => 60,
                'srl_pivot_length' => 2,
                'srl_enter_sweep' => true,
                'srl_enter_pullback' => true,
                // Telegram выключен до явной настройки token и Chat ID.
                'telegram_enabled' => false,
                'telegram_notify_result' => true,
                'telegram_notify_on' => 'signal_trade',
                // PNG с метками SRL (FH/FL, SWEEP, RAID, PB) включается вместе с Telegram.
                'telegram_send_chart' => true,
                'telegram_chat_id' => '',
                // /start в отдельный Telegram-бот добавляет чат в получатели алертов.
                'telegram_auto_subscribe' => true,
                'telegram_analyst_llm_enabled' => true,
                'telegram_analyst_bot_id' => null,
                'telegram_analyst_llm_question' => \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION,
                'version' => 'sweep_raid_liquidity_v1',
            ],
        ],
    );

    $this->info("Sweep / Raid Liquidity: #{$bot->id} {$bot->name} ({$bot->type}) — сначала проверьте paper-режим.");
})->purpose('Создать выключенного M1-бота Sweep / Raid Liquidity (60 сек)');

Artisan::command('bots:strong-trend-telegram-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Strong Trend Telegram', 'type' => 'strong_trend_telegram'],
        [
            'is_active' => false,
            'config' => [
                'count' => 120,
                'streak_len' => 6,
                'expiry_sec' => 60,
                'min_confidence' => 0.0,
                'min_payout' => 80.0,
                'stale_after_sec' => 45,
                'dedupe_bucket_sec' => 300,
                'candle_strictness' => 'strict',
                'min_body_to_range_ratio' => 0.0,
                'execution_mode' => 'alert_only',
                'telegram_enabled' => true,
                'telegram_bot_token' => '8814450509:AAFiq9p6GV5zo1e3ciSVAi1gHnrjMe8pFCA',
                'telegram_chat_id' => '',
                'notify_only' => true,
                'version' => 'strong_trend_telegram_v1',
            ],
        ]
    );

    $this->info("Бот алертов: #{$bot->id} {$bot->name} ({$bot->type})");
    $this->comment('1) Откройте https://t.me/trend1123_bot и нажмите Start');
    $this->comment('2) В настройках бота нажмите «Получить chat_id из Telegram»');
    $this->comment('3) Включите бота и убедитесь, что работает schedule:run / bots:scan');
})->purpose('Создать бота «Сильный тренд M1 → Telegram»');

Artisan::command('bots:strong-trend-demarker-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    // Бот создаётся выключенным: сначала проверяются S30-свечи, виртуальные
    // сделки и соответствие фактической экспирации правилам стратегии.
    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Strong Trend + DeMarker', 'type' => 'strong_trend_demarker'],
        [
            'is_active' => false,
            'config' => [
                'count' => 120,
                'timeframe' => \App\Services\CandleService::TF_S30,
                'streak_len' => 6,
                'demarker_period' => 6,
                'demarker_oversold' => 10.0,
                'demarker_overbought' => 90.0,
                'losses_before_entry' => 2,
                'candle_strictness' => 'strict',
                'min_body_to_range_ratio' => 0.0,
                'expiry_sec' => 30,
                'min_confidence' => 0.0,
                'min_payout' => 80.0,
                'stale_after_sec' => 30,
                'dedupe_bucket_sec' => 30,
                'telegram_enabled' => false,
                'telegram_notify_result' => true,
                'telegram_notify_on' => 'signal_trade',
                'telegram_chat_id' => '',
                // /start в @strong_DeMarker_bot добавляет чат в получатели алертов.
                'telegram_auto_subscribe' => true,
                'telegram_analyst_llm_enabled' => true,
                'telegram_analyst_bot_id' => null,
                'telegram_analyst_llm_question' => \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION,
                'version' => 'strong_trend_demarker_v2',
            ],
        ],
    );

    $this->info("Strong Trend + DeMarker: #{$bot->id} {$bot->name} ({$bot->type}) — выключен для первичной проверки.");
})->purpose('Создать выключенного S30-бота Strong Trend + DeMarker');

Artisan::command('bots:demarker-dual-fast-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $defaults = \App\Support\DemarkerDualFastConfig::defaultConfig();
    $defaults['telegram_analyst_llm_question'] = \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION;

    $bot = Bot::query()->firstOrCreate(
        [
            'user_id' => $user->id,
            'name' => 'Dual DeMarker Fast',
            'type' => \App\Support\DemarkerDualFastConfig::BOT_TYPE,
        ],
        [
            'is_active' => false,
            'config' => $defaults,
        ],
    );

    $this->info("Dual DeMarker Fast: #{$bot->id} {$bot->name} ({$bot->type}) — выключен для первичной проверки.");
})->purpose('Создать выключенного S30-бота Dual DeMarker Fast (стратегия №3)');

Artisan::command('bots:strong-level-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Strong Level', 'type' => 'strong_level'],
        [
            'is_active' => false,
            'config' => [
                'count' => 500,
                'min_closed_bars' => 200,
                'min_confidence' => 0.0,
                'min_payout' => 82.0,
                'stale_after_sec' => 45,
                'entry_mode' => 'both',
                'min_level_strength' => 58.0,
                'min_sources' => 1,
                'cluster_atr_mult_m5' => 0.28,
                'touch_atr_mult_m1' => 0.55,
                'bounce_min_wick_ratio_m5' => 0.28,
                'bounce_require_m1_confirm' => false,
                'h1_flat_strength_extra' => 4.0,
                'expiry_sec_bounce' => 180,
                'expiry_sec_breakout' => 300,
                'm5_take' => 120,
                'm15_take' => 80,
                'h1_take' => 12,
                'require_h1_alignment' => false,
                'breakout_require_retest' => false,
                'breakout_vol_above_sma' => false,
                'telegram_enabled' => false,
                'telegram_notify_on' => 'signal_trade',
                'telegram_alert_format' => 'brief',
                'telegram_send_chart' => true,
                'telegram_send_chart_m5' => true,
                'version' => 'strong_level_v1',
            ],
        ]
    );

    $this->info("Strong Level бот: #{$bot->id} {$bot->name} ({$bot->type})");
    $this->comment('Настройте Price Action DGT и SMC Flow индикаторы, затем включите бота.');
})->purpose('Создать бота Strong Level (отскок/пробой у сильных уровней)');

Artisan::command('bots:openrouter-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    // Создаём отдельный LLM-бот, который принимает решения только через OpenRouter API.
    // По умолчанию бот выключен: сначала задайте OPENROUTER_API_KEY и проверьте настройки.
    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'OpenRouter LLM Bot', 'type' => 'openrouter_llm'],
        [
            'is_active' => false,
            'config' => [
                'count' => 120,
                'expiry_sec' => 300,
                'min_confidence' => 0.0,
                'min_payout' => 70.0,
                'stale_after_sec' => 45,
                'model' => 'openai/gpt-5.2',
                'temperature' => 0.0,
                'max_tokens' => 350,
                'openrouter_timeout_sec' => 120,
                'system_prompt' => OpenRouterLlmConfig::defaultTradingSystemPrompt(),
                'version' => 'openrouter_llm_v1',
            ],
        ]
    );

    $this->info("OpenRouter бот: #{$bot->id} {$bot->name} ({$bot->type})");
    $this->line('Проверьте .env: OPENROUTER_API_KEY, затем включите бота в настройках.');
})->purpose('Создать отдельного бота OpenRouter LLM');

Artisan::command('bots:openrouter-analyst-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $defaultPrompt = OpenRouterChartLevelsParser::defaultAnalystSystemPromptClassic();

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'OpenRouter Chart Analyst', 'type' => 'openrouter_llm_analyst'],
        [
            'is_active' => false,
            'config' => [
                'count' => 120,
                's5_take' => 240,
                'model' => 'openai/gpt-5.2',
                'temperature' => 0.2,
                'max_tokens' => 900,
                'max_tokens_short' => OpenRouterLlmConfig::ANALYST_SHORT_MAX_TOKENS_DEFAULT,
                'openrouter_timeout_sec' => 180,
                'mtf_enabled' => true,
                'm5_take' => 96,
                'm15_take' => 64,
                'h1_take' => 24,
                'analyst_smc_flow_enabled' => false,
                'analyst_prompt_mode' => OpenRouterLlmConfig::ANALYST_PROMPT_MODE_FULL,
                'system_prompt' => $defaultPrompt,
                'system_prompt_short' => OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortClassic(),
                'version' => 'openrouter_llm_analyst_v1',
            ],
        ]
    );

    // У уже существующего бота обновляем system_prompt до актуальной версии с chart_levels.
    $config = is_array($bot->config) ? $bot->config : [];
    $config['system_prompt'] = $defaultPrompt;
    $config['system_prompt_short'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($config);
    $config['max_tokens_short'] = (int) ($config['max_tokens_short'] ?? OpenRouterLlmConfig::ANALYST_SHORT_MAX_TOKENS_DEFAULT);
    $bot->config = $config;
    $bot->save();

    $this->info("Бот-аналитик: #{$bot->id} {$bot->name} ({$bot->type})");
    $this->line('System prompt обновлён (chart_levels). Проверьте OPENROUTER_API_KEY.');
})->purpose('Создать бота OpenRouter Chart Analyst (ручной анализ)');

Artisan::command('bots:openrouter-analyst-sync-prompt', function () {
    $bots = Bot::query()->whereIn('type', ChartAnalystBotTypes::all())->get();

    if ($bots->isEmpty()) {
        $this->warn('Нет ботов Chart Analyst. Запустите: php artisan bots:openrouter-analyst-bootstrap');

        return 1;
    }

    foreach ($bots as $bot) {
        $config = is_array($bot->config) ? $bot->config : [];
        $config['_bot_type'] = $bot->type;
        if (ChartAnalystBotTypes::isSrl($bot->type)) {
            $config['analyst_srl_flow_enabled'] = true;
            $config['analyst_smc_flow_enabled'] = false;
        }
        $config['system_prompt'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptForConfig($config);
        $config['system_prompt_short'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($config);
        $config['max_tokens_short'] = (int) ($config['max_tokens_short'] ?? OpenRouterLlmConfig::ANALYST_SHORT_MAX_TOKENS_DEFAULT);
        unset($config['_bot_type']);
        $bot->config = $config;
        $bot->save();
        $pack = ChartAnalystBotTypes::isSrl($bot->type)
            ? 'SRL'
            : (\App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled($config) ? 'SMC' : 'classic');
        $this->line("Обновлён #{$bot->id} {$bot->name} ({$pack})");
    }

    $this->info('Готово: system_prompt и system_prompt_short записаны в '.count($bots).' бот(ов).');

    return 0;
})->purpose('Обновить system prompt у всех ботов OpenRouter Chart Analyst');

Artisan::command('bots:openrouter-analyst-split-smc', function () {
    $smcName = 'OpenRouter Chart Analyst SMC Flow';
    $classicName = 'OpenRouter Chart Analyst';

    $source = Bot::query()
        ->where('type', 'openrouter_llm_analyst')
        ->orderBy('id')
        ->first();

    if ($source === null) {
        $this->error('Нет ботов openrouter_llm_analyst. Запустите: php artisan bots:openrouter-analyst-bootstrap');

        return 1;
    }

    $baseConfig = is_array($source->config) ? $source->config : [];

    // Текущий бот (часто #13) — версия с SMC Flow.
    $smcConfig = $baseConfig;
    $smcConfig['analyst_smc_flow_enabled'] = true;
    $smcConfig['system_prompt'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptWithSmcFlow();
    $smcConfig['system_prompt_short'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortWithSmcFlow();
    $source->name = $smcName;
    $source->config = $smcConfig;
    $source->save();
    $this->info("SMC: #{$source->id} → {$smcName}");

    $classic = Bot::query()
        ->where('type', 'openrouter_llm_analyst')
        ->where('name', $classicName)
        ->where('id', '!=', $source->id)
        ->first();

    if ($classic === null) {
        $classicConfig = $baseConfig;
        $classicConfig['analyst_smc_flow_enabled'] = false;
        $classicConfig['system_prompt'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptClassic();
        $classicConfig['system_prompt_short'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortClassic();

        $classic = Bot::query()->create([
            'user_id' => $source->user_id,
            'name' => $classicName,
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => $classicConfig,
        ]);
        $this->info("Создан classic: #{$classic->id} {$classicName}");
    } else {
        $classicConfig = is_array($classic->config) ? $classic->config : $baseConfig;
        $classicConfig['analyst_smc_flow_enabled'] = false;
        $classicConfig['system_prompt'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptClassic();
        $classicConfig['system_prompt_short'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortClassic();
        $classic->config = $classicConfig;
        $classic->save();
        $this->info("Обновлён classic: #{$classic->id} {$classicName}");
    }

    $this->line('Готово. Classic — без SMC в LLM; SMC Flow — со снимком индикатора.');

    return 0;
})->purpose('Переименовать аналитика в SMC Flow и создать классическую копию без SMC');

Artisan::command('bots:openrouter-analyst-srl-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return 1;
    }

    $name = ChartAnalystBotTypes::label(ChartAnalystBotTypes::SRL);
    $config = [
        'count' => 120,
        's5_take' => 240,
        'model' => 'openai/gpt-5.2',
        'temperature' => 0.2,
        'max_tokens' => 900,
        'max_tokens_short' => OpenRouterLlmConfig::ANALYST_SHORT_MAX_TOKENS_DEFAULT,
        'openrouter_timeout_sec' => 180,
        'mtf_enabled' => true,
        'm5_take' => 96,
        'm15_take' => 64,
        'h1_take' => 24,
        'analyst_smc_flow_enabled' => false,
        'analyst_srl_flow_enabled' => true,
        'srl_pivot_length' => 2,
        'srl_enter_sweep' => true,
        'srl_enter_pullback' => true,
        'analyst_prompt_mode' => OpenRouterLlmConfig::ANALYST_PROMPT_MODE_FULL,
        'system_prompt' => OpenRouterChartLevelsParser::defaultAnalystSystemPromptWithSweepRaid(),
        'system_prompt_short' => OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortWithSweepRaid(),
        'version' => 'openrouter_llm_analyst_srl_v1',
    ];

    $bot = Bot::query()->firstOrCreate(
        [
            'user_id' => $user->id,
            'name' => $name,
            'type' => ChartAnalystBotTypes::SRL,
        ],
        [
            'is_active' => false,
            'config' => $config,
        ]
    );

    $existing = is_array($bot->config) ? $bot->config : [];
    $bot->config = array_merge($existing, $config);
    $bot->save();

    $this->info("SRL Chart Analyst: #{$bot->id} {$bot->name} ({$bot->type})");
    $this->line('System prompt Sweep/Raid записан. Проверьте OPENROUTER_API_KEY и Telegram token.');

    return 0;
})->purpose('Создать бота OpenRouter Chart Analyst Sweep/Raid (LLM подтверждает SWEEP/RAID/PB)');

Artisan::command('bots:openrouter-list-models', function (OpenRouterClient $client) {
    // Проверка доступности каталога OpenRouter (тот же запрос, что на странице настроек LLM-бота).
    try {
        $models = $client->listModels();
        if ($models === []) {
            $this->error('OpenRouter вернул пустой каталог моделей.');

            return 1;
        }
        $sample = OpenRouterClient::modelSelectLabel($models[0]);
        $this->info('Загружено моделей: '.count($models));
        $this->line('Пример: '.$sample);

        return 0;
    } catch (\Throwable $e) {
        $this->error('Не удалось загрузить модели: '.$e->getMessage());

        return 1;
    }
})->purpose('Проверить загрузку каталога моделей OpenRouter');

Artisan::command('bots:scan-dispatch', function () {
    $bots = BotScanCoordinator::scannableBots();
    if ($bots->isEmpty()) {
        $this->comment('Нет активных ботов для scan.');

        return;
    }

    $started = 0;
    $skippedRunning = 0;
    $skippedInterval = 0;
    foreach ($bots as $bot) {
        if (BotScanCoordinator::isScanRunning($bot->id)) {
            $skippedRunning++;
            $this->line(sprintf('[bot:%d] scan уже выполняется — пропуск.', $bot->id));

            continue;
        }

        if (! BotScanCoordinator::isDueToSpawn($bot)) {
            $skippedInterval++;
            $interval = BotScanCoordinator::scanIntervalSecForBot($bot);
            $nextAt = BotScanCoordinator::nextDueUnix($bot);
            $this->line(sprintf(
                '[bot:%d] рано (интервал %dс, следующий старт через %dс) — пропуск.',
                $bot->id,
                $interval,
                max(0, $nextAt - time())
            ));

            continue;
        }

        if (BotScanCoordinator::spawnScan($bot->id)) {
            $started++;
            $this->line(sprintf('[bot:%d] запущен bots:scan в фоне → %s', $bot->id, BotScanCoordinator::logPathForBot($bot->id)));
        } else {
            $this->warn(sprintf('[bot:%d] не удалось запустить scan.', $bot->id));
        }
    }

    $this->info(sprintf(
        'Диспетчер: запущено %d, пропущено (уже идёт) %d, пропущено (интервал) %d, ботов %d.',
        $started,
        $skippedRunning,
        $skippedInterval,
        $bots->count()
    ));
})->purpose('Запустить параллельный bots:scan для каждого активного бота (cron каждую минуту)');

Artisan::command('bots:scan {--bot_id=} {--active-only=1}', function (BotEngineService $engine) {
    $botIdOpt = $this->option('bot_id');
    $singleBotId = $botIdOpt !== null && $botIdOpt !== '' ? (int) $botIdOpt : null;

    // Per-bot: блокируем только тот же bot_id; полный проход — отдельный lock «all».
    $lockName = $singleBotId !== null
        ? BotScanCoordinator::botLockName($singleBotId)
        : BotScanCoordinator::ALL_BOTS_LOCK;
    $processBotFilter = $singleBotId;

    if (bots_scan_process_running(getmypid(), $processBotFilter)) {
        $this->warn('bots:scan уже выполняется — пропуск.');

        return;
    }

    $lockResult = BotScanCoordinator::acquireLock($lockName, getmypid());
    if (! $lockResult['acquired'] || ! $lockResult['lock'] instanceof \Illuminate\Contracts\Cache\Lock) {
        $this->warn('bots:scan уже выполняется — пропуск (lock).');

        return;
    }
    $lock = $lockResult['lock'];

    try {
        $activeOnly = (string) $this->option('active-only') !== '0';

        $q = Bot::query()->orderBy('id');
        if ($singleBotId !== null) {
            $q->where('id', $singleBotId);
        }
        if ($activeOnly) {
            $q->where('is_active', true);
        }
        $bots = $q->get();
        if ($bots->isEmpty()) {
            $this->warn('Нет ботов для сканирования.');

            return;
        }

        $summary = ['processed' => 0, 'signals' => 0, 'trades' => 0];
        foreach ($bots as $bot) {
            if (! $bot instanceof Bot) {
                continue;
            }
            if ($bot->type === 'openrouter_llm_analyst') {
                continue;
            }
            $r = $engine->scanBot($bot);
            $summary['processed'] += (int) $r['processed'];
            $summary['signals'] += (int) $r['signals'];
            $summary['trades'] += (int) $r['trades'];
            $this->line(sprintf(
                '[bot:%d] processed=%d signals=%d trades=%d',
                $bot->id,
                $r['processed'],
                $r['signals'],
                $r['trades']
            ));
        }

        $this->info(sprintf(
            'Итог: processed=%d signals=%d trades=%d',
            $summary['processed'],
            $summary['signals'],
            $summary['trades']
        ));
    } finally {
        BotScanCoordinator::releaseLock($lock);
    }
})->purpose('Сканировать пары ботом (один --bot_id или все активные в одном процессе)');

Artisan::command('bots:scan-dedupe {--no-restart : Не запускать dispatch после остановки}', function () {
    $running = BotScanCoordinator::listRunningScans();
    $byBot = [];
    foreach ($running as $row) {
        $key = $row['bot_id'] ?? 'all';
        $byBot[$key][] = (int) $row['pid'];
    }

    $duplicatePids = [];
    foreach ($byBot as $pids) {
        if (count($pids) > 1) {
            array_push($duplicatePids, ...$pids);
        }
    }

    if ($duplicatePids === []) {
        $this->info($running === []
            ? 'Процесс bots:scan не запущен.'
            : 'Дубликатов per-bot scan нет (параллельные боты — норма). '.count($running).' процесс(ов).');

        return;
    }

    $killed = \App\Support\ArtisanProcessFinder::terminatePids($duplicatePids);
    usleep(300_000);
    BotScanCoordinator::clearAllScanLocks();

    $this->warn(sprintf('Дубликаты scan: остановлено %d PID (%s). Locks сброшены.', $killed, implode(', ', $duplicatePids)));

    if ($this->option('no-restart')) {
        $this->comment('Новый dispatch не запущен (--no-restart).');

        return;
    }

    Artisan::call('bots:scan-dispatch');
    $this->info(trim(Artisan::output()));
})->purpose('Остановить дубликаты bots:scan на одном bot_id, сбросить locks и запустить dispatch');

Artisan::command('bots:scan-watchdog {--max-elapsed= : Секунды wall-time до kill (default из app_settings / SCAN_MAX_ELAPSED_SEC)} {--no-restart : Не поднимать scan после kill}', function () {
    // Зависший bots:scan остаётся «живым» PID → dispatch вечно skip'ает бота.
    // Watchdog убивает процессы дольше max-elapsed и (по умолчанию) поднимает заново.
    $maxOpt = $this->option('max-elapsed');
    $maxElapsed = $maxOpt !== null && $maxOpt !== ''
        ? max(60, (int) $maxOpt)
        : BotScanCoordinator::getScanMaxElapsedSec();

    $reaped = BotScanCoordinator::reapStaleScans($maxElapsed);
    if ($reaped === []) {
        $this->info(sprintf('Watchdog: зависших scan нет (порог %d с).', $maxElapsed));

        return;
    }

    foreach ($reaped as $row) {
        $this->warn(sprintf(
            'Watchdog: kill pid=%d bot=%s elapsed=%ds reason=%s',
            $row['pid'],
            $row['bot_id'] !== null ? (string) $row['bot_id'] : 'all',
            $row['elapsed_sec'],
            $row['reason']
        ));
    }

    if ($this->option('no-restart')) {
        $this->comment('Новый dispatch не запущен (--no-restart).');

        return;
    }

    usleep(300_000);
    Artisan::call('bots:scan-dispatch');
    $this->info(trim(Artisan::output()));
})->purpose('Убить зависшие bots:scan (wall-time) и перезапустить dispatch');

Artisan::command('bots:settle {--bot_id=} {--limit=1000}', function (
    BotSettlementService $settlement,
    BotVirtualTradeSettlementService $virtualSettlement,
) {
    $botId = $this->option('bot_id') ? (int) $this->option('bot_id') : null;
    $limit = max(1, (int) $this->option('limit'));
    $res = $settlement->settleDueTrades($botId, $limit);
    $virtual = $virtualSettlement->settleDueVirtualTrades($botId, $limit);
    $this->info(sprintf(
        'paper: checked=%d settled=%d skipped=%d fallback=%d; virtual: checked=%d settled=%d skipped=%d',
        $res['checked'],
        $res['settled'],
        $res['skipped'],
        $res['settled_fallback'] ?? 0,
        $virtual['checked'],
        $virtual['settled'],
        $virtual['skipped'],
    ));
})->purpose('Закрыть просроченные paper- и виртуальные сделки ботов');

Artisan::command('telegram:ensure-analyst-services', function (
    TelegramAnalystProcessManager $processes,
    \App\Services\Telegram\StrongLevelTelegramProcessManager $strongLevel,
) {
    // UI (www-data) часто не может убить CLI-poll — cron под user o добивает restart.
    $strongDeferred = $strongLevel->processDeferredRestartIfRequested();
    if ($strongDeferred !== null) {
        $this->info('strong-level deferred restart processed');
    }

    $result = $processes->ensureConfiguredServices();
    $pollCount = count(array_filter($result['bots'], static fn (array $row): bool => (bool) ($row['poll_running'] ?? false)));
    $this->info(sprintf(
        'telegram-analyst: queue=%s, poll bots=%d/%d',
        ($result['queue_running'] ?? false) ? 'ok' : 'fail',
        $pollCount,
        count($result['bots'] ?? []),
    ));

    return (($result['queue_running'] ?? false) && $pollCount === count($result['bots'] ?? [])) ? 0 : 1;
})->purpose('Watchdog: poll + queue для Chart Analyst SMC Flow (/menu)');

Artisan::command('telegram:analyst-poll {--bot= : ID бота Chart Analyst} {--timeout=30}', function (
    TelegramAlertService $telegram,
    TelegramAnalystHandler $handler,
) {
    $botId = (int) ($this->option('bot') ?: 0);
    if ($botId <= 0) {
        $candidates = Bot::query()->whereIn('type', ChartAnalystBotTypes::all())->orderBy('id')->get();
        $bot = $candidates->first(function (Bot $b): bool {
            return ChartAnalystBotTypes::isSrl($b->type)
                || \App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled(is_array($b->config) ? $b->config : []);
        }) ?? $candidates->first();
        if ($bot === null) {
            $this->error('Нет бота Chart Analyst. Укажите --bot=ID');

            return 1;
        }
    } else {
        $bot = Bot::query()->find($botId);
        if ($bot === null || ! ChartAnalystBotTypes::isAnalyst($bot->type)) {
            $this->error('Бот не найден или не Chart Analyst');

            return 1;
        }
    }

    /** @var array<string, mixed> $cfg */
    $cfg = is_array($bot->config) ? $bot->config : [];
    $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
    if ($token === '') {
        $this->error('В config бота не задан telegram_bot_token');

        return 1;
    }
    if (! (bool) ($cfg['telegram_enabled'] ?? false)) {
        $this->warn('telegram_enabled=false — polling всё равно стартует, включите флаг в настройках.');
    }

    $delete = $telegram->deleteWebhook($token);
    if (! ($delete['ok'] ?? false)) {
        $this->warn('deleteWebhook: '.($delete['error'] ?? 'unknown'));
    }

    $timeout = max(5, min(50, (int) $this->option('timeout')));
    $offsetKey = 'telegram_analyst_poll_offset:'.$bot->id;
    $offset = (int) Cache::get($offsetKey, 0);

    $this->info("Telegram analyst poll: bot #{$bot->id} {$bot->name}, offset={$offset}, timeout={$timeout}s");
    $this->line('Ctrl+C для остановки. Нужен queue worker: php artisan queue:work --queue=telegram-analyst --timeout=600');

    while (true) {
        // Long-poll живёт часами — перечитываем config/token из БД на каждой итерации getUpdates.
        $bot->refresh();
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        if ($token === '') {
            $this->error('telegram_bot_token пуст — проверьте настройки бота');
            sleep(5);

            continue;
        }

        $result = $telegram->getUpdates($token, $offset, $timeout);
        if (! ($result['ok'] ?? false)) {
            $this->error('getUpdates: '.($result['error'] ?? 'unknown'));
            sleep(3);

            continue;
        }
        $updates = $result['updates'] ?? [];
        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }
            if (isset($update['update_id'])) {
                $offset = max($offset, (int) $update['update_id'] + 1);
            }
            try {
                $handler->handleUpdate($bot, $update);
            } catch (\Throwable $e) {
                $this->error('handleUpdate: '.$e->getMessage());
            }
        }
        if ($offset > 0) {
            Cache::forever($offsetKey, $offset);
        }
    }
})->purpose('Long polling Telegram для Chart Analyst (меню инструментов → анализ)');

Artisan::command('telegram:strong-level-poll {--bot= : ID бота с Telegram (LLM-кнопка / автоподписка)} {--timeout=30}', function (
    TelegramAlertService $telegram,
    \App\Services\Telegram\StrongLevelTelegramHandler $handler,
) {
    $botId = (int) ($this->option('bot') ?: 0);
    if ($botId <= 0) {
        $supportedTypes = array_values(array_unique(array_merge(
            \App\Services\Telegram\StrongLevelTelegramLlmButton::SUPPORTED_BOT_TYPES,
            ['strong_trend_telegram', 'strong_trend_demarker', \App\Support\DemarkerDualFastConfig::BOT_TYPE, 'sweep_raid_liquidity'],
        )));
        $bot = Bot::query()->whereIn('type', $supportedTypes)->where('is_active', true)->orderBy('id')->first();
        if ($bot === null) {
            $bot = Bot::query()->whereIn('type', $supportedTypes)->orderBy('id')->first();
        }
        if ($bot === null) {
            $this->error('Нет бота для Telegram poll. Укажите --bot=ID');

            return 1;
        }
    } else {
        $bot = Bot::query()->find($botId);
        if ($bot === null) {
            $this->error('Бот не найден');

            return 1;
        }
        // Poll принимает любой бот с token: /start подписка + LLM callback при поддержке типа.
        /** @var array<string, mixed> $cfgCheck */
        $cfgCheck = is_array($bot->config) ? $bot->config : [];
        if (trim((string) ($cfgCheck['telegram_bot_token'] ?? '')) === '') {
            $this->error('В config бота не задан telegram_bot_token');

            return 1;
        }
    }

    /** @var array<string, mixed> $cfg */
    $cfg = is_array($bot->config) ? $bot->config : [];
    $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
    if ($token === '') {
        $this->error('В config бота не задан telegram_bot_token');

        return 1;
    }

    $delete = $telegram->deleteWebhook($token);
    if (! ($delete['ok'] ?? false)) {
        $this->warn('deleteWebhook: '.($delete['error'] ?? 'unknown'));
    }

    $timeout = max(5, min(50, (int) $this->option('timeout')));
    $offsetKey = 'telegram_strong_level_poll_offset:'.$bot->id;
    $offset = (int) Cache::get($offsetKey, 0);

    $this->info("Telegram LLM-button poll: bot #{$bot->id} {$bot->name} ({$bot->type}), offset={$offset}, timeout={$timeout}s");
    $this->line('Ctrl+C для остановки. Нужны: queue:work --queue=telegram-analyst --timeout=600');

    while (true) {
        // Long-poll живёт часами — перечитываем config/token из БД на каждой итерации getUpdates.
        $bot->refresh();
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        if ($token === '') {
            $this->error('telegram_bot_token пуст — проверьте настройки бота');
            sleep(5);

            continue;
        }

        $result = $telegram->getUpdates($token, $offset, $timeout);
        if (! ($result['ok'] ?? false)) {
            $this->error('getUpdates: '.($result['error'] ?? 'unknown'));
            sleep(3);

            continue;
        }
        $updates = $result['updates'] ?? [];
        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }
            if (isset($update['update_id'])) {
                $offset = max($offset, (int) $update['update_id'] + 1);
            }
            try {
                $handler->handleUpdate($bot, $update);
            } catch (\Throwable $e) {
                $this->error('handleUpdate: '.$e->getMessage());
            }
        }
        if ($offset > 0) {
            Cache::forever($offsetKey, $offset);
        }
    }
})->purpose('Long polling Telegram для Strong Level / Strong Trend (кнопка LLM-анализа)');
