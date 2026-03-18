<?php

namespace Harri\LaravelMpesa\Services;

use Harri\LaravelMpesa\Models\MpesaTransaction;
use Harri\LaravelMpesa\MpesaClient;

class TransactionService
{
    public function __construct(protected MpesaClient $client)
    {
    }

    public function registerC2bUrls(array $payload, array $meta = []): array
    {
        $response = $this->client->registerUrls($payload);

        return $this->store('c2b_register', $payload, $response, 'completed', $meta);
    }

    public function simulateC2b(array $payload, array $meta = []): array
    {
        $response = $this->client->simulateC2b($payload);

        return $this->store('c2b_simulate', $payload, $response, 'completed', $meta);
    }

    public function b2c(array $payload, ?string $callbackUrl = null, array $meta = []): array
    {
        $response = $this->client->b2c($payload);

        return $this->store('b2c', $payload, $response, 'requested', $meta, $callbackUrl);
    }

    public function b2b(array $payload, ?string $callbackUrl = null, array $meta = []): array
    {
        $response = $this->client->b2b($payload);

        return $this->store('b2b', $payload, $response, 'requested', $meta, $callbackUrl);
    }

    public function reversal(array $payload, ?string $callbackUrl = null, array $meta = []): array
    {
        $response = $this->client->reversal($payload);

        return $this->store('reversal', $payload, $response, 'requested', $meta, $callbackUrl);
    }

    public function accountBalance(array $payload = [], ?string $callbackUrl = null, array $meta = []): array
    {
        $response = $this->client->accountBalance($payload);

        return $this->store('account_balance', $payload, $response, 'requested', $meta, $callbackUrl);
    }

    public function transactionStatus(array $payload, ?string $callbackUrl = null, array $meta = []): array
    {
        $response = $this->client->transactionStatus($payload);

        return $this->store('transaction_status', $payload, $response, 'requested', $meta, $callbackUrl);
    }

    public function batch(array $operations, array $meta = [], ?string $connection = null): array
    {
        $prepared = [];

        foreach ($operations as $index => $operation) {
            $type = (string) ($operation['type'] ?? '');
            $payload = $operation['payload'] ?? [];
            $definition = $this->batchDefinition($type);
            $key = (string) ($operation['key'] ?? $index);

            $prepared[$key] = [
                'type' => $type,
                'payload' => $payload,
                'callback_url' => $operation['callback_url'] ?? null,
                'meta' => array_merge($meta, $operation['meta'] ?? []),
                'status' => $definition['status'],
                'client_operation' => [
                    'key' => $key,
                    'uri' => $definition['uri'],
                    'secure' => $definition['secure'],
                    'payload' => $payload,
                ],
            ];
        }

        $responses = $this->client->batch(array_column($prepared, 'client_operation'), $connection);
        $results = [];

        foreach ($prepared as $key => $operation) {
            $results[$key] = $this->store(
                $operation['type'],
                $operation['payload'],
                $responses[$key],
                $operation['status'],
                $operation['meta'],
                $operation['callback_url'],
            );
        }

        return $results;
    }

    protected function store(string $type, array $payload, array $response, string $status, array $meta = [], ?string $callbackUrl = null): array
    {
        $model = $this->transactionModel();

        $transaction = $model::query()->create([
            'type' => $type,
            'status' => $status,
            'phone_number' => $payload['PhoneNumber'] ?? $payload['PartyB'] ?? $payload['Msisdn'] ?? null,
            'amount' => $payload['Amount'] ?? null,
            'reference' => $payload['AccountReference'] ?? $payload['BillRefNumber'] ?? $payload['TransactionID'] ?? null,
            'callback_url' => $callbackUrl,
            'merchant_request_id' => $response['MerchantRequestID'] ?? null,
            'checkout_request_id' => $response['CheckoutRequestID'] ?? null,
            'conversation_id' => $response['ConversationID'] ?? null,
            'originator_conversation_id' => $response['OriginatorConversationID'] ?? null,
            'request_payload' => $payload,
            'response_payload' => $response,
            'meta' => $meta,
        ]);

        return [
            'transaction' => $transaction,
            'response' => $response,
        ];
    }

    protected function batchDefinition(string $type): array
    {
        return match ($type) {
            'c2b_register' => ['uri' => '/mpesa/c2b/v2/registerurl', 'secure' => false, 'status' => 'completed'],
            'c2b_simulate' => ['uri' => '/mpesa/c2b/v2/simulate', 'secure' => false, 'status' => 'completed'],
            'b2c' => ['uri' => '/mpesa/b2c/v1/paymentrequest', 'secure' => true, 'status' => 'requested'],
            'b2b' => ['uri' => '/mpesa/b2b/v1/paymentrequest', 'secure' => true, 'status' => 'requested'],
            'reversal' => ['uri' => '/mpesa/reversal/v2/request', 'secure' => true, 'status' => 'requested'],
            'account_balance' => ['uri' => '/mpesa/accountbalance/v1/query', 'secure' => true, 'status' => 'requested'],
            'transaction_status' => ['uri' => '/mpesa/transactionstatus/v1/query', 'secure' => true, 'status' => 'requested'],
            default => throw new \InvalidArgumentException("Unsupported M-Pesa batch operation [{$type}]."),
        };
    }

    protected function transactionModel(): string
    {
        return config('mpesa.models.transaction', MpesaTransaction::class);
    }
}
