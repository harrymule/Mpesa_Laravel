<?php

namespace Harri\LaravelMpesa\Services;

use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\MpesaClient;
use Harri\LaravelMpesa\Support\Mpesa as MpesaSupport;
use Harri\LaravelMpesa\Support\MpesaErrorCatalog;
use Illuminate\Support\Str;

class StkPushService
{
    public function __construct(protected MpesaClient $client)
    {
    }

    public function initiate(
        float|int|string $amount,
        string $phone,
        string $reference,
        ?string $callbackUrl = null,
        ?string $description = null,
        array $meta = []
    ): array {
        $trackingId = (string) Str::uuid();
        $phone = MpesaSupport::formatPhone($phone);
        $description ??= (string) config('mpesa.stk.default_description', 'STK Push Payment');

        $response = $this->client->stkPush([
            'Amount' => $amount,
            'PartyA' => $phone,
            'PhoneNumber' => $phone,
            'AccountReference' => $reference,
            'TransactionDesc' => $description,
        ]);

        $model = $this->stkPushModel();
        $record = $model::query()->create([
            'tracking_id' => $trackingId,
            'phone_number' => $phone,
            'amount' => $amount,
            'invoice_number' => $reference,
            'merchant_request_id' => $response['MerchantRequestID'] ?? null,
            'checkout_request_id' => $response['CheckoutRequestID'] ?? null,
            'response_description' => $response['ResponseDescription'] ?? null,
            'customer_message' => $response['CustomerMessage'] ?? null,
            'response_code' => $response['ResponseCode'] ?? null,
            'callback_url' => $callbackUrl,
            'status' => 0,
            'meta' => $meta,
        ]);

        return [
            'tracking_id' => $trackingId,
            'merchant_request_id' => $record->merchant_request_id,
            'checkout_request_id' => $record->checkout_request_id,
            'response' => $response,
            'record' => $record,
        ];
    }

    public function query(string $checkoutRequestId, array $meta = []): array
    {
        $response = $this->client->stkPushQuery($checkoutRequestId);
        $model = $this->stkPushModel();
        $record = $model::query()->where('checkout_request_id', $checkoutRequestId)->first();
        $resultCode = isset($response['ResultCode']) ? (string) $response['ResultCode'] : null;
        $resultDesc = $response['ResultDesc'] ?? null;

        if ($record) {
            $existingMeta = is_array($record->meta) ? $record->meta : [];
            $record->update([
                'merchant_request_id' => $response['MerchantRequestID'] ?? $record->merchant_request_id,
                'response_code' => $resultCode ?? ($response['ResponseCode'] ?? $record->response_code),
                'response_description' => $response['ResponseDescription'] ?? $record->response_description,
                'internal_comment' => $resultDesc ?? $record->internal_comment,
                'status' => $resultCode === '0' ? 1 : $record->status,
                'meta' => array_merge($existingMeta, [
                    'query' => array_merge($meta, [
                        'response' => $response,
                    ]),
                ]),
            ]);
        }

        if ($resultCode !== null && $resultCode !== '0') {
            MpesaErrorCatalog::record(200, [
                'ResultCode' => $resultCode,
                'ResultDesc' => (string) ($resultDesc ?? 'STK query failed.'),
            ], (string) ($resultDesc ?? 'STK query failed.'), 'stk_query', 'query_result');
        }

        return [
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $response['MerchantRequestID'] ?? null,
            'response' => $response,
            'record' => $record,
        ];
    }

