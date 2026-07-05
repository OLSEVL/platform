<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('candles:prune-old')->hourly();
        // Scan всех ботов может идти 1–4+ часа; mutex 45 мин давал параллельные bots:scan после рестарта.
        $schedule->command('bots:scan')->everyMinute()->withoutOverlapping(360);
        $schedule->command('bots:settle')->everyMinute()->withoutOverlapping(10);
        // Chart Analyst: /menu и inline-список пар требуют long polling + queue worker.
        $schedule->command('telegram:ensure-analyst-services')->everyMinute()->withoutOverlapping(2);
        // Авто-проба dead: постоянный цикл — отдельный процесс `php artisan proxies:probe-loop`.
        // Пакетный fallback (редко): proxies:probe-dead --limit=5
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
