<?php

namespace Harri\LaravelMpesa\Tests\Feature;

use Harri\LaravelMpesa\Models\MpesaErrorCode;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class PackageHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiation_routes_can_be_protected_with_middleware(): void
    {
        putenv('MPESA_INITIATION_ROUTE_MIDDLEWARE=api,mpesa.test.deny');
        $_ENV['MPESA_INITIATION_ROUTE_MIDDLEWARE'] = 'api,mpesa.test.deny';
        $_SERVER['MPESA_INITIATION_ROUTE_MIDDLEWARE'] = 'api,mpesa.test.deny';

        $this->refreshApplication();

        $response = $this->postJson('/daraja/stk-push', [
            'amount' => 100,
            'phone' => '0712345678',
            'reference' => 'INV-1001',
        ]);

        $response->assertStatus(401);

        putenv('MPESA_INITIATION_ROUTE_MIDDLEWARE');
        unset($_ENV['MPESA_INITIATION_ROUTE_MIDDLEWARE'], $_SERVER['MPESA_INITIATION_ROUTE_MIDDLEWARE']);
        $this->refreshApplication();
    }

    public function test_initiation_routes_are_rate_limited(): void
    {
        config()->set('mpesa.rate_limit.max_attempts', 2);
        config()->set('mpesa.rate_limit.decay_seconds', 60);

        $server = ['REMOTE_ADDR' => '10.10.10.10'];

        $this->withServerVariables($server)->postJson('/daraja/stk-push', [])->assertStatus(422);
        $this->withServerVariables($server)->postJson('/daraja/stk-push', [])->assertStatus(422);
        $this->withServerVariables($server)->postJson('/daraja/stk-push', [])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'mpesa_rate_limited');
    }

    public function test_it_returns_normalized_validation_errors(): void
    {
        $response = $this->postJson('/daraja/b2c', []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'validation_error')
            ->assertJsonStructure([
                'success',
                'message',
                'error',
                'errors',
            ]);
    }

    public function test_it_returns_normalized_mpesa_request_errors(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/b2c/v3/paymentrequest' => Http::response([
                'errorMessage' => 'Invalid initiator information',
            ], 400),
        ]);

        $response = $this->postJson('/daraja/b2c', [
            'Amount' => 150,
            'PartyB' => '0712345678',
            'Remarks' => 'Withdrawal',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'mpesa_request_failed')
            ->assertJsonPath('details.journey', 'b2c')
            ->assertJsonPath('details.error_stage', 'request');
    }

    public function test_it_records_known_mpesa_errors_in_the_catalog_with_journey(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/c2b/v2/registerurl' => Http::response([
                'errorCode' => '500.003.1001',
                'errorMessage' => 'Urls are already registered.',
            ], 500),
        ]);

        $response = $this->postJson('/daraja/c2b/register', [
            'ShortCode' => '600000',
            'ResponseType' => 'Completed',
            'ConfirmationURL' => 'https://client-app.test/daraja/callbacks/c2b/confirmation',
            'ValidationURL' => 'https://client-app.test/daraja/callbacks/c2b/validation',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('error', 'mpesa_c2b_urls_already_registered')
            ->assertJsonPath('details.mpesa_error_code', '500.003.1001')
            ->assertJsonPath('details.known_error', true)
            ->assertJsonPath('details.journey', 'c2b')
            ->assertJsonPath('details.error_stage', 'request');

        $this->assertDatabaseHas('mpesa_error_codes', [
            'code' => '500.003.1001',
            'journey' => 'c2b',
            'error_stage' => 'request',
            'error_key' => 'mpesa_c2b_urls_already_registered',
            'is_known' => 1,
        ]);
    }

    public function test_it_records_unknown_mpesa_errors_in_the_catalog_with_journey(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/c2b/v2/registerurl' => Http::response([
                'errorCode' => '999.999.99',
                'errorMessage' => 'Brand new Daraja failure',
            ], 500),
        ]);

        $response = $this->postJson('/daraja/c2b/register', [
            'ShortCode' => '600000',
            'ResponseType' => 'Completed',
            'ConfirmationURL' => 'https://client-app.test/daraja/callbacks/c2b/confirmation',
            'ValidationURL' => 'https://client-app.test/daraja/callbacks/c2b/validation',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('error', 'mpesa_request_failed')
            ->assertJsonPath('details.mpesa_error_code', '999.999.99')
            ->assertJsonPath('details.known_error', false)
            ->assertJsonPath('details.journey', 'c2b')
            ->assertJsonPath('details.error_stage', 'request');

        $record = MpesaErrorCode::query()
            ->where('code', '999.999.99')
            ->where('journey', 'c2b')
            ->where('error_stage', 'request')
            ->first();

        $this->assertNotNull($record);
        $this->assertSame('Brand new Daraja failure', $record->message);
        $this->assertSame('c2b', $record->journey);
        $this->assertSame('request', $record->error_stage);
        $this->assertFalse($record->is_known);
        $this->assertSame(1, $record->occurrences);
    }

    public function test_the_same_error_code_can_be_tracked_separately_per_journey(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/c2b/v2/registerurl' => Http::response([
                'errorCode' => '500.003.1001',
                'errorMessage' => 'Urls are already registered.',
            ], 500),
            'https://sandbox.safaricom.co.ke/mpesa/b2c/v3/paymentrequest' => Http::response([
                'errorCode' => '500.003.1001',
                'errorMessage' => 'Internal Server Error',
            ], 500),
        ]);

        $this->postJson('/daraja/c2b/register', [
            'ShortCode' => '600000',
            'ResponseType' => 'Completed',
            'ConfirmationURL' => 'https://client-app.test/daraja/callbacks/c2b/confirmation',
            'ValidationURL' => 'https://client-app.test/daraja/callbacks/c2b/validation',
        ])->assertStatus(500);

        $this->postJson('/daraja/b2c', [
            'Amount' => 150,
            'PartyB' => '0712345678',
            'Remarks' => 'Withdrawal',
        ])->assertStatus(500);

        $this->assertDatabaseHas('mpesa_error_codes', [
            'code' => '500.003.1001',
            'journey' => 'c2b',
            'error_stage' => 'request',
            'error_key' => 'mpesa_c2b_urls_already_registered',
        ]);

        $this->assertDatabaseHas('mpesa_error_codes', [
            'code' => '500.003.1001',
            'journey' => 'b2c',
            'error_stage' => 'request',
            'error_key' => 'mpesa_internal_server_error',
        ]);
    }

    public function test_callback_hmac_signature_can_be_required(): void
    {
        config()->set('mpesa.security.callback_hmac.enabled', true);
        config()->set('mpesa.security.callback_hmac.required', true);
        config()->set('mpesa.security.callback_hmac.secret', 'callback-signing-secret');
        config()->set('mpesa.security.callback_hmac.header', 'X-Mpesa-Signature');

        $payload = ['TransID' => 'TEST123', 'MSISDN' => '254712345678'];

        $this->postJson('/daraja/callbacks/c2b/validation', $payload)
            ->assertStatus(403)
            ->assertJsonPath('error', 'mpesa_callback_signature_missing');
    }

    public function test_callback_hmac_signature_is_validated_when_present(): void
    {
        config()->set('mpesa.security.callback_hmac.enabled', true);
        config()->set('mpesa.security.callback_hmac.required', true);
        config()->set('mpesa.security.callback_hmac.secret', 'callback-signing-secret');
        config()->set('mpesa.security.callback_hmac.header', 'X-Mpesa-Signature');
        config()->set('mpesa.dispatch_callback_job', false);

        $json = json_encode([
            'TransID' => 'TEST123',
            'MSISDN' => '254712345678',
            'BillRefNumber' => 'INV-1',
            'TransAmount' => 100,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $json, 'callback-signing-secret');

        $this->call(
            'POST',
            '/daraja/callbacks/c2b/validation',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_MPESA_SIGNATURE' => $signature,
            ],
            $json,
        )
            ->assertStatus(200)
            ->assertJsonPath('ResultCode', 0);
    }
}

