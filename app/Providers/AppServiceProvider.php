<?php

namespace App\Providers;

use App\Services\PocketOptionsProxyClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PocketOptionsProxyClient::class, function () {
            return new PocketOptionsProxyClient(config('services.pocket_options_proxy.url', 'http://127.0.0.1:3000'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
