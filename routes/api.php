<?php

use App\Http\Controllers\ExtensionSignalsController;
use App\Http\Middleware\ValidateProxyIngestToken;
use Illuminate\Support\Facades\Route;

Route::middleware(ValidateProxyIngestToken::class)->prefix('proxy')->group(function () {
    Route::get('pool-version', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'poolVersion']);
    Route::get('instruments', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'instruments']);
});
Route::middleware(ValidateProxyIngestToken::class)->post('ingest-candles', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'ingestCandles']);
Route::middleware(ValidateProxyIngestToken::class)->post('ingest-payout', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'ingestPayout']);
// Partial-свеча: в production nginx location = /api/ingest-current-candle → public/ingest-current.php
// (без Laravel bootstrap). Маршрут ниже — fallback, если запрос попал в index.php.
Route::middleware(ValidateProxyIngestToken::class)->post('ingest-current-candle', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'ingestCurrentCandle']);
Route::middleware(ValidateProxyIngestToken::class)->post('ingest-proxy-failure', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'ingestProxyFailure']);
Route::middleware(ValidateProxyIngestToken::class)->post('ingest-proxy-health', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'ingestProxyHealth']);
Route::middleware(ValidateProxyIngestToken::class)->get('bot/series', [App\Http\Controllers\Api\BotMarketDataController::class, 'series']);

// Chrome-расширение (LAN): история сигналов без токена.
Route::get('extension/signals', [ExtensionSignalsController::class, 'index']);
Route::get('extension/resolve', [ExtensionSignalsController::class, 'resolve']);
