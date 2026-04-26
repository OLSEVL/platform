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
