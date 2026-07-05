<?php

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Bots\BotEngineService;
use App\Services\Bots\BotSettlementService;
use App\Services\Bots\OpenRouterClient;
use App\Services\DataCleanupService;
use App\Services\ProxyDeadProbeService;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramAnalystHandler;
use App\Services\Telegram\TelegramAnalystProcessManager;
use App\Support\OpenRouterChartLevelsParser;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Реальный процесс scan: читаем cmdline PHP, а не pgrep по всей строке bash/cursor.
 * Иначе «зависший» lock после kill не снимается — pgrep находит оболочки с текстом bots:scan.
 */
if (! function_exists('bots_scan_process_running')) {
    function bots_scan_process_running(?int $exceptPid = null): bool
    {
        // Тот же фильтр, что в ArtisanProcessFinder: без bash/cursor-оболочек с текстом «bots:scan» в cmdline.
        $pids = \App\Support\ArtisanProcessFinder::findArtisanPids(
            static fn (string $cmd): bool => (bool) preg_match('#(?:^|/)(php\d*(?:\.\d+)?)\s+artisan\s+bots:scan\b#', $cmd),
            $exceptPid
        );

        return $pids !== [];
    }
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('candles:prune-old', function (DataCleanupService $dataCleanup) {
    $result = $dataCleanup->purgeCandlesOlderThan(DataCleanupService::DEFAULT_RETENTION_HOURS);
    $this->info("Удалено устаревших свечей: {$result['candles']}.");
})->purpose('Удалить свечи старше 24 часов');

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
        $probe->releaseStaleLock();

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
            $probe->forceReleaseLock();
            $this->error('Probe error: '.$e->getMessage());
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
    $this->comment('Вручную: php artisan bots:scan && php artisan bots:settle');
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
                'min_confidence' => 0.55,
                'min_payout' => 80.0,
                'stale_after_sec' => 45,
                'version' => 'v7_three_same_opposite',
            ],
        ]
    );

    $this->info("Бот готов: #{$bot->id} {$bot->name} ({$bot->type})");
})->purpose('Создать базового бота "3 свечи в ряд"');

Artisan::command('bots:akim-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    // Бот 1: версия "из первого видео" — M1, тренд + откат.
    $bot1 = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'AKIM TRADE 1M', 'type' => 'akim_trade_1m'],
        [
            'is_active' => false,
            'config' => [
                'count' => 180,
                'expiry_sec' => 60,
                'min_confidence' => 0.58,
                'min_payout' => 80.0,
                'stale_after_sec' => 45,
                'version' => 'akim_trade_1m_v1',
            ],
        ]
    );

    $this->info("Готово: #{$bot1->id} {$bot1->name} ({$bot1->type})");
})->purpose('Создать бота AKIM TRADE 1M');

