<?php

use Harri\LaravelMpesa\Models\MpesaTransaction;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\Support\AcceptC2bValidation;
use Harri\LaravelMpesa\Support\DefaultCallbackPayloadTransformer;

return [
    'default' => env('MPESA_DEFAULT', 'sandbox'),
    'log_channel' => env('MPESA_LOG_CHANNEL', 'stack'),
    'log_channels' => [
        'default' => env('MPESA_LOG_CHANNEL', 'stack'),
        'oauth' => env('MPESA_LOG_CHANNEL_OAUTH', env('MPESA_LOG_CHANNEL', 'stack')),
        'stk' => env('MPESA_LOG_CHANNEL_STK', env('MPESA_LOG_CHANNEL', 'stack')),
        'stk_query' => env('MPESA_LOG_CHANNEL_STK_QUERY', env('MPESA_LOG_CHANNEL_STK', env('MPESA_LOG_CHANNEL', 'stack'))),
        'c2b' => env('MPESA_LOG_CHANNEL_C2B', env('MPESA_LOG_CHANNEL', 'stack')),
        'b2c' => env('MPESA_LOG_CHANNEL_B2C', env('MPESA_LOG_CHANNEL', 'stack')),
        'b2b' => env('MPESA_LOG_CHANNEL_B2B', env('MPESA_LOG_CHANNEL', 'stack')),
        'reversal' => env('MPESA_LOG_CHANNEL_REVERSAL', env('MPESA_LOG_CHANNEL', 'stack')),
        'account_balance' => env('MPESA_LOG_CHANNEL_ACCOUNT_BALANCE', env('MPESA_LOG_CHANNEL', 'stack')),
        'transaction_status' => env('MPESA_LOG_CHANNEL_TRANSACTION_STATUS', env('MPESA_LOG_CHANNEL', 'stack')),
        'qr' => env('MPESA_LOG_CHANNEL_QR', env('MPESA_LOG_CHANNEL', 'stack')),
        'forwarding' => env('MPESA_LOG_CHANNEL_FORWARDING', env('MPESA_LOG_CHANNEL', 'stack')),
        'callback' => env('MPESA_LOG_CHANNEL_CALLBACK', env('MPESA_LOG_CHANNEL', 'stack')),
        'security' => env('MPESA_LOG_CHANNEL_SECURITY', env('MPESA_LOG_CHANNEL', 'stack')),
    ],
    'route_prefix' => env('MPESA_ROUTE_PREFIX', 'daraja'),
    'route_middleware' => ['api'],
    'initiation_route_middleware' => array_values(array_filter(array_map('trim', explode(',', env('MPESA_INITIATION_ROUTE_MIDDLEWARE', 'api,throttle:mpesa.initiation,mpesa.initiation.auth'))))),
    'callback_route_middleware' => array_values(array_filter(array_map('trim', explode(',', env('MPESA_CALLBACK_ROUTE_MIDDLEWARE', 'api,mpesa.callback.auth'))))),
    'load_routes' => env('MPESA_LOAD_ROUTES', true),
    'load_initiation_routes' => env('MPESA_LOAD_INITIATION_ROUTES', true),
    'load_callback_routes' => env('MPESA_LOAD_CALLBACK_ROUTES', true),
    'load_migrations' => env('MPESA_LOAD_MIGRATIONS', true),
    'dispatch_callback_job' => env('MPESA_DISPATCH_CALLBACK_JOB', true),
    'callback_job_connection' => env('MPESA_CALLBACK_JOB_CONNECTION'),
    'callback_job_queue' => env('MPESA_CALLBACK_JOB_QUEUE'),
    'shortcode' => env('MPESA_SHORTCODE'),
    'store_number' => env('MPESA_STORE_NUMBER'),
    'initiator_name' => env('MPESA_INITIATOR_NAME'),
    'initiator_password' => env('MPESA_INITIATOR_PASSWORD'),
    'passkey' => env('MPESA_PASSKEY'),
    'result_url' => env('MPESA_RESULT_URL'),
    'timeout_url' => env('MPESA_TIMEOUT_URL'),
    'callback_url' => env('MPESA_CALLBACK_URL'),
    'confirmation_url' => env('MPESA_CONFIRMATION_URL'),
    'validation_url' => env('MPESA_VALIDATION_URL'),
    'queue_timeout_url' => env('MPESA_QUEUE_TIMEOUT_URL'),
    'security_certificate_path' => env('MPESA_SECURITY_CERTIFICATE_PATH'),
    'oauth' => [
        'cache_prefix' => env('MPESA_OAUTH_CACHE_PREFIX', 'mpesa_oauth_token'),
        'ttl' => (int) env('MPESA_OAUTH_CACHE_TTL', 3500),
    ],
    'rate_limit' => [
        'enabled' => env('MPESA_INITIATION_RATE_LIMIT_ENABLED', true),
        'name' => env('MPESA_INITIATION_RATE_LIMIT_NAME', 'mpesa.initiation'),
        'max_attempts' => (int) env('MPESA_INITIATION_RATE_LIMIT_MAX_ATTEMPTS', 60),
        'decay_seconds' => (int) env('MPESA_INITIATION_RATE_LIMIT_DECAY_SECONDS', 60),
    ],
    'security' => [
        'initiation_token' => env('MPESA_INITIATION_TOKEN'),
        'callback_secret' => env('MPESA_CALLBACK_SECRET'),
        'trusted_ips' => array_values(array_filter(array_map('trim', explode(',', env('MPESA_TRUSTED_CALLBACK_IPS', ''))))),
        'callback_hmac' => [
            'enabled' => env('MPESA_CALLBACK_HMAC_ENABLED', false),
            'secret' => env('MPESA_CALLBACK_HMAC_SECRET'),
            'header' => env('MPESA_CALLBACK_HMAC_HEADER', 'X-Mpesa-Signature'),
            'algorithm' => env('MPESA_CALLBACK_HMAC_ALGORITHM', 'sha256'),
            'encoding' => env('MPESA_CALLBACK_HMAC_ENCODING', 'hex'),
            'required' => env('MPESA_CALLBACK_HMAC_REQUIRED', false),
        ],
    ],
    'models' => [
        'stk_push' => StkPush::class,
        'payment' => Payment::class,
        'transaction' => MpesaTransaction::class,
    ],
    'transformers' => [
        'callback_payload' => DefaultCallbackPayloadTransformer::class,
    ],
    'c2b' => [
        'validation_responder' => AcceptC2bValidation::class,
        'fallback' => [
            'enabled' => env('MPESA_C2B_FALLBACK_ENABLED', false),
            'shortcode' => env('MPESA_C2B_FALLBACK_SHORTCODE', env('MPESA_SHORTCODE')),
            'instructions' => env('MPESA_C2B_FALLBACK_INSTRUCTIONS', 'Complete payment via M-Pesa PayBill and use the provided account reference.'),
        ],
    ],
    'stk' => [
        'callback_url' => env('MPESA_STK_CALLBACK_URL'),
        'default_description' => env('MPESA_STK_DESCRIPTION', 'STK Push Payment'),
        'default_reference' => env('MPESA_STK_REFERENCE', 'Payment'),
    ],
    'qr' => [
        'generate_uri' => env('MPESA_QR_GENERATE_URI', '/mpesa/qrcode/v1/generate'),
        'default_size' => (string) env('MPESA_QR_DEFAULT_SIZE', '300'),
    ],
    'b2c' => [
        'payment_uri' => env('MPESA_B2C_PAYMENT_URI', '/mpesa/b2c/v3/paymentrequest'),
    ],
    'connections' => [
        'sandbox' => [
            'base_url' => env('MPESA_SANDBOX_BASE_URL', 'https://sandbox.safaricom.co.ke'),
            'consumer_key' => env('MPESA_SANDBOX_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_SANDBOX_CONSUMER_SECRET'),
            'security_credential' => env('MPESA_SANDBOX_SECURITY_CREDENTIAL'),
        ],
        'live' => [
            'base_url' => env('MPESA_LIVE_BASE_URL', 'https://api.safaricom.co.ke'),
            'consumer_key' => env('MPESA_LIVE_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_LIVE_CONSUMER_SECRET'),
            'security_credential' => env('MPESA_LIVE_SECURITY_CREDENTIAL'),
        ],
    ],
];
