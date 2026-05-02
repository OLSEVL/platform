<?php

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Bots\BotEngineService;
use App\Services\Bots\BotSettlementService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('candles:prune-old', function () {
    $threshold = time() - (24 * 3600); // старше 24 часов
    $deleted = InstrumentCandle::where('time', '<', $threshold)->delete();
    $this->info("Удалено устаревших свечей: {$deleted}.");
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
    $this->comment('Нет новых сделок при повторном скане — нормально, если по инструменту уже есть открытая сделка, сработал dedupe (для Feshan — по окну экспирации), или стратегия не даёт сигнал (3 свечи / Feshan MTF).');
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
                'stale_after_sec' => 45,
                'version' => 'akim_trade_1m_v1',
            ],
        ]
    );

    // Бот 2: версия "из второго видео" — быстрый поток S5 и короткая экспирация.
    $bot2 = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'AKIM TRADE 5S', 'type' => 'akim_trade_5s'],
        [
            'is_active' => false,
            'config' => [
                'count' => 500,
                'expiry_sec' => 5,
                'min_confidence' => 0.60,
                'stale_after_sec' => 10,
                'version' => 'akim_trade_5s_v1',
            ],
        ]
    );

    $this->info("Готово: #{$bot1->id} {$bot1->name} ({$bot1->type})");
    $this->info("Готово: #{$bot2->id} {$bot2->name} ({$bot2->type})");
})->purpose('Создать ботов AKIM TRADE 1M и AKIM TRADE 5S');

Artisan::command('bots:feshan-bootstrap', function () {
    $user = User::query()->orderBy('id')->first();
    if (! $user) {
        $this->error('Нет пользователей. Сначала зарегистрируйте пользователя.');

        return;
    }

    $bot = Bot::query()->firstOrCreate(
        ['user_id' => $user->id, 'name' => 'Feshan bot', 'type' => 'feshan'],
        [
            'is_active' => false,
            'config' => [
                'count' => 2500,
                'expiry_sec' => 300,
                // Базовый профиль под стратегию "liquidity sweep".
                'min_confidence' => 0.60,
                'min_payout' => 80.0,
                'stale_after_sec' => 45,
                'liquidity_lookback' => 20,
                'sweep_min_wick_ratio' => 0.35,
                'sweep_min_reclaim_ratio' => 0.12,
                'entry_extrema_distance' => 0.12,
                'version' => 'feshan_liquidity_v2',
            ],
        ]
    );

    $this->info("Feshan бот: #{$bot->id} {$bot->name} ({$bot->type}) — включите в настройках при необходимости.");
})->purpose('Создать бота Feshan (MTF-стратегия)');

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
                'candles_take' => 120,
                'expiry_sec' => 300,
                'min_confidence' => 0.65,
                'min_payout' => 70.0,
                'stale_after_sec' => 45,
                'model' => 'openai/gpt-5.2',
                'temperature' => 0.0,
                'max_tokens' => 350,
                'system_prompt' => "Ты торговый AI-ассистент для бинарных опционов.\nРаботаешь по MTF-логике: H1 -> M15 -> M5 -> M1.\n\nВерни только JSON без markdown.\nФормат:\n{\"side\":\"CALL|PUT|NONE\",\"confidence\":0.0,\"analysis_text\":\"...\",\"reasons_short\":[\"...\"],\"features\":{}}\n\nДанные в запросе:\n- closed_candles: M1\n- mtf.h1_closed: H1\n- mtf.m15_closed: M15\n- mtf.m5_closed: M5\n\nОбязательный алгоритм:\n1) H1: определи bias = up | down | flat.\n2) M15: определи структуру у уровня: support/resistance, retest/breakout/false_breakout/range.\n3) M5: ищи подтверждение: rejection candle, breakout confirmation, возврат под/над уровень после ложного пробоя.\n4) M1: только точка входа (тайминг), не источник основного bias.\n\nПравила входа:\n- CALL только если H1 bias up, M15 поддерживает long-сценарий и M5 дал подтверждение.\n- PUT только если H1 bias down, M15 поддерживает short-сценарий и M5 дал подтверждение.\n- Иначе side = \"NONE\".\n\nФильтры качества:\n- Если H1 = flat и M15 = range без четкого края уровня -> NONE.\n- Если M1 сигнал против H1+M15 -> NONE.\n- Если подтверждений меньше 2 (на M15/M5) -> NONE.\n- Если не уверен -> NONE и confidence <= 0.5.\n\nConfidence:\n- NONE: 0.0..0.5\n- CALL/PUT: 0.55..1.0 только при подтвержденном MTF-согласовании.\n\nСтрогий формат explanation:\n- analysis_text обязательно в виде:\n  \"H1: ...; M15: ...; M5: ...; M1: ...; setup: ...\"\n- reasons_short: 3-5 коротких пунктов, желательно в порядке H1 -> M15 -> M5 -> M1.\n\nfeatures (обязательно заполняй):\n{\n  \"h1_bias\":\"up|down|flat\",\n  \"m15_structure\":\"support|resistance|range|breakout|retest|false_breakout\",\n  \"m5_trigger\":\"rejection|breakout_confirm|none\",\n  \"entry_tf\":\"M1\",\n  \"setup_type\":\"rejection|breakout_retest|false_breakout|none\",\n  \"mtf_alignment_score\": 0.0,\n  \"level_price\": 0.0\n}",
                'version' => 'openrouter_llm_v1',
            ],
        ]
    );

    $this->info("OpenRouter бот: #{$bot->id} {$bot->name} ({$bot->type})");
    $this->line('Проверьте .env: OPENROUTER_API_KEY, затем включите бота в настройках.');
})->purpose('Создать отдельного бота OpenRouter LLM');

Artisan::command('bots:scan {--bot_id=} {--active-only=1}', function (BotEngineService $engine) {
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
})->purpose('Сканировать пары активными ботами и создавать сигналы/сделки');

Artisan::command('bots:settle {--bot_id=} {--limit=1000}', function (BotSettlementService $settlement) {
    $botId = $this->option('bot_id') ? (int) $this->option('bot_id') : null;
    $limit = max(1, (int) $this->option('limit'));
    $res = $settlement->settleDueTrades($botId, $limit);
    $this->info(sprintf(
        'checked=%d settled=%d skipped=%d',
        $res['checked'],
        $res['settled'],
        $res['skipped']
    ));
})->purpose('Закрыть просроченные paper-сделки ботов');
