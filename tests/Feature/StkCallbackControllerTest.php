<?php

namespace Harri\LaravelMpesa\Tests\Feature;

use Harri\LaravelMpesa\Jobs\ForwardMpesaCallbackJob;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

class StkCallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_stk_callback_and_creates_payment(): void
    {
        Bus::fake();

        StkPush::query()->create([
            'tracking_id' => 'track-123',
            'phone_number' => '254712345678',
            'amount' => '100',
            'invoice_number' => 'INV-1001',
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_123',
            'callback_url' => 'https://client-app.test/api/payment-callback',
            'status' => 0,
        ]);

        $payload = $this->successfulStkPayload();

        $response = $this->postJson('/mpesa/callbacks/stk', $payload);

        $response->assertOk()->assertJsonPath('ResultCode', 0);

        $this->assertDatabaseHas('mpesa_payments', [
            'trans_id' => 'NLJ7RT61SV',
            'bill_ref_number' => 'INV-1001',
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseHas('mpesa_stk_pushes', [
            'checkout_request_id' => 'ws_CO_123',
            'transaction_code' => 'NLJ7RT61SV',
            'status' => 1,
        ]);

        Bus::assertDispatched(ForwardMpesaCallbackJob::class);
        $this->assertInstanceOf(Payment::class, Payment::query()->first());
    }

    public function test_it_does_not_duplicate_payment_processing_for_repeat_stk_callbacks(): void
    {
        Bus::fake();

        StkPush::query()->create([
            'tracking_id' => 'track-123',
            'phone_number' => '254712345678',
            'amount' => '100',
            'invoice_number' => 'INV-1001',
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_123',
            'callback_url' => 'https://client-app.test/api/payment-callback',
            'status' => 0,
        ]);

        $payload = $this->successfulStkPayload();

        $this->postJson('/mpesa/callbacks/stk', $payload)->assertOk();
        $this->postJson('/mpesa/callbacks/stk', $payload)->assertOk();

        $this->assertSame(1, Payment::query()->count());
        Bus::assertDispatchedTimes(ForwardMpesaCallbackJob::class, 1);
    }

    public function test_it_assigns_callback_jobs_to_the_configured_connection_and_queue(): void
    {
        Bus::fake();

        config()->set('mpesa.callback_job_connection', 'redis-mpesa');
        config()->set('mpesa.callback_job_queue', 'mpesa-callbacks');

        StkPush::query()->create([
            'tracking_id' => 'track-123',
            'phone_number' => '254712345678',
            'amount' => '100',
            'invoice_number' => 'INV-1001',
            'merchant_request_id' => '29115-34620561-1',
            'checkout_request_id' => 'ws_CO_123',
            'callback_url' => 'https://client-app.test/api/payment-callback',
            'status' => 0,
        ]);

        $this->postJson('/mpesa/callbacks/stk', $this->successfulStkPayload())->assertOk();

        Bus::assertDispatched(ForwardMpesaCallbackJob::class, function (ForwardMpesaCallbackJob $job) {
            return $job->connection === 'redis-mpesa' && $job->queue === 'mpesa-callbacks';
        });
    }

    protected function successfulStkPayload(): array
    {
        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-34620561-1',
                    'CheckoutRequestID' => 'ws_CO_123',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 100],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                            ['Name' => 'TransactionDate', 'Value' => '20260316121212'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];
    }
}
