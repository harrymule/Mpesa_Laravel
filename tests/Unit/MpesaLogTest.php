<?php

namespace Harri\LaravelMpesa\Tests\Unit;

use Harri\LaravelMpesa\Support\MpesaLog;
use Harri\LaravelMpesa\Tests\TestCase;

class MpesaLogTest extends TestCase
{
    public function test_it_resolves_journey_specific_channels(): void
    {
        config()->set('mpesa.log_channels', [
            'default' => 'mpesa-default',
            'stk' => 'mpesa-stk',
            'c2b' => 'mpesa-c2b',
        ]);

        $this->assertSame('mpesa-stk', MpesaLog::channel('stk'));
        $this->assertSame('mpesa-c2b', MpesaLog::channel('c2b'));
        $this->assertSame('mpesa-default', MpesaLog::channel('b2c'));
    }

    public function test_it_can_infer_journeys_from_daraja_uris(): void
    {
        $this->assertSame('stk', MpesaLog::journeyFromUri('/mpesa/stkpush/v1/processrequest'));
        $this->assertSame('c2b', MpesaLog::journeyFromUri('/mpesa/c2b/v2/registerurl'));
        $this->assertSame('b2c', MpesaLog::journeyFromUri('/mpesa/b2c/v1/paymentrequest'));
        $this->assertSame('account_balance', MpesaLog::journeyFromUri('/mpesa/accountbalance/v1/query'));
        $this->assertSame('transaction_status', MpesaLog::journeyFromUri('/mpesa/transactionstatus/v1/query'));
    }
}

