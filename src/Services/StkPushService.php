<?php

namespace Harri\LaravelMpesa\Services;

use Harri\LaravelMpesa\Models\StkPush;
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

    protected function stkPushModel(): string
    {
        return config('mpesa.models.stk_push', StkPush::class);
    }
}
