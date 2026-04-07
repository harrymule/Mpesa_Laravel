<?php

namespace Harri\LaravelMpesa\Tests\Feature;

use Harri\LaravelMpesa\Tests\TestCase;
use Harri\LaravelMpesa\Tests\Concerns\RefreshPackageDatabase;
use Illuminate\Support\Facades\Http;

class QrCodeEndpointTest extends TestCase
{
    use RefreshPackageDatabase;

    public function test_it_returns_qr_specific_known_errors(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/qrcode/v1/generate' => Http::response([
                'errorCode' => '400.002.05',
                'errorMessage' => 'Invalid Request Payload',
            ], 400),
        ]);

        $response = $this->postJson('/daraja/qr-code', [
            'MerchantName' => 'TEST SUPERMARKET',
            'RefNo' => 'Invoice Test',
            'Amount' => 1,
            'TrxCode' => 'BG',
            'CPI' => '373132',
            'Size' => '300',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('error', 'mpesa_qr_invalid_request_payload')
            ->assertJsonPath('details.journey', 'qr')
            ->assertJsonPath('details.error_stage', 'request')
            ->assertJsonPath('details.mpesa_error_code', '400.002.05');
    }

    public function test_it_generates_a_qr_code_and_logs_the_transaction(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/qrcode/v1/generate' => Http::response([
                'ResponseCode' => 'AG_20191219_000043fdf61864fe9ff5',
                'RequestID' => '16738-27456357-1',
                'ResponseDescription' => 'QR Code Successfully Generated.',
                'QRCode' => 'iVBORw0KGgoAAAANSUhEUgAAASwAAAEsCAIAAAD2Hxki',
            ]),
        ]);

        $response = $this->postJson('/daraja/qr-code', [
            'MerchantName' => 'TEST SUPERMARKET',
            'RefNo' => 'Invoice Test',
            'Amount' => 1,
            'TrxCode' => 'BG',
            'CPI' => '373132',
            'Size' => '300',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('request_id', '16738-27456357-1')
            ->assertJsonPath('response_code', 'AG_20191219_000043fdf61864fe9ff5')
            ->assertJsonPath('response.QRCode', 'iVBORw0KGgoAAAANSUhEUgAAASwAAAEsCAIAAAD2Hxki');

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'qr',
            'status' => 'completed',
            'reference' => 'Invoice Test',
            'amount' => '1',
            'transaction_id' => '16738-27456357-1',
            'result_desc' => 'QR Code Successfully Generated.',
        ]);
    }
}

