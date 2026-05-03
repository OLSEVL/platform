<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BotChartIndicatorController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
Route::post('/register', [RegisterController::class, 'register']);

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/candles', [DashboardController::class, 'candles'])->name('candles.data');
    Route::get('/structures', [DashboardController::class, 'structures'])->name('structures.data');
    Route::get('/indicators/price-action-dgt', [DashboardController::class, 'priceActionDgtIndicatorData'])->name('dashboard.indicators.price-action-dgt');
    Route::get('/payouts', [DashboardController::class, 'payouts'])->name('payouts.data');
    Route::get('/instruments/data', [DashboardController::class, 'instrumentsSidebarData'])->name('dashboard.instruments.data');
    Route::prefix('bots')->name('bots.')->group(function () {
        Route::get('/', [BotController::class, 'index'])->name('index');
        Route::get('/analytics', [BotController::class, 'analytics'])->name('analytics');
        Route::get('/trades', [BotController::class, 'trades'])->name('trades');
        Route::get('/live-trades', [BotController::class, 'liveTrades'])->name('live-trades');
        Route::get('/live-trades/data', [BotController::class, 'liveTradesData'])->name('live-trades.data');
        Route::get('/{bot}/open-trades/data', [BotController::class, 'openTradesData'])->name('open-trades.data');
        Route::get('/{bot}/signals/data', [BotController::class, 'signalsData'])->name('signals.data');
        Route::get('/{bot}/analysis/data', [BotController::class, 'analysisData'])->name('analysis.data');
        Route::get('/{bot}/stats/data', [BotController::class, 'statsData'])->name('stats.data');
        Route::get('/{bot}/instruments/data', [BotController::class, 'instrumentsSidebarData'])->name('instruments.sidebar-data');
        Route::get('/{bot}/indicators/data', [BotController::class, 'indicatorsData'])->name('indicators.data');
        Route::get('/{bot}/indicators/price-action-dgt', [BotChartIndicatorController::class, 'editPriceActionDgt'])->name('indicators.price-action-dgt.edit');
        Route::put('/{bot}/indicators/price-action-dgt', [BotChartIndicatorController::class, 'updatePriceActionDgt'])->name('indicators.price-action-dgt.update');
        Route::get('/{bot}/indicators/order-blocks', [BotChartIndicatorController::class, 'editOrderBlocks'])->name('indicators.order-blocks.edit');
        Route::get('/{bot}/indicators/fvg', [BotChartIndicatorController::class, 'editFvg'])->name('indicators.fvg.edit');
        Route::get('/{bot}/activity', [BotController::class, 'activity'])->name('activity');
        Route::get('/{bot}/logs', [BotController::class, 'logs'])->name('logs');
        Route::get('/{bot}/settings', [BotController::class, 'settings'])->name('settings');
        Route::put('/{bot}/settings', [BotController::class, 'updateSettings'])->name('settings.update');
        Route::post('/{bot}/cleanup-recent', [BotController::class, 'cleanupRecent'])->name('cleanup-recent');
        Route::get('/{bot}', [BotController::class, 'show'])->name('show');
    });
    Route::resource('instruments', InstrumentController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::post('/instruments/{instrument}/toggle-active', [InstrumentController::class, 'toggleActive'])->name('instruments.toggle-active');
    Route::post('/instruments/{instrument}/proxy/replace', [InstrumentController::class, 'replaceProxy'])->name('instruments.proxy.replace');
    Route::post('/instruments/{instrument}/proxy/replace-and-mark-dead', [InstrumentController::class, 'replaceProxyAndMarkDead'])->name('instruments.proxy.replace-mark-dead');
    Route::post('/instruments/{instrument}/proxy/remove', [InstrumentController::class, 'removeProxy'])->name('instruments.proxy.remove');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/proxies/refresh', [SettingsController::class, 'refreshProxies'])->name('settings.proxies.refresh');
    Route::post('/settings/proxies/auto-rotation', [SettingsController::class, 'updateProxyRotation'])->name('settings.proxies.auto-rotation');
    Route::post('/settings/proxies/{proxy}/reactivate', [SettingsController::class, 'reactivateProxy'])->name('settings.proxies.reactivate');
    Route::post('/settings/proxies/reactivate-all', [SettingsController::class, 'reactivateAllProblemProxies'])->name('settings.proxies.reactivate-all');
    Route::post('/settings/proxies/{proxy}/test-quick-ajax', [SettingsController::class, 'testQuickProxyAjax'])->name('settings.proxies.test-quick-ajax');
    Route::post('/settings/proxies/{proxy}/test-deep-ajax', [SettingsController::class, 'testDeepProxyAjax'])->name('settings.proxies.test-deep-ajax');
    Route::post('/settings/payout/monitoring', [SettingsController::class, 'updatePayoutMonitoring'])->name('settings.payout.monitoring');
});
