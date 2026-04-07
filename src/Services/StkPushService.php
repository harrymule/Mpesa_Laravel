<?php

namespace Harri\LaravelMpesa\Services;

use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\Support\MpesaErrorCatalog;
use Harri\LaravelMpesa\MpesaClient;
use Harri\LaravelMpesa\Support\Mpesa as MpesaSupport;
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

    protected function stkPushModel(): string
    {
        return config('mpesa.models.stk_push', StkPush::class);
    }
}

