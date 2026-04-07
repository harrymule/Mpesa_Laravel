<?php

namespace Harri\LaravelMpesa;

use Harri\LaravelMpesa\Exceptions\MpesaRequestException;
use Harri\LaravelMpesa\Support\Mpesa as MpesaSupport;
use Harri\LaravelMpesa\Support\MpesaLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MpesaClient
{
    public function __construct(protected array $config)
    {
    }

    public function accessToken(?string $connection = null): array
    {
        $connection = $this->connectionName($connection);
        $credentials = $this->connection($connection);
        $cacheKey = $this->tokenCacheKey($connection);
        $ttl = max(1, (int) Arr::get($this->config, 'oauth.ttl', 3500));
        $hasCachedToken = Cache::has($cacheKey);

        $this->logInfo($hasCachedToken ? 'mpesa.oauth.cache_hit' : 'mpesa.oauth.fetching', [
            'connection' => $connection,
            'cache_key' => $cacheKey,
            'ttl' => $ttl,
        ], 'oauth');

        return Cache::remember($cacheKey, now()->addSeconds($ttl), function () use ($connection, $credentials) {
            $response = Http::withBasicAuth(
                (string) Arr::get($credentials, 'consumer_key'),
                (string) Arr::get($credentials, 'consumer_secret')
            )->get($this->baseUrl($connection) . '/oauth/v1/generate?grant_type=client_credentials');

            $data = $this->handleResponse($response->throw(), 'Unable to get M-Pesa access token.');

            $this->logInfo('mpesa.oauth.fetched', [
                'connection' => $connection,
                'expires_in' => Arr::get($data, 'expires_in'),
            ], 'oauth');

            return $data;
        });
    }

    public function clearAccessTokenCache(?string $connection = null): void
    {
        $connection = $this->connectionName($connection);
        Cache::forget($this->tokenCacheKey($connection));

        $this->logInfo('mpesa.oauth.cache_cleared', [
            'connection' => $connection,
        ], 'oauth');
    }

    public function stkPush(array $payload, ?string $connection = null): array
    {
        $timestamp = Arr::get($payload, 'Timestamp', MpesaSupport::timestamp());
        $shortCode = (string) Arr::get($payload, 'BusinessShortCode', $this->config['shortcode']);
        $passkey = (string) Arr::get($payload, 'Passkey', $this->config['passkey']);

        $payload = array_merge([
            'BusinessShortCode' => $shortCode,
            'Password' => MpesaSupport::stkPassword($shortCode, $passkey, $timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => Arr::get($payload, 'TransactionType', 'CustomerPayBillOnline'),
            'PartyA' => MpesaSupport::formatPhone((string) Arr::get($payload, 'PartyA')),
            'PartyB' => Arr::get($payload, 'PartyB', $shortCode),
            'PhoneNumber' => MpesaSupport::formatPhone((string) Arr::get($payload, 'PhoneNumber', Arr::get($payload, 'PartyA'))),
            'CallBackURL' => Arr::get($payload, 'CallBackURL', Arr::get($this->config, 'stk.callback_url', $this->config['callback_url'])),
            'AccountReference' => Arr::get($payload, 'AccountReference', Arr::get($this->config, 'stk.default_reference', 'Payment')),
            'TransactionDesc' => Arr::get($payload, 'TransactionDesc', Arr::get($this->config, 'stk.default_description', 'Payment')),
        ], $payload);

        unset($payload['Passkey']);

        return $this->post('/mpesa/stkpush/v1/processrequest', $payload, $connection, 'stk');
    }

    public function stkPushQuery(string $checkoutRequestId, ?string $connection = null, ?string $shortCode = null, ?string $passkey = null): array
    {
        $timestamp = MpesaSupport::timestamp();
        $shortCode ??= (string) $this->config['shortcode'];
        $passkey ??= (string) $this->config['passkey'];

        return $this->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $shortCode,
            'Password' => MpesaSupport::stkPassword($shortCode, $passkey, $timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ], $connection, 'stk');
    }

    public function registerUrls(array $payload, ?string $connection = null): array
    {
        return $this->post('/mpesa/c2b/v2/registerurl', array_merge([
            'ShortCode' => Arr::get($payload, 'ShortCode', $this->config['shortcode']),
            'ResponseType' => Arr::get($payload, 'ResponseType', 'Completed'),
            'ConfirmationURL' => Arr::get($payload, 'ConfirmationURL', $this->config['confirmation_url']),
            'ValidationURL' => Arr::get($payload, 'ValidationURL', $this->config['validation_url']),
        ], $payload), $connection, 'c2b');
    }

    public function simulateC2b(array $payload, ?string $connection = null): array
    {
        return $this->post('/mpesa/c2b/v2/simulate', array_merge([
            'ShortCode' => Arr::get($payload, 'ShortCode', $this->config['shortcode']),
            'CommandID' => Arr::get($payload, 'CommandID', 'CustomerPayBillOnline'),
            'Msisdn' => MpesaSupport::formatPhone((string) Arr::get($payload, 'Msisdn')),
            'BillRefNumber' => Arr::get($payload, 'BillRefNumber', 'Test'),
        ], $payload), $connection, 'c2b');
    }

    public function b2c(array $payload, ?string $connection = null): array
    {
        return $this->securePost((string) Arr::get($this->config, 'b2c.payment_uri', '/mpesa/b2c/v3/paymentrequest'), array_merge([
            'InitiatorName' => Arr::get($payload, 'InitiatorName', $this->config['initiator_name']),
            'CommandID' => Arr::get($payload, 'CommandID', 'BusinessPayment'),
            'PartyA' => Arr::get($payload, 'PartyA', $this->config['shortcode']),
            'PartyB' => MpesaSupport::formatPhone((string) Arr::get($payload, 'PartyB')),
            'Remarks' => Arr::get($payload, 'Remarks', 'B2C Payment'),
            'QueueTimeOutURL' => Arr::get($payload, 'QueueTimeOutURL', $this->config['timeout_url']),
            'ResultURL' => Arr::get($payload, 'ResultURL', $this->config['result_url']),
            'Occasion' => Arr::get($payload, 'Occasion', 'B2C Payment'),
        ], $payload), $connection, 'b2c');
    }

    public function transactionStatus(array $payload, ?string $connection = null): array
    {
        return $this->securePost('/mpesa/transactionstatus/v1/query', array_merge([
            'Initiator' => Arr::get($payload, 'Initiator', $this->config['initiator_name']),
            'CommandID' => Arr::get($payload, 'CommandID', 'TransactionStatusQuery'),
            'PartyA' => Arr::get($payload, 'PartyA', $this->config['shortcode']),
            'IdentifierType' => Arr::get($payload, 'IdentifierType', '4'),
            'QueueTimeOutURL' => Arr::get($payload, 'QueueTimeOutURL', $this->config['timeout_url']),
            'ResultURL' => Arr::get($payload, 'ResultURL', $this->config['result_url']),
            'Remarks' => Arr::get($payload, 'Remarks', 'Transaction status'),
            'Occasion' => Arr::get($payload, 'Occasion', 'Transaction status'),
        ], $payload), $connection, 'transaction_status');
    }

    public function accountBalance(array $payload = [], ?string $connection = null): array
    {
        return $this->securePost('/mpesa/accountbalance/v1/query', array_merge([
            'Initiator' => Arr::get($payload, 'Initiator', $this->config['initiator_name']),
            'CommandID' => Arr::get($payload, 'CommandID', 'AccountBalance'),
            'PartyA' => Arr::get($payload, 'PartyA', $this->config['shortcode']),
            'IdentifierType' => Arr::get($payload, 'IdentifierType', '4'),
            'Remarks' => Arr::get($payload, 'Remarks', 'Account balance'),
            'QueueTimeOutURL' => Arr::get($payload, 'QueueTimeOutURL', $this->config['timeout_url']),
            'ResultURL' => Arr::get($payload, 'ResultURL', $this->config['result_url']),
        ], $payload), $connection, 'account_balance');
    }

    public function reversal(array $payload, ?string $connection = null): array
    {
        return $this->securePost('/mpesa/reversal/v2/request', array_merge([
            'Initiator' => Arr::get($payload, 'Initiator', $this->config['initiator_name']),
            'CommandID' => Arr::get($payload, 'CommandID', 'TransactionReversal'),
            'ReceiverParty' => Arr::get($payload, 'ReceiverParty', $this->config['shortcode']),
            'RecieverIdentifierType' => Arr::get($payload, 'RecieverIdentifierType', '11'),
            'QueueTimeOutURL' => Arr::get($payload, 'QueueTimeOutURL', $this->config['timeout_url']),
            'ResultURL' => Arr::get($payload, 'ResultURL', $this->config['result_url']),
            'Remarks' => Arr::get($payload, 'Remarks', 'Transaction reversal'),
            'Occasion' => Arr::get($payload, 'Occasion', 'Transaction reversal'),
        ], $payload), $connection, 'reversal');
    }

    public function b2b(array $payload, ?string $connection = null): array
    {
        return $this->securePost('/mpesa/b2b/v1/paymentrequest', array_merge([
            'Initiator' => Arr::get($payload, 'Initiator', $this->config['initiator_name']),
            'CommandID' => Arr::get($payload, 'CommandID', 'BusinessPayBill'),
            'SenderIdentifierType' => Arr::get($payload, 'SenderIdentifierType', '4'),
            'RecieverIdentifierType' => Arr::get($payload, 'RecieverIdentifierType', '4'),
            'PartyA' => Arr::get($payload, 'PartyA', $this->config['shortcode']),
            'AccountReference' => Arr::get($payload, 'AccountReference', 'B2B'),
            'Remarks' => Arr::get($payload, 'Remarks', 'B2B Payment'),
            'QueueTimeOutURL' => Arr::get($payload, 'QueueTimeOutURL', $this->config['timeout_url']),
            'ResultURL' => Arr::get($payload, 'ResultURL', $this->config['result_url']),
        ], $payload), $connection, 'b2b');
    }

    public function qrCode(array $payload, ?string $connection = null): array
    {
        return $this->post((string) Arr::get($this->config, 'qr.generate_uri', '/mpesa/qrcode/v1/generate'), array_merge([
            'MerchantName' => Arr::get($payload, 'MerchantName'),
            'RefNo' => Arr::get($payload, 'RefNo'),
            'Amount' => Arr::get($payload, 'Amount'),
            'TrxCode' => Arr::get($payload, 'TrxCode', 'BG'),
            'CPI' => Arr::get($payload, 'CPI', $this->config['shortcode']),
            'Size' => (string) Arr::get($payload, 'Size', Arr::get($this->config, 'qr.default_size', '300')),
        ], $payload), $connection, 'qr');
    }

    public function batch(array $operations, ?string $connection = null): array
    {
        $connection = $this->connectionName($connection);
        $accessToken = Arr::get($this->accessToken($connection), 'access_token');
        $preparedOperations = array_map(fn (array $operation): array => $this->prepareBatchOperation($operation, $connection), $operations);
        $journeys = array_values(array_unique(array_map(fn (array $operation): string => MpesaLog::journeyFromUri($operation['uri']), $preparedOperations)));
        $batchJourney = count($journeys) === 1 ? $journeys[0] : 'default';

        $this->logInfo('mpesa.api.batch_request', [
            'connection' => $connection,
            'operations' => array_map(fn (array $operation): array => [
                'key' => $operation['key'],
                'method' => $operation['method'],
                'uri' => $operation['uri'],
                'payload' => $this->sanitizePayload($operation['payload']),
            ], $preparedOperations),
        ], $batchJourney);

        $responses = Http::pool(function (Pool $pool) use ($preparedOperations, $connection, $accessToken) {
            $requests = [];

            foreach ($preparedOperations as $operation) {
                $request = $pool->as($operation['key'])
                    ->baseUrl($this->baseUrl($connection))
                    ->acceptJson()
                    ->contentType('application/json')
                    ->withToken((string) $accessToken);

                $method = strtolower($operation['method']);
                $requests[$operation['key']] = $request->{$method}($operation['uri'], $operation['payload']);
            }

            return $requests;
        });

        $results = [];

        foreach ($preparedOperations as $operation) {
            $key = $operation['key'];
            $response = $responses[$key] ?? null;
            $journey = MpesaLog::journeyFromUri($operation['uri']);

            if (! $response instanceof Response) {
                throw new MpesaRequestException('M-Pesa batch request returned an invalid response.', 500, null, [], $journey);
            }

            try {
                $results[$key] = $this->handleResponse($response->throw(), 'M-Pesa batch request failed.');
            } catch (RequestException $exception) {
                $this->clearAccessTokenCache($connection);
                $details = $exception->response?->json();
                $details = is_array($details) ? $details : ['raw_body' => $exception->response?->body()];

                $this->logError('mpesa.api.batch_failed', [
                    'connection' => $connection,
                    'key' => $key,
                    'uri' => $operation['uri'],
                    'status' => $exception->response?->status(),
                    'response' => $details,
                ], $journey);

                throw new MpesaRequestException(
                    $exception->response?->body() ?: 'M-Pesa batch request failed.',
                    $exception->response?->status() ?? 500,
                    $exception,
                    $details,
                    $journey,
                );
            }
        }

        $this->logInfo('mpesa.api.batch_response', [
            'connection' => $connection,
            'results' => $results,
        ], $batchJourney);

        return $results;
    }

    public function generateSecurityCredential(?string $connection = null): string
    {
        $connection = $this->connectionName($connection);
        $configured = (string) Arr::get($this->connection($connection), 'security_credential');

        if ($configured !== '') {
            return $configured;
        }

        $certificatePath = (string) Arr::get($this->config, 'security_certificate_path');
        $initiatorPassword = (string) Arr::get($this->config, 'initiator_password');

        if ($certificatePath === '' || $initiatorPassword === '' || ! is_file($certificatePath)) {
            throw new MpesaRequestException('M-Pesa security credential is not configured.');
        }

        $publicKey = file_get_contents($certificatePath);

        if ($publicKey === false) {
            throw new MpesaRequestException('Unable to read M-Pesa certificate file.');
        }

        $encrypted = null;
        openssl_public_encrypt($initiatorPassword, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);

        return base64_encode($encrypted ?: '');
    }

    protected function post(string $uri, array $payload, ?string $connection = null, ?string $journey = null): array
    {
        $connection = $this->connectionName($connection);
        $journey ??= MpesaLog::journeyFromUri($uri);

        $this->logInfo('mpesa.api.request', [
            'connection' => $connection,
            'uri' => $uri,
            'payload' => $this->sanitizePayload($payload),
        ], $journey);

        try {
            $response = $this->request($connection)->post($uri, $payload)->throw();
        } catch (RequestException $exception) {
            $this->clearAccessTokenCache($connection);
            $details = $exception->response?->json();
            $details = is_array($details) ? $details : ['raw_body' => $exception->response?->body()];

            $this->logError('mpesa.api.failed', [
                'connection' => $connection,
                'uri' => $uri,
                'status' => $exception->response?->status(),
                'response' => $details,
            ], $journey);

            throw new MpesaRequestException(
                $exception->response?->body() ?: 'M-Pesa request failed.',
                $exception->response?->status() ?? 500,
                $exception,
                $details,
                $journey,
            );
        }

        $data = $this->handleResponse($response, 'M-Pesa request failed.');

        $this->logInfo('mpesa.api.response', [
            'connection' => $connection,
            'uri' => $uri,
            'status' => $response->status(),
            'response' => $data,
        ], $journey);

        return $data;
    }

    protected function securePost(string $uri, array $payload, ?string $connection = null, ?string $journey = null): array
    {
        $connection = $this->connectionName($connection);

        $payload = array_merge([
            'SecurityCredential' => $this->generateSecurityCredential($connection),
        ], $payload);

        return $this->post($uri, $payload, $connection, $journey);
    }

    protected function request(?string $connection = null): PendingRequest
    {
        $connection = $this->connectionName($connection);
        $accessToken = Arr::get($this->accessToken($connection), 'access_token');

        return Http::baseUrl($this->baseUrl($connection))
            ->acceptJson()
            ->contentType('application/json')
            ->withToken((string) $accessToken);
    }

    protected function handleResponse(mixed $response, string $message): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new MpesaRequestException($message);
        }

        return $data;
    }

    protected function connectionName(?string $connection = null): string
    {
        return $connection ?: (string) Arr::get($this->config, 'default', 'sandbox');
    }

    protected function connection(string $connection): array
    {
        $config = Arr::get($this->config, "connections.{$connection}", []);

        if ($config === []) {
            throw new MpesaRequestException("M-Pesa connection [{$connection}] is not configured.");
        }

        return $config;
    }

    protected function baseUrl(string $connection): string
    {
        return rtrim((string) Arr::get($this->connection($connection), 'base_url'), '/');
    }

    protected function tokenCacheKey(string $connection): string
    {
        $prefix = (string) Arr::get($this->config, 'oauth.cache_prefix', 'mpesa_oauth_token');

        return $prefix . ':' . $connection;
    }

    protected function prepareBatchOperation(array $operation, string $connection): array
    {
        $payload = $operation['payload'] ?? [];
        $secure = (bool) ($operation['secure'] ?? false);

        if ($secure && ! array_key_exists('SecurityCredential', $payload)) {
            $payload['SecurityCredential'] = $this->generateSecurityCredential($connection);
        }

        return [
            'key' => (string) ($operation['key'] ?? uniqid('mpesa_batch_', true)),
            'method' => strtoupper((string) ($operation['method'] ?? 'POST')),
            'uri' => (string) ($operation['uri'] ?? ''),
            'payload' => $payload,
        ];
    }

    protected function sanitizePayload(array $payload): array
    {
        return collect($payload)->map(function (mixed $value, string|int $key) {
            if (! is_string($key)) {
                return $value;
            }

            if (in_array($key, ['Password', 'Passkey', 'SecurityCredential'], true)) {
                return '[REDACTED]';
            }

            return $value;
        })->all();
    }

    protected function logInfo(string $message, array $context = [], ?string $journey = null): void
    {
        Log::channel(MpesaLog::channel($journey))->info($message, $context);
    }

    protected function logError(string $message, array $context = [], ?string $journey = null): void
    {
        Log::channel(MpesaLog::channel($journey))->error($message, $context);
    }
}



