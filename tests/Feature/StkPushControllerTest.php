<?php

namespace Harri\LaravelMpesa\Tests\Feature;

use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class StkPushControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_initiates_and_persists_an_stk_push(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => '29115-34620561-1',
                'CheckoutRequestID' => 'ws_CO_123',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
        ]);

        $response = $this->postJson('/mpesa/stk-push', [
            'amount' => 100,
            'phone' => '0712345678',
            'reference' => 'INV-1001',
            'callback_url' => 'https://client-app.test/api/payment-callback',
            'description' => 'Invoice payment',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('checkout_request_id', 'ws_CO_123');

        $this->assertDatabaseHas('mpesa_stk_pushes', [
            'checkout_request_id' => 'ws_CO_123',
            'invoice_number' => 'INV-1001',
            'callback_url' => 'https://client-app.test/api/payment-callback',
        ]);

        $this->assertSame('254712345678', StkPush::query()->firstOrFail()->phone_number);
    }
}
