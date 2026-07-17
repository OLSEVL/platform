<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BotChartIndicatorController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\PlatformDiagnosticsController;
use App\Http\Controllers\ProfileMirrorProxiesController;
use App\Http\Controllers\ProfileMirrorQueueController;
use App\Http\Controllers\ProfileTradingController;
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
    Route::get('/indicators/liquidity-swings', [DashboardController::class, 'liquiditySwingsIndicatorData'])->name('dashboard.indicators.liquidity-swings');
    Route::get('/indicators/smc-flow', [DashboardController::class, 'smcFlowIndicatorData'])->name('dashboard.indicators.smc-flow');
    Route::get('/payouts', [DashboardController::class, 'payouts'])->name('payouts.data');
    Route::get('/instruments/data', [DashboardController::class, 'instrumentsSidebarData'])->name('dashboard.instruments.data');
    Route::prefix('bots')->name('bots.')->group(function () {
        Route::get('/', [BotController::class, 'index'])->name('index');
        Route::get('/analytics', [BotController::class, 'analytics'])->name('analytics');
        // Winrate по валютным парам одного бота (графики как на /bots/analytics, но ось «пара», не «бот»).
        Route::get('/analytics/pairs', [BotController::class, 'analyticsPairs'])->name('analytics.pairs');
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
        Route::put('/{bot}/indicators/order-blocks', [BotChartIndicatorController::class, 'updateOrderBlocks'])->name('indicators.order-blocks.update');
        Route::get('/{bot}/structures/data', [BotController::class, 'structuresData'])->name('structures.data');
        Route::get('/{bot}/indicators/fvg', [BotChartIndicatorController::class, 'editFvg'])->name('indicators.fvg.edit');
        Route::get('/{bot}/indicators/liquidity-swings', [BotChartIndicatorController::class, 'editLiquiditySwings'])->name('indicators.liquidity-swings.edit');
        Route::put('/{bot}/indicators/liquidity-swings', [BotChartIndicatorController::class, 'updateLiquiditySwings'])->name('indicators.liquidity-swings.update');
        Route::get('/{bot}/indicators/smc-flow', [BotChartIndicatorController::class, 'editSmcFlow'])->name('indicators.smc-flow.edit');
        Route::put('/{bot}/indicators/smc-flow', [BotChartIndicatorController::class, 'updateSmcFlow'])->name('indicators.smc-flow.update');
        Route::get('/{bot}/activity', [BotController::class, 'activity'])->name('activity');
        Route::get('/{bot}/cards', [BotController::class, 'cards'])->name('cards');
        Route::get('/{bot}/cards/data', [BotController::class, 'cardsData'])->name('cards.data');
        Route::get('/{bot}/cards/{trade}/chart', [BotController::class, 'cardsChart'])->name('cards.chart');
        Route::get('/{bot}/logs', [BotController::class, 'logs'])->name('logs');
        Route::get('/{bot}/settings', [BotController::class, 'settings'])->name('settings');
        Route::put('/{bot}/settings', [BotController::class, 'updateSettings'])->name('settings.update');
        Route::post('/{bot}/telegram/test', [BotController::class, 'telegramTest'])->name('telegram.test');
        Route::post('/{bot}/telegram/discover-chat', [BotController::class, 'telegramDiscoverChat'])->name('telegram.discover-chat');
        Route::get('/{bot}/telegram/analyst-status', [BotController::class, 'telegramAnalystStatus'])->name('telegram.analyst-status');
        Route::post('/{bot}/telegram/analyst-restart', [BotController::class, 'telegramAnalystRestart'])->name('telegram.analyst-restart');
        Route::get('/{bot}/telegram/strong-level-status', [BotController::class, 'telegramStrongLevelStatus'])->name('telegram.strong-level-status');
        Route::post('/{bot}/telegram/strong-level-restart', [BotController::class, 'telegramStrongLevelRestart'])->name('telegram.strong-level-restart');
        Route::post('/{bot}/cleanup-recent', [BotController::class, 'cleanupRecent'])->name('cleanup-recent');
        Route::get('/{bot}', [BotController::class, 'show'])->name('show');
    });
    Route::resource('instruments', InstrumentController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::post('/instruments/{instrument}/toggle-active', [InstrumentController::class, 'toggleActive'])->name('instruments.toggle-active');
    Route::post('/instruments/{instrument}/proxy/replace', [InstrumentController::class, 'replaceProxy'])->name('instruments.proxy.replace');
    Route::post('/instruments/{instrument}/proxy/replace-and-mark-dead', [InstrumentController::class, 'replaceProxyAndMarkDead'])->name('instruments.proxy.replace-mark-dead');
    Route::post('/instruments/{instrument}/proxy/remove', [InstrumentController::class, 'removeProxy'])->name('instruments.proxy.remove');
    Route::redirect('/profile', '/profile/trading')->name('profile.index');
    Route::get('/profile/trading', [ProfileTradingController::class, 'edit'])->name('profile.trading.edit');
    Route::put('/profile/trading', [ProfileTradingController::class, 'update'])->name('profile.trading.update');
    Route::post('/profile/trading/reset-martingale', [ProfileTradingController::class, 'resetMartingale'])
        ->name('profile.trading.reset-martingale');
    Route::post('/profile/trading/test-connection', [ProfileTradingController::class, 'testConnection'])
        ->name('profile.trading.test-connection');
    Route::get('/profile/mirror-proxies', [ProfileMirrorProxiesController::class, 'edit'])->name('profile.mirror-proxies.edit');
    Route::put('/profile/mirror-proxies', [ProfileMirrorProxiesController::class, 'update'])->name('profile.mirror-proxies.update');
    Route::get('/profile/mirror-queue', [ProfileMirrorQueueController::class, 'index'])->name('profile.mirror-queue.index');
    Route::get('/profile/mirror-queue/data', [ProfileMirrorQueueController::class, 'data'])->name('profile.mirror-queue.data');
    Route::put('/profile/mirror-queue/window', [ProfileMirrorQueueController::class, 'updateWindow'])->name('profile.mirror-queue.window');

    Route::get('/settings/processes', [PlatformDiagnosticsController::class, 'index'])->name('settings.processes.index');
    Route::get('/settings/processes/live-ajax', [PlatformDiagnosticsController::class, 'liveAjax'])->name('settings.processes.live-ajax');
    Route::post('/settings/processes/{key}/kill', [PlatformDiagnosticsController::class, 'kill'])->name('settings.processes.kill');
    Route::post('/settings/processes/{key}/restart', [PlatformDiagnosticsController::class, 'restart'])->name('settings.processes.restart');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/proxies/refresh', [SettingsController::class, 'refreshProxies'])->name('settings.proxies.refresh');
    Route::post('/settings/proxies/auto-rotation', [SettingsController::class, 'updateProxyRotation'])->name('settings.proxies.auto-rotation');
    Route::post('/settings/proxies/{proxy}/reactivate', [SettingsController::class, 'reactivateProxy'])->name('settings.proxies.reactivate');
    Route::post('/settings/proxies/{proxy}/mark-dead', [SettingsController::class, 'markProxyDead'])->name('settings.proxies.mark-dead');
    Route::post('/settings/proxies/reactivate-all', [SettingsController::class, 'reactivateAllProblemProxies'])->name('settings.proxies.reactivate-all');
    Route::post('/settings/proxies/{proxy}/test-quick-ajax', [SettingsController::class, 'testQuickProxyAjax'])->name('settings.proxies.test-quick-ajax');
    Route::post('/settings/proxies/{proxy}/test-deep-ajax', [SettingsController::class, 'testDeepProxyAjax'])->name('settings.proxies.test-deep-ajax');
    Route::get('/settings/proxies/{proxy}/health-row-ajax', [SettingsController::class, 'proxyHealthRowAjax'])->name('settings.proxies.health-row-ajax');
    Route::get('/settings/proxies/health-live-ajax', [SettingsController::class, 'proxyHealthLiveAjax'])->name('settings.proxies.health-live-ajax');
    Route::post('/settings/payout/monitoring', [SettingsController::class, 'updatePayoutMonitoring'])->name('settings.payout.monitoring');
    Route::post('/settings/data/purge', [SettingsController::class, 'purgeOldData'])->name('settings.data.purge');
    Route::post('/settings/data/auto-cleanup', [SettingsController::class, 'updateDataAutoCleanup'])->name('settings.data.auto-cleanup');
    Route::post('/settings/bots/scan-watchdog', [SettingsController::class, 'updateScanWatchdog'])->name('settings.bots.scan-watchdog');
});
