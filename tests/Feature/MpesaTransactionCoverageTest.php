<?php

namespace Harri\LaravelMpesa\Tests\Feature;

use Harri\LaravelMpesa\Contracts\C2bValidationResponder;
use Harri\LaravelMpesa\Jobs\ForwardMpesaCallbackJob;
use Harri\LaravelMpesa\Models\MpesaErrorCode;
use Harri\LaravelMpesa\Models\MpesaTransaction;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Services\TransactionService;
use Harri\LaravelMpesa\Tests\Concerns\RefreshPackageDatabase;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

class MpesaTransactionCoverageTest extends TestCase
{
    use RefreshPackageDatabase;

    public function test_it_registers_c2b_urls_and_logs_the_request(): void
    {
        $this->fakeDarajaRequest('https://sandbox.safaricom.co.ke/mpesa/c2b/v2/registerurl', [
            'ConversationID' => 'AG_C2B_REGISTER_1',
            'OriginatorConversationID' => 'ORIG_C2B_REGISTER_1',
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success',
        ]);

        $response = $this->postJson('/daraja/c2b/register', [
            'ShortCode' => '600000',
            'ResponseType' => 'Completed',
            'ConfirmationURL' => 'https://client-app.test/c2b/confirmation',
            'ValidationURL' => 'https://client-app.test/c2b/validation',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation_id', 'AG_C2B_REGISTER_1');

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'c2b_register',
            'conversation_id' => 'AG_C2B_REGISTER_1',
            'status' => 'completed',
        ]);
    }

    public function test_it_simulates_c2b_and_logs_the_request(): void
    {
        $this->fakeDarajaRequest('https://sandbox.safaricom.co.ke/mpesa/c2b/v2/simulate', [
            'ConversationID' => 'AG_C2B_SIM_1',
            'OriginatorConversationID' => 'ORIG_C2B_SIM_1',
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]);

        $response = $this->postJson('/daraja/c2b/simulate', [
            'Amount' => 250,
            'Msisdn' => '0712345678',
            'BillRefNumber' => 'INV-2500',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation_id', 'AG_C2B_SIM_1');

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'c2b_simulate',
            'conversation_id' => 'AG_C2B_SIM_1',
            'status' => 'completed',
        ]);
    }

