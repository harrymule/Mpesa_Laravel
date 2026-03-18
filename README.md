# Laravel M-Pesa

[![Tests](https://github.com/harrymule/Mpesa_Laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/harrymule/Mpesa_Laravel/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012-red.svg)](https://laravel.com)

A plug-and-play Laravel package for Safaricom M-Pesa Daraja API integrations. Provides REST endpoints, callback processing, database persistence, event dispatching, and configurable security middleware out of the box.

## Features

- Raw Daraja client for STK Push/Query, C2B Register/Simulate, B2C, B2B, Reversal, Account Balance, and Transaction Status
- REST endpoints for all Daraja initiation flows (8 endpoints) and callback receivers (10 endpoints)
- Database persistence for STK requests, payment confirmations, and generic transaction logs
- OAuth token caching with configurable TTL
- Batch/concurrent HTTP requests via Laravel's `Http::pool()`
- Callback processors with idempotency checks to prevent duplicate processing
- Queued callback forwarding to client application URLs with configurable queue connection
- Configurable initiation route protection (Bearer token + rate limiting)
- Configurable callback trust protection (IP allowlist, shared secret, HMAC signature)
- Pluggable C2B validation responder for accepting or rejecting transactions
- Customizable Eloquent models and callback payload transformer
- Laravel events for all callback types (11 events including forwarding failures)
- Separate route group loading (initiation-only, callbacks-only, or both)
- GitHub Actions CI workflow (PHP 8.2 & 8.3)

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- MySQL (or any Laravel-supported database)

## Installation

```bash
composer require harri/laravel-mpesa
php artisan vendor:publish --tag=mpesa-config
php artisan migrate
```

Copy the environment variables from [.env.example](.env.example) into your project's `.env` file and fill in your Daraja credentials.

## Quick Start

### Using the Facade

```php
use Harri\LaravelMpesa\Facades\Mpesa;

// STK Push (Lipa Na M-Pesa Online)
$response = Mpesa::stkPush([
    'BusinessShortCode' => config('mpesa.shortcode'),
    'Password'          => $password,
    'Timestamp'         => $timestamp,
    'TransactionType'   => 'CustomerPayBillOnline',
    'Amount'            => 100,
    'PartyA'            => '254712345678',
    'PartyB'            => config('mpesa.shortcode'),
    'PhoneNumber'       => '254712345678',
    'CallBackURL'       => 'https://your-app.test/mpesa/callbacks/stk',
    'AccountReference'  => 'INV-1001',
    'TransactionDesc'   => 'Invoice payment',
]);

// Query STK Push status
$status = Mpesa::stkPushQuery($checkoutRequestId);

// B2C payment
$response = Mpesa::b2c([...]);

// Account balance
$response = Mpesa::accountBalance([...]);

// Batch concurrent requests
$results = Mpesa::batch([
    ['key' => 'bal', 'method' => 'POST', 'uri' => '/mpesa/accountbalance/v1/query', 'payload' => [...]],
    ['key' => 'status', 'method' => 'POST', 'uri' => '/mpesa/transactionstatus/v1/query', 'payload' => [...]],
]);
```

### Using the REST Endpoints

Send JSON requests to the package endpoints. All initiation routes require a Bearer token when `MPESA_INITIATION_TOKEN` is set.

**STK Push:**

```bash
curl -X POST https://your-app.test/mpesa/stk-push \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json" \
  -d '{"amount": 100, "phone": "254712345678", "reference": "INV-1001"}'
```

## Routes

When `MPESA_LOAD_ROUTES=true` (default), the package registers endpoints under your configured prefix (default: `/mpesa`).

### Initiation Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/mpesa/stk-push` | Initiate STK Push |
| POST | `/mpesa/c2b/register` | Register C2B URLs |
| POST | `/mpesa/c2b/simulate` | Simulate C2B payment |
| POST | `/mpesa/b2c` | Business to Customer payment |
| POST | `/mpesa/b2b` | Business to Business payment |
| POST | `/mpesa/reversal` | Reverse a transaction |
| POST | `/mpesa/account-balance` | Query account balance |
| POST | `/mpesa/transaction-status` | Query transaction status |

### Callback Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/mpesa/callbacks/stk` | STK Push callback |
| POST | `/mpesa/callbacks/timeout` | Generic timeout |
| POST | `/mpesa/callbacks/c2b/confirmation` | C2B confirmation |
| POST | `/mpesa/callbacks/c2b/validation` | C2B validation |
| POST | `/mpesa/callbacks/b2c/result` | B2C result |
| POST | `/mpesa/callbacks/b2c/timeout` | B2C timeout |
| POST | `/mpesa/callbacks/b2b/result` | B2B result |
| POST | `/mpesa/callbacks/reversal/result` | Reversal result |
| POST | `/mpesa/callbacks/account-balance/result` | Account balance result |
| POST | `/mpesa/callbacks/transaction-status/result` | Transaction status result |

You can load only the route groups you need:

```env
MPESA_LOAD_ROUTES=true
MPESA_LOAD_INITIATION_ROUTES=true
MPESA_LOAD_CALLBACK_ROUTES=true
```

## Security

### Initiation Route Protection

```env
MPESA_INITIATION_ROUTE_MIDDLEWARE=api,throttle:mpesa.initiation,mpesa.initiation.auth
MPESA_INITIATION_TOKEN=your-secret-token
```

Send the token as `Authorization: Bearer <token>` or `X-Mpesa-Token` header.

**Rate limiting** is enabled by default (60 requests per 60 seconds):

```env
MPESA_INITIATION_RATE_LIMIT_ENABLED=true
MPESA_INITIATION_RATE_LIMIT_MAX_ATTEMPTS=60
MPESA_INITIATION_RATE_LIMIT_DECAY_SECONDS=60
```

### Callback Trust Protection

```env
MPESA_CALLBACK_ROUTE_MIDDLEWARE=api,mpesa.callback.auth
MPESA_CALLBACK_SECRET=your-callback-secret
MPESA_TRUSTED_CALLBACK_IPS=196.201.214.200,196.201.214.206
```

Optional HMAC signature validation:

```env
MPESA_CALLBACK_HMAC_ENABLED=true
MPESA_CALLBACK_HMAC_SECRET=your-hmac-secret
MPESA_CALLBACK_HMAC_HEADER=X-Mpesa-Signature
MPESA_CALLBACK_HMAC_ALGORITHM=sha256
```

If no security values are configured, the middleware passes through without blocking.

## Configuration

All configuration is environment-driven. Publish the config and see [.env.example](.env.example) for the full list of `MPESA_*` variables.

Key configuration areas:

| Area | Variables |
|------|-----------|
| Connection | `MPESA_DEFAULT` (sandbox/live) |
| Credentials | `MPESA_SHORTCODE`, `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`, `MPESA_PASSKEY` |
| OAuth Cache | `MPESA_OAUTH_CACHE_TTL` (default: 3500s) |
| Routes | `MPESA_ROUTE_PREFIX`, `MPESA_LOAD_ROUTES`, `MPESA_LOAD_INITIATION_ROUTES`, `MPESA_LOAD_CALLBACK_ROUTES` |
| Security | `MPESA_INITIATION_TOKEN`, `MPESA_CALLBACK_SECRET`, `MPESA_TRUSTED_CALLBACK_IPS` |
| Queue | `MPESA_CALLBACK_JOB_CONNECTION`, `MPESA_CALLBACK_JOB_QUEUE` |
| Rate Limit | `MPESA_INITIATION_RATE_LIMIT_MAX_ATTEMPTS`, `MPESA_INITIATION_RATE_LIMIT_DECAY_SECONDS` |

## Database

The package creates three tables:

| Table | Purpose |
|-------|---------|
| `mpesa_stk_pushes` | Tracks STK Push initiation requests and their status |
| `mpesa_payments` | Stores successful payment confirmations (STK and C2B) |
| `mpesa_transactions` | Generic log for all non-STK requests and callbacks |

The `mpesa_payments` table enforces a unique constraint on `trans_id` for idempotent callback processing.

## C2B Validation Hook

By default, the package accepts all C2B validation requests. To apply custom business rules, implement the `C2bValidationResponder` contract:

```php
namespace App\Support;

use Harri\LaravelMpesa\Contracts\C2bValidationResponder;

class RejectLargeC2bPayments implements C2bValidationResponder
{
    public function respond(array $payload): array
    {
        if ((float) ($payload['TransAmount'] ?? 0) > 10000) {
            return [
                'ResultCode' => 'C2B00011',
                'ResultDesc' => 'Amount exceeds merchant validation rules.',
            ];
        }

        return [
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ];
    }
}
```

Register it in `config/mpesa.php`:

```php
'c2b' => [
    'validation_responder' => App\Support\RejectLargeC2bPayments::class,
],
```

When a non-zero `ResultCode` is returned, the callback is recorded with status `rejected`.

## Customization

### Override Models

```php
// config/mpesa.php
'models' => [
    'stk_push'    => App\Models\CustomMpesaStkPush::class,
    'payment'     => App\Models\CustomMpesaPayment::class,
    'transaction' => App\Models\CustomMpesaTransaction::class,
],
```

### Override Callback Payload Transformer

Implement [CallbackPayloadTransformer](src/Contracts/CallbackPayloadTransformer.php) and register it:

```php
// config/mpesa.php
'transformers' => [
    'callback_payload' => App\Support\CustomMpesaPayloadTransformer::class,
],
```

### Configure Queue for Callback Forwarding

```env
MPESA_CALLBACK_JOB_CONNECTION=redis
MPESA_CALLBACK_JOB_QUEUE=mpesa-callbacks
```

## Events

The package dispatches events for every callback type:

| Event | Trigger |
|-------|---------|
| `StkCallbackReceived` | STK Push callback received |
| `C2bConfirmationReceived` | C2B confirmation received |
| `C2bValidationReceived` | C2B validation received |
| `B2cResultReceived` | B2C result received |
| `B2cTimeoutReceived` | B2C timeout received |
| `B2bResultReceived` | B2B result received |
| `ReversalResultReceived` | Reversal result received |
| `AccountBalanceResultReceived` | Account balance result received |
| `TransactionStatusResultReceived` | Transaction status result received |
| `TimeoutCallbackReceived` | Generic timeout received |
| `CallbackForwardingFailed` | Callback forwarding to client URL failed |

All events are in the `Harri\LaravelMpesa\Events` namespace.

## Error Responses

The package returns consistent JSON error responses:

**Validation error (422):**

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "error": "validation_error",
  "status": 422,
  "errors": {
    "Amount": ["The Amount field is required."]
  }
}
```

**Daraja error (400):**

```json
{
  "success": false,
  "message": "Invalid initiator information",
  "error": "mpesa_request_failed",
  "status": 400,
  "details": {
    "errorMessage": "Invalid initiator information"
  }
}
```

## Example Payloads

### STK Push

```json
{
  "amount": 100,
  "phone": "254712345678",
  "reference": "INV-1001",
  "callback_url": "https://client-app.test/api/payment-callback",
  "description": "Invoice payment"
}
```

### C2B Register

```json
{
  "ShortCode": "600000",
  "ResponseType": "Completed",
  "ConfirmationURL": "https://your-app.test/mpesa/callbacks/c2b/confirmation",
  "ValidationURL": "https://your-app.test/mpesa/callbacks/c2b/validation"
}
```

### B2C

```json
{
  "Amount": 100,
  "PartyB": "254712345678",
  "Remarks": "Withdrawal",
  "callback_url": "https://client-app.test/api/b2c-callback"
}
```

## Testing

Run tests locally:

```bash
composer test
```

The test suite covers STK flows, callback processing, B2C/B2B/reversal/balance/status operations, security middleware, rate limiting, HMAC validation, and error normalization.

CI runs automatically on push and pull request via [GitHub Actions](.github/workflows/tests.yml) against PHP 8.2 and 8.3 with MySQL.

## Contributing

Contributions are welcome. Please:

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure `composer test` passes
5. Submit a pull request

## License

This package is open-sourced software licensed under the [MIT License](LICENSE).
