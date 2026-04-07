<?php

namespace Harri\LaravelMpesa\Tests\Unit;

use Harri\LaravelMpesa\Models\MpesaTransaction;
use Harri\LaravelMpesa\Models\Payment;
use Harri\LaravelMpesa\Models\StkPush;
use Harri\LaravelMpesa\Support\DefaultCallbackPayloadTransformer;
use PHPUnit\Framework\TestCase;

class DefaultCallbackPayloadTransformerTest extends TestCase
{
    public function test_it_transforms_stk_success_payloads(): void
    {
        $transformer = new DefaultCallbackPayloadTransformer();
        $stkPush = new StkPush([
            'invoice_number' => 'INV-1',
            'tracking_id' => 'track-1',
            'checkout_request_id' => 'checkout-1',
        ]);
        $payment = new Payment([
            'trans_id' => 'QAB123',
            'tracking_id' => 'track-1',
            'trans_amount' => '500',
            'business_short_code' => '174379',
            'bill_ref_number' => 'INV-1',
            'msisdn' => '254712345678',
            'path' => 'stk',
            'first_name' => 'Harri',
            'last_name' => 'Tester',
        ]);

        $payload = $transformer->transformStkSuccess($stkPush, $payment, ['raw' => true]);

        $this->assertSame(0, $payload['StatusCode']);
        $this->assertSame('QAB123', $payload['trans_code']);
        $this->assertSame('Harri Tester', $payload['name']);
    }

    public function test_it_transforms_transaction_result_payloads(): void
    {
        $transformer = new DefaultCallbackPayloadTransformer();
        $transaction = new MpesaTransaction([
            'type' => 'b2c',
            'conversation_id' => 'CONV-1',
            'originator_conversation_id' => 'ORIG-1',
            'transaction_id' => 'TRX-1',
            'reference' => 'INV-1',
            'amount' => '250',
            'phone_number' => '254712345678',
        ]);

        $payload = $transformer->transformTransactionResult($transaction, ['result' => true], 0, 'Success');

        $this->assertSame('b2c', $payload['type']);
        $this->assertSame('TRX-1', $payload['transaction_id']);
        $this->assertSame('Success', $payload['Message']);
    }
}

