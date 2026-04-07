<?php

namespace Harri\LaravelMpesa\Services;

use Harri\LaravelMpesa\Contracts\CallbackPayloadTransformer;
use Harri\LaravelMpesa\Jobs\ForwardMpesaCallbackJob;
use Harri\LaravelMpesa\Models\MpesaTransaction;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\Support\DefaultCallbackPayloadTransformer;
use Harri\LaravelMpesa\Support\MpesaErrorCatalog;
use Harri\LaravelMpesa\Support\MpesaLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class MpesaCallbackProcessor
{
    public function processStk(array $payload): array
    {
        $stkCallback = data_get($payload, 'Body.stkCallback', []);
        $merchantRequestId = data_get($stkCallback, 'MerchantRequestID');
        $checkoutRequestId = data_get($stkCallback, 'CheckoutRequestID');
        $resultCode = (int) data_get($stkCallback, 'ResultCode', 1);
        $resultDesc = (string) data_get($stkCallback, 'ResultDesc', 'Unknown callback result');

        $stkModel = $this->stkPushModel();
        $paymentModel = $this->paymentModel();

        $stkPush = $stkModel::query()
            ->where('checkout_request_id', $checkoutRequestId)
            ->where('merchant_request_id', $merchantRequestId)
            ->first();

        if (! $stkPush) {
            return [
                'matched' => false,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ];
        }

        if ($resultCode !== 0) {
            MpesaErrorCatalog::record(200, [
                'ResultCode' => (string) $resultCode,
                'ResultDesc' => $resultDesc,
            ], $resultDesc, 'stk', 'callback_result');

            $stkPush->update([
                'response_code' => $resultCode,
                'internal_comment' => $resultDesc,
                'status' => 0,
                'meta' => $payload,
            ]);

            $this->forwardFailure($stkPush, $resultCode, $resultDesc, $payload);

            return [
                'matched' => true,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'status' => 'failed',
            ];
        }

        $metadata = collect(data_get($stkCallback, 'CallbackMetadata.Item', []))
            ->mapWithKeys(function (array $item): array {
                return [$item['Name'] => $item['Value'] ?? null];
            });

        $receiptNumber = (string) $metadata->get('MpesaReceiptNumber');
        $existingPayment = $receiptNumber !== ''
            ? $paymentModel::query()->where('trans_id', $receiptNumber)->first()
            : null;

        if ($existingPayment && ($stkPush->payment_id === $existingPayment->id || (string) $stkPush->transaction_code === $receiptNumber)) {
            $this->logDuplicate('stk', [
                'receipt_number' => $receiptNumber,
                'stk_push_id' => $stkPush->id,
                'payment_id' => $existingPayment->id,
            ]);

            return [
                'matched' => true,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'status' => 'duplicate',
                'payment_id' => $existingPayment->id,
            ];
        }

        $payment = $existingPayment ?: $paymentModel::query()->firstOrCreate([
            'trans_id' => $receiptNumber,
        ], [
            'tracking_id' => $stkPush->tracking_id,
            'stk_push_id' => $stkPush->id,
            'transaction_type' => 'Pay Bill',
            'trans_time' => (string) $metadata->get('TransactionDate'),
            'trans_amount' => (string) $metadata->get('Amount'),
            'business_short_code' => (string) config('mpesa.shortcode'),
            'bill_ref_number' => $stkPush->invoice_number,
            'invoice_number' => $stkPush->invoice_number,
            'msisdn' => (string) $metadata->get('PhoneNumber'),
            'status' => 'COMPLETED',
            'path' => 'stk',
            'callback_url' => $stkPush->callback_url,
        ]);

        $stkPush->update([
            'response_code' => $resultCode,
            'internal_comment' => $resultDesc,
            'phone_number' => (string) $metadata->get('PhoneNumber'),
            'amount' => (string) $metadata->get('Amount'),
            'user_action' => 1,
            'status' => 1,
            'transaction_code' => $receiptNumber,
            'payment_id' => $payment->id,
            'meta' => $payload,
        ]);

        $this->forwardSuccess($stkPush, $payment, $payload);

        return [
            'matched' => true,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'status' => 'completed',
            'payment_id' => $payment->id,
        ];
    }

    public function processC2bConfirmation(array $payload): array
    {
        $paymentModel = $this->paymentModel();
        $transactionModel = $this->transactionModel();
        $transId = data_get($payload, 'TransID');

        $payment = $paymentModel::query()->updateOrCreate([
            'trans_id' => $transId,
        ], [
            'tracking_id' => $transId,
            'transaction_type' => data_get($payload, 'TransactionType'),
            'trans_time' => data_get($payload, 'TransTime'),
            'trans_amount' => data_get($payload, 'TransAmount'),
            'business_short_code' => data_get($payload, 'BusinessShortCode'),
            'bill_ref_number' => data_get($payload, 'BillRefNumber'),
            'invoice_number' => data_get($payload, 'InvoiceNumber', data_get($payload, 'BillRefNumber')),
            'org_account_balance' => data_get($payload, 'OrgAccountBalance'),
            'third_party_trans_id' => data_get($payload, 'ThirdPartyTransID'),
            'msisdn' => data_get($payload, 'MSISDN'),
            'first_name' => data_get($payload, 'FirstName'),
            'middle_name' => data_get($payload, 'MiddleName'),
            'last_name' => data_get($payload, 'LastName'),
            'status' => 'COMPLETED',
            'path' => 'c2b',
        ]);

        $transaction = $transactionModel::query()->updateOrCreate([
            'type' => 'c2b_confirmation',
            'transaction_id' => $transId,
        ], [
            'status' => 'completed',
            'phone_number' => data_get($payload, 'MSISDN'),
            'amount' => data_get($payload, 'TransAmount'),
            'reference' => data_get($payload, 'BillRefNumber'),
            'callback_payload' => $payload,
        ]);

        return [
            'payment_id' => $payment->id,
            'transaction_id' => $transaction->id,
        ];
    }

    public function processC2bValidation(array $payload, string $status = 'validated'): array
    {
        $model = $this->transactionModel();
        $transId = data_get($payload, 'TransID');

        $transaction = $model::query()->updateOrCreate([
            'type' => 'c2b_validation',
            'transaction_id' => $transId,
        ], [
            'status' => $status,
            'phone_number' => data_get($payload, 'MSISDN'),
            'amount' => data_get($payload, 'TransAmount'),
            'reference' => data_get($payload, 'BillRefNumber'),
            'callback_payload' => $payload,
        ]);

        return ['transaction_id' => $transaction->id];
    }

    public function processResult(string $type, array $payload): array
    {
        $conversationId = data_get($payload, 'Result.ConversationID');
        $originatorConversationId = data_get($payload, 'Result.OriginatorConversationID');
        $transactionId = data_get($payload, 'Result.TransactionID');
        $resultCode = (int) data_get($payload, 'Result.ResultCode', 1);
        $resultDesc = (string) data_get($payload, 'Result.ResultDesc', 'Unknown result');

        if ($resultCode !== 0) {
            MpesaErrorCatalog::record(200, [
                'ResultCode' => (string) $resultCode,
                'ResultDesc' => $resultDesc,
            ], $resultDesc, $type, 'callback_result');
        }

        $transaction = $this->findTransaction($type, $conversationId, $originatorConversationId);
        $transactionModel = $this->transactionModel();

        if (! $transaction) {
            $transaction = $transactionModel::query()->create([
                'type' => $type,
                'status' => $resultCode === 0 ? 'completed' : 'failed',
                'conversation_id' => $conversationId,
                'originator_conversation_id' => $originatorConversationId,
                'transaction_id' => $transactionId,
                'callback_payload' => $payload,
                'result_code' => (string) $resultCode,
                'result_desc' => $resultDesc,
            ]);
        } else {
            $transaction->update([
                'status' => $resultCode === 0 ? 'completed' : 'failed',
                'transaction_id' => $transactionId,
                'callback_payload' => $payload,
                'result_code' => (string) $resultCode,
                'result_desc' => $resultDesc,
            ]);
        }

        $this->forwardTransactionResult($transaction, $payload, $resultCode, $resultDesc);

        return [
            'transaction_id' => $transaction->id,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
        ];
    }

    public function processTimeout(string $type, array $payload): array
    {
        $transaction = $this->findTimeoutTransaction($type, $payload);

        if ($transaction) {
            MpesaErrorCatalog::record(200, [
                'ResultCode' => '1037',
                'ResultDesc' => 'Timed out',
            ], 'Timed out', $type, 'timeout');

            $transaction->update([
                'status' => 'timeout',
                'callback_payload' => $payload,
                'result_desc' => 'Timed out',
            ]);

            $this->forwardTransactionResult($transaction, $payload, 1037, 'Timed out');
        }

        return ['matched' => (bool) $transaction];
    }

    public function processTimeoutAny(array $payload): array
    {
        $conversationId = data_get($payload, 'Result.ConversationID') ?: data_get($payload, 'ConversationID');
        $originatorConversationId = data_get($payload, 'Result.OriginatorConversationID') ?: data_get($payload, 'OriginatorConversationID');

        $transaction = $this->transactionModel()::query()
            ->where(function ($query) use ($conversationId, $originatorConversationId) {
                $query->where('conversation_id', $conversationId)
                    ->orWhere('originator_conversation_id', $originatorConversationId);
            })->first();

        if ($transaction) {
            MpesaErrorCatalog::record(200, [
                'ResultCode' => '1037',
                'ResultDesc' => 'Timed out',
            ], 'Timed out', $transaction->type, 'timeout');

            $transaction->update([
                'status' => 'timeout',
                'callback_payload' => $payload,
                'result_desc' => 'Timed out',
            ]);

            $this->forwardTransactionResult($transaction, $payload, 1037, 'Timed out');
        }

        return ['matched' => (bool) $transaction];
    }

    protected function findTransaction(string $type, ?string $conversationId, ?string $originatorConversationId): ?MpesaTransaction
    {
        return $this->transactionModel()::query()
            ->where('type', $type)
            ->where(function ($query) use ($conversationId, $originatorConversationId) {
                $query->where('conversation_id', $conversationId)
                    ->orWhere('originator_conversation_id', $originatorConversationId);
            })->first();
    }

    protected function findTimeoutTransaction(string $type, array $payload): ?MpesaTransaction
    {
        $conversationId = data_get($payload, 'Result.ConversationID') ?: data_get($payload, 'ConversationID');
        $originatorConversationId = data_get($payload, 'Result.OriginatorConversationID') ?: data_get($payload, 'OriginatorConversationID');

        return $this->findTransaction($type, $conversationId, $originatorConversationId);
    }

    protected function forwardSuccess(StkPush $stkPush, Payment $payment, array $payload): void
    {
        if (! $stkPush->callback_url || ! filter_var($stkPush->callback_url, FILTER_VALIDATE_URL)) {
            return;
        }

        $job = new ForwardMpesaCallbackJob(
            $stkPush->callback_url,
            $this->transformer()->transformStkSuccess($stkPush, $payment, $payload),
            $payment->id,
            null,
            'stk',
        );

        $this->dispatch($job);
    }

    protected function forwardFailure(StkPush $stkPush, int $resultCode, string $resultDesc, array $payload): void
    {
        if (! $stkPush->callback_url || ! filter_var($stkPush->callback_url, FILTER_VALIDATE_URL)) {
            return;
        }

        $job = new ForwardMpesaCallbackJob(
            $stkPush->callback_url,
            $this->transformer()->transformStkFailure($stkPush, $resultCode, $resultDesc, $payload),
            null,
            null,
            'stk',
        );

        $this->dispatch($job);
    }

    protected function forwardTransactionResult(MpesaTransaction $transaction, array $payload, int $resultCode, string $resultDesc): void
    {
        if (! $transaction->callback_url || ! filter_var($transaction->callback_url, FILTER_VALIDATE_URL)) {
            return;
        }

        $job = new ForwardMpesaCallbackJob(
            $transaction->callback_url,
            $this->transformer()->transformTransactionResult($transaction, $payload, $resultCode, $resultDesc),
            null,
            $transaction->id,
            $transaction->type,
        );

        $this->dispatch($job);
    }

    protected function dispatch(ForwardMpesaCallbackJob $job): void
    {
        if (! config('mpesa.dispatch_callback_job', true)) {
            $job->handle();
            return;
        }

        $connection = config('mpesa.callback_job_connection');
        $queue = config('mpesa.callback_job_queue');

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        Bus::dispatch($job);
    }

    protected function transformer(): CallbackPayloadTransformer
    {
        $class = config('mpesa.transformers.callback_payload', DefaultCallbackPayloadTransformer::class);

        return app($class);
    }

    protected function stkPushModel(): string
    {
        return config('mpesa.models.stk_push', StkPush::class);
    }

    protected function paymentModel(): string
    {
        return config('mpesa.models.payment', Payment::class);
    }

    protected function transactionModel(): string
    {
        return config('mpesa.models.transaction', MpesaTransaction::class);
    }

    protected function logDuplicate(string $type, array $context): void
    {
        Log::channel(MpesaLog::channel($type))->info("mpesa.{$type}.duplicate_callback", $context);
    }
}
