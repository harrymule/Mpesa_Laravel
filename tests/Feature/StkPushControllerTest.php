<?php

namespace Harri\LaravelMpesa\Tests\Feature;

use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\Tests\Concerns\RefreshPackageDatabase;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class StkPushControllerTest extends TestCase
{
    use RefreshPackageDatabase;

    public function test_it_maps_known_stk_request_errors_to_the_stk_journey(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'errorCode' => '500.001.1001',
                'errorMessage' => 'Wrong credentials',
            ], 500),
        ]);

        $response = $this->postJson('/daraja/stk-push', [
            'amount' => 100,
            'phone' => '0712345678',
            'reference' => 'INV-1001',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('error', 'mpesa_stk_wrong_credentials')
            ->assertJsonPath('details.journey', 'stk')
            ->assertJsonPath('details.error_stage', 'request')
            ->assertJsonPath('details.mpesa_error_code', '500.001.1001');

        $this->assertDatabaseHas('mpesa_error_codes', [
            'journey' => 'stk',
            'error_stage' => 'request',
            'code' => '500.001.1001',
            'error_key' => 'mpesa_stk_wrong_credentials',
            'is_known' => 1,
        ]);
    }

    public function test_it_queries_an_stk_push_and_updates_the_record(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'The service request has been accepted successfully',
                'MerchantRequestID' => '22205-34066-1',
                'CheckoutRequestID' => 'ws_CO_13012021093521236557',
                'ResultCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);

        StkPush::query()->create([
            'tracking_id' => 'track-query-1',
            'phone_number' => '254712345678',
            'amount' => '100',
            'invoice_number' => 'INV-1001',
            'checkout_request_id' => 'ws_CO_13012021093521236557',
            'status' => 0,
        ]);

        $response = $this->postJson('/daraja/stk-push/query', [
            'checkout_request_id' => 'ws_CO_13012021093521236557',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('checkout_request_id', 'ws_CO_13012021093521236557')
            ->assertJsonPath('result_code', '0');

        $this->assertDatabaseHas('mpesa_stk_pushes', [
            'checkout_request_id' => 'ws_CO_13012021093521236557',
            'merchant_request_id' => '22205-34066-1',
            'response_code' => '0',
            'internal_comment' => 'The service request is processed successfully.',
            'status' => 1,
        ]);
    }

    public function test_it_catalogs_failed_stk_queries_by_stage(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'The service request has been accepted successfully',
                'MerchantRequestID' => '22205-34066-1',
                'CheckoutRequestID' => 'ws_CO_13012021093521236557',
                'ResultCode' => '1032',
                'ResultDesc' => 'Request cancelled by user',
            ]),
        ]);

        StkPush::query()->create([
            'tracking_id' => 'track-query-2',
            'phone_number' => '254712345678',
            'amount' => '100',
            'invoice_number' => 'INV-1002',
            'checkout_request_id' => 'ws_CO_13012021093521236557',
            'status' => 0,
        ]);

        $response = $this->postJson('/daraja/stk-push/query', [
            'checkout_request_id' => 'ws_CO_13012021093521236557',
        ]);

        $response->assertOk()
            ->assertJsonPath('result_code', '1032')
            ->assertJsonPath('result_desc', 'Request cancelled by user');

        $this->assertDatabaseHas('mpesa_error_codes', [
            'journey' => 'stk_query',
            'error_stage' => 'query_result',
            'code' => '1032',
            'error_key' => 'mpesa_stk_query_request_cancelled_by_user',
            'is_known' => 1,
        ]);
    }

    public function test_it_returns_stk_push_status_by_tracking_id(): void
    {
        $stkPush = StkPush::query()->create([
            'tracking_id' => 'track-status-1',
            'phone_number' => '254712345678',
            'amount' => '100',
            'invoice_number' => 'INV-1003',
            'checkout_request_id' => 'ws_CO_status_1',
            'response_code' => '0',
            'status' => 1,
        ]);

        $payment = Payment::query()->create([
            'stk_push_id' => $stkPush->id,
            'tracking_id' => $stkPush->tracking_id,
            'trans_id' => 'NLJ7RT61SV',
            'trans_amount' => '100',
            'bill_ref_number' => 'INV-1003',
            'invoice_number' => 'INV-1003',
            'msisdn' => '254712345678',
            'status' => 'COMPLETED',
            'path' => 'stk',
        ]);

        $stkPush->update([
            'payment_id' => $payment->id,
            'transaction_code' => 'NLJ7RT61SV',
        ]);

        $this->getJson('/daraja/stk-push/status/track-status-1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('receipt_number', 'NLJ7RT61SV');
    }

    public function test_it_returns_paybill_fallback_instructions_when_enabled(): void
    {
        config()->set('mpesa.c2b.fallback.enabled', true);
        config()->set('mpesa.c2b.fallback.shortcode', '400200');
        config()->set('mpesa.c2b.fallback.instructions', 'Use the provided PayBill details to complete payment.');

        StkPush::query()->create([
            'tracking_id' => 'track-paybill-1',
            'phone_number' => '254712345678',
            'amount' => '250',
            'invoice_number' => 'PP-A1B2C3D4',
            'checkout_request_id' => 'ws_CO_paybill_1',
            'status' => 0,
        ]);

        $this->getJson('/daraja/stk-push/paybill/track-paybill-1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('shortcode', '400200')
            ->assertJsonPath('account_reference', 'PP-A1B2C3D4')
            ->assertJsonPath('amount', '250');
    }

    public function test_it_verifies_manual_payment_and_links_it_to_the_tracking_record(): void
    {
        $stkPush = StkPush::query()->create([
            'tracking_id' => 'track-manual-1',
            'phone_number' => '254712345678',
            'amount' => '300',
            'invoice_number' => 'INV-1004',
            'checkout_request_id' => 'ws_CO_manual_1',
            'status' => 0,
        ]);

        Payment::query()->create([
            'trans_id' => 'MANUAL123',
            'trans_amount' => '300',
            'bill_ref_number' => 'INV-1004',
            'invoice_number' => 'INV-1004',
            'msisdn' => '254712345678',
            'status' => 'COMPLETED',
            'path' => 'c2b',
        ]);

        $this->postJson('/daraja/stk-push/verify-manual', [
            'receipt_number' => 'MANUAL123',
            'tracking_id' => 'track-manual-1',
            'phone' => '0712345678',
            'amount' => 300,
            'reference' => 'INV-1004',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('payment.trans_id', 'MANUAL123');

        $this->assertDatabaseHas('mpesa_stk_pushes', [
            'tracking_id' => 'track-manual-1',
            'payment_id' => 1,
            'transaction_code' => 'MANUAL123',
            'status' => 1,
        ]);
    }

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

        $response = $this->postJson('/daraja/stk-push', [
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