Artisan::command('bots:reversal-confirm-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Candle Reversal + Confirm', 'type' => 'candle_reversal_confirm'],
        [
            'is_active' => false,
            'config' => [
                'count' => 180,
                'expiry_sec' => 300,
                'min_confidence' => 0.56,
                'min_payout' => 80.0,
                'stale_after_sec' => 45,
                'm5_reversal_min_wick_ratio' => 0.30,
                'm5_reversal_max_body_ratio' => 0.70,
                'm5_require_break_rev_extreme' => true,
                'm1_min_wick_ratio' => 0.22,
                'm1_min_body_ratio' => 0.25,
                'm1_require_wick_dominance' => true,
                'm1_wick_requirement_scope' => 'last',
                'version' => 'candle_reversal_confirm_v2_mtf',
            ],
        ]
    );

    $this->info("Бот разворота: #{$bot->id} {$bot->name} ({$bot->type}) — при необходимости включите в настройках.");
})->purpose('Создать бота «M5 разворот + 3 свечи M1»');

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
                'min_confidence' => 0.58,
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
                'count' => 30,
                'streak_len' => 6,
                'min_confidence' => 0.55,
                'min_payout' => 80.0,
                'stale_after_sec' => 45,
                'dedupe_bucket_sec' => 300,
                'candle_strictness' => 'strict',
                'min_body_to_range_ratio' => 0.0,
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
                'min_confidence' => 0.62,
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
                'h1_take' => 48,
                'require_h1_alignment' => false,
                'breakout_require_retest' => false,
                'breakout_vol_above_sma' => false,
                'telegram_enabled' => false,
                'telegram_notify_on' => 'signal_trade',
                'telegram_alert_format' => 'brief',
                'telegram_send_chart' => true,
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
                'min_confidence' => 0.65,
                'min_payout' => 70.0,
                'stale_after_sec' => 45,
                'model' => 'openai/gpt-5.2',
                'temperature' => 0.0,
                'max_tokens' => 350,
                'openrouter_timeout_sec' => 120,
                'system_prompt' => "Ты торговый AI-ассистент для бинарных опционов.\nРаботаешь по MTF-логике: H1 -> M15 -> M5 -> M1.\n\nВерни только JSON без markdown.\nФормат:\n{\"side\":\"CALL|PUT|NONE\",\"confidence\":0.0,\"analysis_text\":\"...\",\"reasons_short\":[\"...\"],\"features\":{}}\n\nДанные в запросе:\n- closed_candles: M1\n- mtf.h1_closed: H1\n- mtf.m15_closed: M15\n- mtf.m5_closed: M5\n\nОбязательный алгоритм:\n1) H1: определи bias = up | down | flat.\n2) M15: определи структуру у уровня: support/resistance, retest/breakout/false_breakout/range.\n3) M5: ищи подтверждение: rejection candle, breakout confirmation, возврат под/над уровень после ложного пробоя.\n4) M1: только точка входа (тайминг), не источник основного bias.\n\nПравила входа:\n- CALL только если H1 bias up, M15 поддерживает long-сценарий и M5 дал подтверждение.\n- PUT только если H1 bias down, M15 поддерживает short-сценарий и M5 дал подтверждение.\n- Иначе side = \"NONE\".\n\nФильтры качества:\n- Если H1 = flat и M15 = range без четкого края уровня -> NONE.\n- Если M1 сигнал против H1+M15 -> NONE.\n- Если подтверждений меньше 2 (на M15/M5) -> NONE.\n- Если не уверен -> NONE и confidence <= 0.5.\n\nConfidence:\n- NONE: 0.0..0.5\n- CALL/PUT: 0.55..1.0 только при подтвержденном MTF-согласовании.\n\nСтрогий формат explanation:\n- analysis_text обязательно в виде:\n  \"H1: ...; M15: ...; M5: ...; M1: ...; setup: ...\"\n- reasons_short: 3-5 коротких пунктов, желательно в порядке H1 -> M15 -> M5 -> M1.\n\nfeatures (обязательно заполняй):\n{\n  \"h1_bias\":\"up|down|flat\",\n  \"m15_structure\":\"support|resistance|range|breakout|retest|false_breakout\",\n  \"m5_trigger\":\"rejection|breakout_confirm|none\",\n  \"entry_tf\":\"M1\",\n  \"setup_type\":\"rejection|breakout_retest|false_breakout|none\",\n  \"mtf_alignment_score\": 0.0,\n  \"level_price\": 0.0\n}",
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
                'openrouter_timeout_sec' => 180,
                'mtf_enabled' => true,
                'm5_take' => 96,
                'm15_take' => 64,
                'h1_take' => 24,
                'analyst_smc_flow_enabled' => false,
                'system_prompt' => $defaultPrompt,
                'version' => 'openrouter_llm_analyst_v1',
            ],
        ]
    );

    // У уже существующего бота обновляем system_prompt до актуальной версии с chart_levels.
    $config = is_array($bot->config) ? $bot->config : [];
    $config['system_prompt'] = $defaultPrompt;
    $bot->config = $config;
    $bot->save();

    $this->info("Бот-аналитик: #{$bot->id} {$bot->name} ({$bot->type})");
    $this->line('System prompt обновлён (chart_levels). Проверьте OPENROUTER_API_KEY.');
})->purpose('Создать бота OpenRouter Chart Analyst (ручной анализ)');

