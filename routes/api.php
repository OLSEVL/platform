<?php

use App\Http\Middleware\ValidateProxyIngestToken;
use Illuminate\Support\Facades\Route;

Route::middleware(ValidateProxyIngestToken::class)->prefix('proxy')->group(function () {
    Route::get('instruments', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'instruments']);
});
Route::middleware(ValidateProxyIngestToken::class)->post('ingest-candles', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'ingestCandles']);
Route::middleware(ValidateProxyIngestToken::class)->post('ingest-payout', [App\Http\Controllers\Api\ProxyInstrumentController::class, 'ingestPayout']);
