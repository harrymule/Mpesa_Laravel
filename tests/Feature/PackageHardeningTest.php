<?php

namespace Harri\LaravelMpesa\Tests\Feature;

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

        $response = $this->postJson('/mpesa/stk-push', [
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

        $this->withServerVariables($server)->postJson('/mpesa/stk-push', [])->assertStatus(422);
        $this->withServerVariables($server)->postJson('/mpesa/stk-push', [])->assertStatus(422);
        $this->withServerVariables($server)->postJson('/mpesa/stk-push', [])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'mpesa_rate_limited');
    }

    public function test_it_returns_normalized_validation_errors(): void
    {
        $response = $this->postJson('/mpesa/b2c', []);

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
            'https://sandbox.safaricom.co.ke/mpesa/b2c/v1/paymentrequest' => Http::response([
                'errorMessage' => 'Invalid initiator information',
            ], 400),
        ]);

        $response = $this->postJson('/mpesa/b2c', [
            'Amount' => 150,
            'PartyB' => '0712345678',
            'Remarks' => 'Withdrawal',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'mpesa_request_failed');
    }

    public function test_callback_hmac_signature_can_be_required(): void
    {
        config()->set('mpesa.security.callback_hmac.enabled', true);
        config()->set('mpesa.security.callback_hmac.required', true);
        config()->set('mpesa.security.callback_hmac.secret', 'callback-signing-secret');
        config()->set('mpesa.security.callback_hmac.header', 'X-Mpesa-Signature');

        $payload = ['TransID' => 'TEST123', 'MSISDN' => '254712345678'];

        $this->postJson('/mpesa/callbacks/c2b/validation', $payload)
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
            '/mpesa/callbacks/c2b/validation',
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
