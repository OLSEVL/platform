<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
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
    Route::get('/payouts', [DashboardController::class, 'payouts'])->name('payouts.data');
    Route::resource('instruments', InstrumentController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/proxies/refresh', [SettingsController::class, 'refreshProxies'])->name('settings.proxies.refresh');
});