    public function status(string $trackingId): array
    {
        $record = $this->stkPushModel()::findByTracking($trackingId);

        if (! $record) {
            return [
                'matched' => false,
                'tracking_id' => $trackingId,
            ];
        }

        $payment = $record->payment;
        $status = $this->deriveStatus($record, $payment);
        $errorCode = $record->response_code !== '0' ? (string) $record->response_code : null;
        $errorMessage = $status === 'failed'
            ? $this->friendlyResultMessage($record->response_code, $record->internal_comment ?: $record->response_description)
            : null;

        return [
            'matched' => true,
            'tracking_id' => $trackingId,
            'status' => $status,
            'can_show_paybill' => $status !== 'completed' && $this->c2bFallbackEnabled(),
            'checkout_request_id' => $record->checkout_request_id,
            'merchant_request_id' => $record->merchant_request_id,
            'reference' => $record->invoice_number,
            'amount' => $record->amount,
            'phone_number' => $record->phone_number,
            'receipt_number' => $payment?->trans_id ?? $record->transaction_code,
            'result_code' => $record->response_code,
            'result_desc' => $record->internal_comment ?: $record->response_description,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }

    public function paybillInstructions(string $trackingId): array
    {
        $record = $this->stkPushModel()::findByTracking($trackingId);

        if (! $record) {
            return [
                'matched' => false,
                'tracking_id' => $trackingId,
                'fallback_enabled' => $this->c2bFallbackEnabled(),
            ];
        }

        $accountReference = $this->accountReference($record);

        return [
            'matched' => true,
            'tracking_id' => $trackingId,
            'fallback_enabled' => $this->c2bFallbackEnabled(),
            'shortcode' => $this->c2bFallbackShortcode(),
            'account_reference' => $accountReference,
            'reference' => $record->invoice_number,
            'amount' => $record->amount,
            'phone_number' => $record->phone_number,
            'instructions' => (string) config('mpesa.c2b.fallback.instructions', 'Complete payment via M-Pesa PayBill and use the provided account reference.'),
        ];
    }

    public function verifyManualPayment(
        string $receiptNumber,
        ?string $trackingId = null,
        ?string $phone = null,
        float|int|string|null $amount = null,
        ?string $reference = null,
    ): array {
        $payment = $this->paymentModel()::query()->where('trans_id', $receiptNumber)->first();

        if (! $payment) {
            return [
                'verified' => false,
                'reason' => 'receipt_not_found',
                'message' => 'The supplied M-Pesa receipt could not be found.',
            ];
        }

        $record = $trackingId ? $this->stkPushModel()::findByTracking($trackingId) : null;
        $normalizedPhone = $phone ? MpesaSupport::formatPhone($phone) : null;

        if ($trackingId !== null && ! $record) {
            return [
                'verified' => false,
                'reason' => 'tracking_not_found',
                'message' => 'The supplied tracking ID could not be found.',
                'payment' => $payment,
            ];
        }

        if ($normalizedPhone !== null && (string) $payment->msisdn !== $normalizedPhone) {
            return [
                'verified' => false,
                'reason' => 'phone_mismatch',
                'message' => 'The supplied phone number does not match the receipt details.',
                'payment' => $payment,
            ];
        }

        if ($amount !== null && (float) $payment->trans_amount !== (float) $amount) {
            return [
                'verified' => false,
                'reason' => 'amount_mismatch',
                'message' => 'The supplied amount does not match the receipt details.',
                'payment' => $payment,
            ];
        }

        if ($reference !== null && ! in_array($reference, array_filter([
            $payment->bill_ref_number,
            $payment->invoice_number,
            $record?->invoice_number,
        ]), true)) {
            return [
                'verified' => false,
                'reason' => 'reference_mismatch',
                'message' => 'The supplied reference does not match the receipt details.',
                'payment' => $payment,
            ];
        }

        if ($record) {
            $payment->forceFill([
                'tracking_id' => $payment->tracking_id ?: $record->tracking_id,
                'stk_push_id' => $payment->stk_push_id ?: $record->id,
                'callback_url' => $payment->callback_url ?: $record->callback_url,
            ])->save();

            $existingMeta = is_array($record->meta) ? $record->meta : [];

            $record->update([
                'payment_id' => $record->payment_id ?: $payment->id,
                'transaction_code' => $payment->trans_id,
                'phone_number' => $payment->msisdn ?: $record->phone_number,
                'amount' => $payment->trans_amount ?: $record->amount,
                'response_code' => '0',
                'internal_comment' => 'Manually verified payment receipt.',
                'status' => 1,
                'meta' => array_merge($existingMeta, [
                    'manual_verification' => [
                        'receipt_number' => $receiptNumber,
                    ],
                ]),
            ]);
        }

        return [
            'verified' => true,
            'message' => 'The supplied M-Pesa receipt has been verified successfully.',
            'payment' => $payment->fresh(),
            'record' => $record?->fresh(),
        ];
    }

    protected function deriveStatus(StkPush $record, ?Payment $payment = null): string
    {
        if ($payment || $record->payment_id || $record->transaction_code || (string) $record->response_code === '0' && (int) $record->status === 1) {
            return 'completed';
        }

        if ($record->internal_comment || ($record->response_code !== null && (string) $record->response_code !== '0')) {
            return 'failed';
        }

        return 'pending';
    }

    protected function accountReference(StkPush $record): string
    {
        $meta = is_array($record->meta) ? $record->meta : [];

        return (string) ($meta['account_reference'] ?? $record->invoice_number ?? $record->tracking_id);
    }

    protected function c2bFallbackEnabled(): bool
    {
        return (bool) config('mpesa.c2b.fallback.enabled', false);
    }

    protected function c2bFallbackShortcode(): ?string
    {
        return config('mpesa.c2b.fallback.shortcode', config('mpesa.shortcode'));
    }

    protected function friendlyResultMessage(string|int|null $resultCode, ?string $default = null): string
    {
        $messages = [
            '1' => 'The payer account has insufficient balance for this transaction.',
            '17' => 'A similar transaction was attempted too recently. Please wait and try again.',
            '1019' => 'The transaction expired before it could be completed.',
            '1025' => 'An error occurred while sending the payment prompt.',
            '1032' => 'The payment request was cancelled by the user.',
            '1037' => 'The customer phone could not be reached for the payment prompt.',
            '2001' => 'The payment was not completed because the supplied PIN was invalid.',
            '2028' => 'The request is not permitted for the configured product assignment.',
            '8006' => 'The customer account security state prevented the payment from completing.',
            'SFC_IC0003' => 'The target M-Pesa operator details could not be resolved.',
        ];

        $code = $resultCode === null ? null : (string) $resultCode;

        return $messages[$code] ?? (string) ($default ?: 'The payment request did not complete successfully.');
    }

    protected function stkPushModel(): string
    {
        return config('mpesa.models.stk_push', StkPush::class);
    }

    protected function paymentModel(): string
    {
        return config('mpesa.models.payment', Payment::class);
    }
}
