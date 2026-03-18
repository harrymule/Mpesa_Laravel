<?php

namespace Harri\LaravelMpesa\Contracts;

use Harri\LaravelMpesa\Models\MpesaTransaction;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;

interface CallbackPayloadTransformer
{
    public function transformStkSuccess(StkPush $stkPush, Payment $payment, array $rawPayload): array;

    public function transformStkFailure(StkPush $stkPush, int $resultCode, string $resultDesc, array $rawPayload): array;

    public function transformTransactionResult(MpesaTransaction $transaction, array $rawPayload, int $resultCode, string $resultDesc): array;
}
