<?php

use Harri\LaravelMpesa\Http\Controllers\MpesaCallbackController;
use Illuminate\Support\Facades\Route;

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