    public function test_it_logs_b2b_reversal_balance_and_transaction_status_requests(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/b2b/v1/paymentrequest' => Http::response([
                'ConversationID' => 'AG_B2B_1',
                'OriginatorConversationID' => 'ORIG_B2B_1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/reversal/v2/request' => Http::response([
                'ConversationID' => 'AG_REV_1',
                'OriginatorConversationID' => 'ORIG_REV_1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/accountbalance/v1/query' => Http::response([
                'ConversationID' => 'AG_BAL_1',
                'OriginatorConversationID' => 'ORIG_BAL_1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/transactionstatus/v1/query' => Http::response([
                'ConversationID' => 'AG_STATUS_1',
                'OriginatorConversationID' => 'ORIG_STATUS_1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
            ]),
        ]);

        $this->postJson('/daraja/b2b', [
            'Amount' => 300,
            'PartyB' => '600111',
            'AccountReference' => 'B2B-REF',
            'callback_url' => 'https://client-app.test/b2b-callback',
        ])->assertOk();

        $this->postJson('/daraja/reversal', [
            'TransactionID' => 'NLJ7RT61SV',
            'Amount' => 300,
            'callback_url' => 'https://client-app.test/reversal-callback',
        ])->assertOk();

        $this->postJson('/daraja/account-balance', [
            'callback_url' => 'https://client-app.test/balance-callback',
        ])->assertOk();

        $this->postJson('/daraja/transaction-status', [
            'TransactionID' => 'NLJ7RT61SV',
            'callback_url' => 'https://client-app.test/status-callback',
        ])->assertOk();

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'b2b',
            'conversation_id' => 'AG_B2B_1',
            'callback_url' => 'https://client-app.test/b2b-callback',
            'status' => 'requested',
        ]);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'reversal',
            'conversation_id' => 'AG_REV_1',
            'callback_url' => 'https://client-app.test/reversal-callback',
            'status' => 'requested',
        ]);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'account_balance',
            'conversation_id' => 'AG_BAL_1',
            'callback_url' => 'https://client-app.test/balance-callback',
            'status' => 'requested',
        ]);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'transaction_status',
            'conversation_id' => 'AG_STATUS_1',
            'callback_url' => 'https://client-app.test/status-callback',
            'status' => 'requested',
        ]);
    }

    public function test_it_supports_async_batch_transaction_requests(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'batch-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/b2c/v3/paymentrequest' => Http::response([
                'ConversationID' => 'AG_B2C_BATCH_1',
                'OriginatorConversationID' => 'ORIG_B2C_BATCH_1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/accountbalance/v1/query' => Http::response([
                'ConversationID' => 'AG_BAL_BATCH_1',
                'OriginatorConversationID' => 'ORIG_BAL_BATCH_1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success',
            ]),
        ]);

        $results = app(TransactionService::class)->batch([
            [
                'key' => 'b2c-1',
                'type' => 'b2c',
                'payload' => [
                    'Amount' => 120,
                    'PartyB' => '0712345678',
                    'Remarks' => 'Batch payout',
                ],
                'callback_url' => 'https://client-app.test/b2c-batch-callback',
            ],
            [
                'key' => 'balance-1',
                'type' => 'account_balance',
                'payload' => [],
                'callback_url' => 'https://client-app.test/balance-batch-callback',
            ],
        ]);

        $this->assertArrayHasKey('b2c-1', $results);
        $this->assertArrayHasKey('balance-1', $results);
        $this->assertSame('AG_B2C_BATCH_1', $results['b2c-1']['response']['ConversationID']);
        $this->assertSame('AG_BAL_BATCH_1', $results['balance-1']['response']['ConversationID']);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'b2c',
            'conversation_id' => 'AG_B2C_BATCH_1',
            'callback_url' => 'https://client-app.test/b2c-batch-callback',
        ]);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'account_balance',
            'conversation_id' => 'AG_BAL_BATCH_1',
            'callback_url' => 'https://client-app.test/balance-batch-callback',
        ]);
    }

    public function test_it_processes_c2b_confirmation_and_validation_callbacks(): void
    {
        $confirmation = [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'QAB123XYZ',
            'TransTime' => '20260316143000',
            'TransAmount' => 450,
            'BusinessShortCode' => '600000',
            'BillRefNumber' => 'INV-4500',
            'InvoiceNumber' => 'INV-4500',
            'OrgAccountBalance' => '1000.00',
            'ThirdPartyTransID' => 'TP-123',
            'MSISDN' => '254712345678',
            'FirstName' => 'Harry',
            'MiddleName' => 'M',
            'LastName' => 'Doe',
        ];

        $validation = [
            'TransID' => 'QAB124XYZ',
            'TransAmount' => 120,
            'BillRefNumber' => 'INV-1200',
            'MSISDN' => '254700000001',
        ];

        $this->postJson('/daraja/callbacks/c2b/confirmation', $confirmation)
            ->assertOk()
            ->assertJsonPath('ResultCode', 0);

        $this->postJson('/daraja/callbacks/c2b/validation', $validation)
            ->assertOk()
            ->assertJsonPath('ResultCode', 0);

        $this->assertDatabaseHas('mpesa_payments', [
            'trans_id' => 'QAB123XYZ',
            'bill_ref_number' => 'INV-4500',
            'path' => 'c2b',
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'c2b_confirmation',
            'transaction_id' => 'QAB123XYZ',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'c2b_validation',
            'transaction_id' => 'QAB124XYZ',
            'status' => 'validated',
        ]);
    }

    public function test_c2b_confirmation_is_idempotent_for_duplicate_callbacks(): void
    {
        $payload = [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'QAB123XYZ',
            'TransTime' => '20260316143000',
            'TransAmount' => 450,
            'BusinessShortCode' => '600000',
            'BillRefNumber' => 'INV-4500',
            'InvoiceNumber' => 'INV-4500',
            'MSISDN' => '254712345678',
        ];

        $this->postJson('/daraja/callbacks/c2b/confirmation', $payload)->assertOk();
        $this->postJson('/daraja/callbacks/c2b/confirmation', $payload)->assertOk();

        $this->assertSame(1, Payment::query()->where('trans_id', 'QAB123XYZ')->count());
        $this->assertSame(1, MpesaTransaction::query()->where('type', 'c2b_confirmation')->where('transaction_id', 'QAB123XYZ')->count());
    }

    public function test_c2b_validation_can_be_rejected_by_a_custom_responder(): void
    {
        app()->bind(C2bValidationResponder::class, fn () => new class implements C2bValidationResponder {
            public function respond(array $payload): array
            {
                return [
                    'ResultCode' => 'C2B00011',
                    'ResultDesc' => 'Rejected by merchant rule',
                ];
            }
        });

        config()->set('mpesa.c2b.validation_responder', C2bValidationResponder::class);

        $response = $this->postJson('/daraja/callbacks/c2b/validation', [
            'TransID' => 'QAB125XYZ',
            'TransAmount' => 50,
            'BillRefNumber' => 'INV-BLOCKED',
            'MSISDN' => '254700000002',
        ]);

        $response->assertOk()
            ->assertJsonPath('ResultCode', 'C2B00011')
            ->assertJsonPath('ResultDesc', 'Rejected by merchant rule');

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'c2b_validation',
            'transaction_id' => 'QAB125XYZ',
            'status' => 'rejected',
        ]);
    }

    public function test_it_processes_result_and_timeout_callbacks_for_non_stk_flows(): void
    {
        Bus::fake();

        MpesaTransaction::query()->create([
            'type' => 'b2b',
            'status' => 'requested',
            'conversation_id' => 'AG_B2B_2',
            'originator_conversation_id' => 'ORIG_B2B_2',
            'callback_url' => 'https://client-app.test/b2b-callback',
        ]);

        MpesaTransaction::query()->create([
            'type' => 'reversal',
            'status' => 'requested',
            'conversation_id' => 'AG_REV_2',
            'originator_conversation_id' => 'ORIG_REV_2',
            'callback_url' => 'https://client-app.test/reversal-callback',
        ]);

        $this->postJson('/daraja/callbacks/b2b/result', [
            'Result' => [
                'ConversationID' => 'AG_B2B_2',
                'OriginatorConversationID' => 'ORIG_B2B_2',
                'TransactionID' => 'B2BTRX001',
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
            ],
        ])->assertOk()->assertJsonPath('ResultCode', 0);

        $this->postJson('/daraja/callbacks/timeout', [
            'ConversationID' => 'AG_REV_2',
            'OriginatorConversationID' => 'ORIG_REV_2',
        ])->assertOk()->assertJsonPath('ResultCode', 0);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'b2b',
            'conversation_id' => 'AG_B2B_2',
            'transaction_id' => 'B2BTRX001',
            'status' => 'completed',
            'result_code' => '0',
        ]);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'reversal',
            'conversation_id' => 'AG_REV_2',
            'status' => 'timeout',
            'result_desc' => 'Timed out',
        ]);

        $this->assertDatabaseHas('mpesa_error_codes', [
            'journey' => 'reversal',
            'error_stage' => 'timeout',
            'code' => '1037',
        ]);

        Bus::assertDispatched(ForwardMpesaCallbackJob::class);
    }

    public function test_it_catalogs_b2c_callback_result_failures_by_stage(): void
    {
        Bus::fake();

        MpesaTransaction::query()->create([
            'type' => 'b2c',
            'status' => 'requested',
            'conversation_id' => 'AG_B2C_2',
            'originator_conversation_id' => 'ORIG_B2C_2',
            'callback_url' => 'https://client-app.test/b2c-callback',
        ]);

        $this->postJson('/daraja/callbacks/b2c/result', [
            'Result' => [
                'ConversationID' => 'AG_B2C_2',
                'OriginatorConversationID' => 'ORIG_B2C_2',
                'TransactionID' => 'B2CTRX001',
                'ResultCode' => 2001,
                'ResultDesc' => 'The initiator information is invalid.',
            ],
        ])->assertOk()->assertJsonPath('ResultCode', 0);

        $this->assertDatabaseHas('mpesa_transactions', [
            'type' => 'b2c',
            'conversation_id' => 'AG_B2C_2',
            'transaction_id' => 'B2CTRX001',
            'status' => 'failed',
            'result_code' => '2001',
        ]);

        $this->assertDatabaseHas('mpesa_error_codes', [
            'journey' => 'b2c',
            'error_stage' => 'callback_result',
            'code' => '2001',
            'error_key' => 'mpesa_b2c_initiator_information_invalid',
            'is_known' => 1,
        ]);
    }

    protected function fakeDarajaRequest(string $url, array $response): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => '3600',
            ]),
            $url => Http::response($response),
        ]);
    }
}

