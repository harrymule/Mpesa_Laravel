<?php

namespace Harri\LaravelMpesa\Tests\Feature;

use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class B2cEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_submits_b2c_and_logs_transaction(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/b2c/v3/paymentrequest' => Http::response([
                'ConversationID' => 'AG_20260316_00001',
                'OriginatorConversationID' => '12345-67890-1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Accept the service request successfully.',
            ]),
        ]);

        $response = $this->postJson('/daraja/b2c', [
            'OriginatorConversationID' => '600997_Test_32et3241ed8yu',
            'Amount' => 150,
            'PartyB' => '0712345678',
            'Remarks' => 'Withdrawal',
            'Occassion' => 'ChristmasPay',
            'callback_url' => 'https://client-app.test/api/b2c-callback',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation_id', 'AG_20260316_00001');

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'b2c',
            'conversation_id' => 'AG_20260316_00001',
            'callback_url' => 'https://client-app.test/api/b2c-callback',
            'status' => 'requested',
        ]);
    }
}