Artisan::command('bots:openrouter-analyst-sync-prompt', function () {
    $bots = Bot::query()->where('type', 'openrouter_llm_analyst')->get();

    if ($bots->isEmpty()) {
        $this->warn('Нет ботов openrouter_llm_analyst. Запустите: php artisan bots:openrouter-analyst-bootstrap');

        return 1;
    }

    foreach ($bots as $bot) {
        $config = is_array($bot->config) ? $bot->config : [];
        $config['system_prompt'] = OpenRouterChartLevelsParser::defaultAnalystSystemPromptForConfig($config);
        $bot->config = $config;
        $bot->save();
        $smc = \App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled($config) ? 'SMC' : 'classic';
        $this->line("Обновлён #{$bot->id} {$bot->name} ({$smc})");
    }

    $this->info('Готово: system_prompt записан в '.count($bots).' бот(ов).');

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
        $classic->config = $classicConfig;
        $classic->save();
        $this->info("Обновлён classic: #{$classic->id} {$classicName}");
    }

    $this->line('Готово. Classic — без SMC в LLM; SMC Flow — со снимком индикатора.');

    return 0;
})->purpose('Переименовать аналитика в SMC Flow и создать классическую копию без SMC');

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

Artisan::command('bots:scan {--bot_id=} {--active-only=1}', function (BotEngineService $engine) {
    // Если другой PHP-процесс scan уже работает — не стартуем второй (даже при протухшем lock в БД).
    if (bots_scan_process_running(getmypid())) {
        $this->warn('bots:scan уже выполняется — пропуск.');

        return;
    }

    // Жёсткий global lock: withoutOverlapping на file cache не держит параллельные bots:scan,
    // из-за чего endScan() одного процесса гасил счётчик sidebar mid-scan.
    $lockStore = Schema::hasTable('cache') ? Cache::store('database') : Cache::store();
    $lockKey = config('cache.prefix').'bots:scan:global';
    // Scan всех ботов может идти несколько часов — lock должен пережить длинный проход.
    $lockTtlSec = 21600;
    $lock = null;
    $lockAcquired = false;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $lock = $lockStore->lock('bots:scan:global', $lockTtlSec);
        if ($lock->get()) {
            $lockAcquired = true;
            break;
        }
        if ($attempt === 0 && Schema::hasTable('cache_locks')) {
            $row = DB::table('cache_locks')->where('key', $lockKey)->first();
            $scanRunning = bots_scan_process_running(getmypid());
            if ($scanRunning) {
                // Живой scan без валидного lock — не снимаем mutex и не запускаем дубликат.
                $lock = null;
                break;
            }
            $lockExpired = $row && (int) ($row->expiration ?? 0) <= time();
            if ($row && $lockExpired) {
                DB::table('cache_locks')->where('key', $lockKey)->delete();

                continue;
            }
        }
        $lock = null;
        break;
    }
    if (! $lockAcquired || ! $lock instanceof \Illuminate\Contracts\Cache\Lock) {
        $this->warn('bots:scan уже выполняется — пропуск.');

        return;
    }

    try {
        $botId = $this->option('bot_id');
        $activeOnly = (string) $this->option('active-only') !== '0';

        $q = Bot::query()->orderBy('id');
        if ($botId) {
            $q->where('id', (int) $botId);
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
        $lock->release();
    }
})->purpose('Сканировать пары активными ботами и создавать сигналы/сделки');

