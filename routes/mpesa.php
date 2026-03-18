<?php

use Harri\LaravelMpesa\Http\Controllers\Api\MpesaTransactionController;
use Harri\LaravelMpesa\Http\Controllers\MpesaCallbackController;
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
Route::post('/callbacks/stk', [MpesaCallbackController::class, 'stk']);
Route::post('/callbacks/timeout', [MpesaCallbackController::class, 'genericTimeout']);
Route::post('/callbacks/c2b/confirmation', [MpesaCallbackController::class, 'confirmation']);
Route::post('/callbacks/c2b/validation', [MpesaCallbackController::class, 'validation']);
Route::post('/callbacks/b2c/result', [MpesaCallbackController::class, 'result']);
Route::post('/callbacks/b2c/timeout', [MpesaCallbackController::class, 'timeout']);
Route::post('/callbacks/b2b/result', [MpesaCallbackController::class, 'b2bResult']);
Route::post('/callbacks/reversal/result', [MpesaCallbackController::class, 'reversalResult']);
Route::post('/callbacks/account-balance/result', [MpesaCallbackController::class, 'accountBalanceResult']);
Route::post('/callbacks/transaction-status/result', [MpesaCallbackController::class, 'transactionStatusResult']);
