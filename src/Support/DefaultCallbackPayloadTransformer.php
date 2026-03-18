<?php

namespace Harri\LaravelMpesa\Support;

use Harri\LaravelMpesa\Contracts\CallbackPayloadTransformer;
use Harri\LaravelMpesa\Models\MpesaTransaction;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;

class DefaultCallbackPayloadTransformer implements CallbackPayloadTransformer
{
    public function transformStkSuccess(StkPush $stkPush, Payment $payment, array $rawPayload): array
    {
        return [
            'StatusCode' => 0,
            'Message' => 'The service request is processed successfully.',
            'trans_code' => $payment->trans_id,
            'tracking_id' => $payment->tracking_id,
            'amount' => $payment->trans_amount,
            'business_short_code' => $payment->business_short_code,
            'reference' => $payment->bill_ref_number,
            'phone' => $payment->msisdn,
            'channel' => $payment->path,
            'name' => $payment->full_name,
            'payload' => $rawPayload,
        ];
    }

    public function transformStkFailure(StkPush $stkPush, int $resultCode, string $resultDesc, array $rawPayload): array
    {
        return [
            'StatusCode' => $resultCode,
            'Message' => $resultDesc,
            'reference' => $stkPush->invoice_number,
            'tracking_id' => $stkPush->tracking_id,
            'checkout_request_id' => $stkPush->checkout_request_id,
            'payload' => $rawPayload,
        ];
    }

    public function transformTransactionResult(MpesaTransaction $transaction, array $rawPayload, int $resultCode, string $resultDesc): array
    {
        return [
            'type' => $transaction->type,
            'StatusCode' => $resultCode,
            'Message' => $resultDesc,
            'conversation_id' => $transaction->conversation_id,
            'originator_conversation_id' => $transaction->originator_conversation_id,
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->reference,
            'amount' => $transaction->amount,
            'phone' => $transaction->phone_number,
            'payload' => $rawPayload,
        ];
    }
}
