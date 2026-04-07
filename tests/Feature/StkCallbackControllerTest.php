<?php

namespace Harri\LaravelMpesa\Tests\Feature;

use Harri\LaravelMpesa\Jobs\ForwardMpesaCallbackJob;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

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

        $response = $this->postJson('/daraja/callbacks/stk', $payload);

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

    public function test_it_catalogs_known_failed_stk_callback_results(): void
    {
        Bus::fake();

        StkPush::query()->create([
            'tracking_id' => 'track-124',
            'phone_number' => '254712345678',
            'amount' => '100',
            'invoice_number' => 'INV-1002',
            'merchant_request_id' => 'f1e2-4b95-a71d-b30d3cdbb7a7942864',
            'checkout_request_id' => 'ws_CO_21072024125243250722943992',
            'callback_url' => 'https://client-app.test/api/payment-callback',
            'status' => 0,
        ]);

        $response = $this->postJson('/daraja/callbacks/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'f1e2-4b95-a71d-b30d3cdbb7a7942864',
                    'CheckoutRequestID' => 'ws_CO_21072024125243250722943992',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ]);

        $response->assertOk()->assertJsonPath('ResultCode', 0);

        $this->assertDatabaseHas('mpesa_error_codes', [
            'journey' => 'stk',
            'error_stage' => 'callback_result',
            'code' => '1032',
            'error_key' => 'mpesa_stk_request_cancelled_by_user',
            'is_known' => 1,
        ]);
    }

    public function test_it_routes_stk_callback_logs_to_the_stk_channel_when_configured(): void
    {
        config()->set('mpesa.log_channel', 'mpesa-default');
        config()->set('mpesa.log_channels.stk', 'mpesa-stk');

        Log::shouldReceive('channel')->once()->with('mpesa-stk')->andReturnSelf();
        Log::shouldReceive('info')->once()->with('mpesa.stk.callback', $this->successfulStkPayload());

        $this->postJson('/daraja/callbacks/stk', $this->successfulStkPayload())
            ->assertOk()
            ->assertJsonPath('ResultCode', 0);
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

        $this->postJson('/daraja/callbacks/stk', $payload)->assertOk();
        $this->postJson('/daraja/callbacks/stk', $payload)->assertOk();

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

        $this->postJson('/daraja/callbacks/stk', $this->successfulStkPayload())->assertOk();

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