Artisan::command('bots:scan-dedupe {--no-restart : Не запускать новый scan после остановки}', function () {
    $pids = \App\Support\ArtisanProcessFinder::findArtisanPids(
        static fn (string $cmd): bool => (bool) preg_match('#(?:^|/)(php\d*(?:\.\d+)?)\s+artisan\s+bots:scan\b#', $cmd)
    );

    if (count($pids) <= 1) {
        $this->info(count($pids) === 1
            ? 'Один процесс bots:scan (PID '.$pids[0].') — дубликатов нет.'
            : 'Процесс bots:scan не запущен.');

        return;
    }

    // Параллельные scan почти всегда мешают друг другу; «старейший» часто зависший — гасим всех.
    $killed = \App\Support\ArtisanProcessFinder::terminatePids($pids);
    usleep(300_000);

    if (Schema::hasTable('cache_locks')) {
        $lockKey = config('cache.prefix').'bots:scan:global';
        DB::table('cache_locks')->where('key', $lockKey)->delete();
    }

    $this->warn(sprintf(
        'Параллельных scan: %d. Остановлено: %d (%s). Lock сброшен.',
        count($pids),
        $killed,
        implode(', ', $pids)
    ));

    if ($this->option('no-restart')) {
        $this->comment('Новый scan не запущен (--no-restart). Cron запустит при следующем schedule:run.');

        return;
    }

    $log = storage_path('logs/bots-scan.log');
    $started = \App\Support\ArtisanProcessFinder::spawnBackground([PHP_BINARY, 'artisan', 'bots:scan'], $log);
    if ($started) {
        $this->info('Запущен новый bots:scan в фоне. Лог: '.$log);
    } else {
        $this->error('Не удалось запустить bots:scan. Попробуйте: php artisan bots:scan');
    }
})->purpose('Остановить все параллельные bots:scan, сбросить lock и запустить один новый');

Artisan::command('bots:settle {--bot_id=} {--limit=1000}', function (BotSettlementService $settlement) {
    $botId = $this->option('bot_id') ? (int) $this->option('bot_id') : null;
    $limit = max(1, (int) $this->option('limit'));
    $res = $settlement->settleDueTrades($botId, $limit);
    $this->info(sprintf(
        'checked=%d settled=%d skipped=%d fallback=%d',
        $res['checked'],
        $res['settled'],
        $res['skipped'],
        $res['settled_fallback'] ?? 0
    ));
})->purpose('Закрыть просроченные paper-сделки ботов');

Artisan::command('telegram:ensure-analyst-services', function (
    TelegramAnalystProcessManager $processes,
) {
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

Artisan::command('telegram:analyst-poll {--bot= : ID бота openrouter_llm_analyst} {--timeout=30}', function (
    TelegramAlertService $telegram,
    TelegramAnalystHandler $handler,
) {
    $botId = (int) ($this->option('bot') ?: 0);
    if ($botId <= 0) {
        $candidates = Bot::query()->where('type', 'openrouter_llm_analyst')->orderBy('id')->get();
        $bot = $candidates->first(function (Bot $b): bool {
            return \App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled(is_array($b->config) ? $b->config : []);
        }) ?? $candidates->first();
        if ($bot === null) {
            $this->error('Нет бота openrouter_llm_analyst. Укажите --bot=ID');

            return 1;
        }
    } else {
        $bot = Bot::query()->find($botId);
        if ($bot === null || $bot->type !== 'openrouter_llm_analyst') {
            $this->error('Бот не найден или не openrouter_llm_analyst');

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

Artisan::command('telegram:strong-level-poll {--bot= : ID бота strong_level или strong_trend_telegram} {--timeout=30}', function (
    TelegramAlertService $telegram,
    \App\Services\Telegram\StrongLevelTelegramHandler $handler,
) {
    $supportedTypes = \App\Services\Telegram\StrongLevelTelegramLlmButton::SUPPORTED_BOT_TYPES;
    $botId = (int) ($this->option('bot') ?: 0);
    if ($botId <= 0) {
        $bot = Bot::query()->whereIn('type', $supportedTypes)->where('is_active', true)->orderBy('id')->first();
        if ($bot === null) {
            $bot = Bot::query()->whereIn('type', $supportedTypes)->orderBy('id')->first();
        }
        if ($bot === null) {
            $this->error('Нет бота strong_level / strong_trend_telegram. Укажите --bot=ID');

            return 1;
        }
    } else {
        $bot = Bot::query()->find($botId);
        if ($bot === null || ! \App\Services\Telegram\StrongLevelTelegramLlmButton::supportsBotType($bot->type)) {
            $this->error('Бот не найден или тип не поддерживает LLM-кнопку (strong_level / strong_trend_telegram)');

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
