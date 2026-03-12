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
