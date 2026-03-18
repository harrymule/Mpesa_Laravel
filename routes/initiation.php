<?php

use Harri\LaravelMpesa\Http\Controllers\Api\MpesaTransactionController;
use Harri\LaravelMpesa\Http\Controllers\StkPushController;
use Illuminate\Support\Facades\Route;

Route::post('/stk-push', [StkPushController::class, 'store']);
Route::post('/c2b/register', [MpesaTransactionController::class, 'registerC2bUrls']);
Route::post('/c2b/simulate', [MpesaTransactionController::class, 'simulateC2b']);
Route::post('/b2c', [MpesaTransactionController::class, 'b2c']);
Route::post('/b2b', [MpesaTransactionController::class, 'b2b']);
Route::post('/reversal', [MpesaTransactionController::class, 'reversal']);
Route::post('/account-balance', [MpesaTransactionController::class, 'accountBalance']);
Route::post('/transaction-status', [MpesaTransactionController::class, 'transactionStatus']);
