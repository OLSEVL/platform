<?php

use App\Models\InstrumentCandle;
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
