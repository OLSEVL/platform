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
        $schedule->command('data:auto-cleanup')->hourly();
        // Сервис ниже использует ровно тот же код, что кнопка "Обновить прокси"
        // в /settings. Внутри команды проверяется пользовательский переключатель;
        // withoutOverlapping не допускает параллельную замену одного и того же пула.
        $schedule->command('proxies:refresh-webshare')->dailyAt('00:00')->withoutOverlapping(60);
        // При free_slots=0 раз в час тянем свежий список Webshare (та же кнопка «Обновить прокси»).
        $schedule->command('proxies:refresh-webshare-if-exhausted')->hourly()->withoutOverlapping(30);
        // MaxBot live (5 с) и S30: dispatch каждые 5 с; isDueToSpawn режет частоту per-bot.
        $schedule->command('bots:scan-dispatch')->everyFiveSeconds()->withoutOverlapping(2);
        // Зависший scan (живой PID) блокирует dispatch навсегда — reap по wall-time.
        $schedule->command('bots:scan-watchdog')->everyFiveMinutes()->withoutOverlapping(5);
        // Виртуальные S30-сделки должны быть рассчитаны до следующего scan той же пары.
        $schedule->command('bots:settle')->everyThirtySeconds()->withoutOverlapping(10);
        // Sticky warm pool: после рестарта proxy и при обрыве WS — догрев зеркальных сессий.
        $schedule->command('mirror:warm-pool')->everyMinute()->withoutOverlapping(5);
        // Chart Analyst: /menu и inline-список пар требуют long polling + queue worker.
        $schedule->command('telegram:ensure-analyst-services')->everyMinute()->withoutOverlapping(2);
        // Авто-проба dead: постоянный цикл — отдельный процесс `php artisan proxies:probe-loop`.
        // Пакетный fallback (редко): proxies:probe-dead --limit=5
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
