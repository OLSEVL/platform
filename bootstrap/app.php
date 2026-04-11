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
        // Mutex expires in 5 min so a stuck/hard-killed run does not block scans until default 24h.
        $schedule->command('bots:scan')->everyMinute()->withoutOverlapping(5);
        $schedule->command('bots:settle')->everyMinute()->withoutOverlapping(5);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
