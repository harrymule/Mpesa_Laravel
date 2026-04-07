<?php

namespace Harri\LaravelMpesa\Tests\Unit;

use Harri\LaravelMpesa\MpesaClient;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MpesaClientTest extends TestCase
{
    public function test_it_caches_the_oauth_token_between_requests(): void
    {
        Cache::flush();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'cached-token',
                'expires_in' => '3600',
            ]),
        ]);

        $client = new MpesaClient(config('mpesa'));

        $first = $client->accessToken();
        $second = $client->accessToken();

        $this->assertSame('cached-token', $first['access_token']);
        $this->assertSame('cached-token', $second['access_token']);
        Http::assertSentCount(1);
    }

    public function test_it_clears_the_cached_token_when_requested(): void
    {
        Cache::flush();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'token-one',
                'expires_in' => '3600',
            ]),
        ]);

        $client = new MpesaClient(config('mpesa'));
        $client->accessToken();
        $client->clearAccessTokenCache();

        $this->assertFalse(Cache::has('mpesa_oauth_token:sandbox'));
    }

    public function test_it_generates_dynamic_qr_codes(): void
    {
        Cache::flush();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'qr-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/qrcode/v1/generate' => Http::response([
                'ResponseCode' => 'AG_QR_1',
                'RequestID' => 'QR-REQUEST-1',
                'ResponseDescription' => 'QR Code Successfully Generated.',
                'QRCode' => 'base64-qr',
            ]),
        ]);

        $client = new MpesaClient(config('mpesa'));

        $response = $client->qrCode([
            'MerchantName' => 'TEST SUPERMARKET',
            'RefNo' => 'Invoice Test',
            'Amount' => 1,
            'TrxCode' => 'BG',
            'CPI' => '373132',
            'Size' => '300',
        ]);

        $this->assertSame('AG_QR_1', $response['ResponseCode']);
        $this->assertSame('QR-REQUEST-1', $response['RequestID']);
        $this->assertSame('base64-qr', $response['QRCode']);
    }

    public function test_it_can_execute_batch_requests_async(): void
    {
        Cache::flush();

        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' => Http::response([
                'access_token' => 'batch-token',
                'expires_in' => '3600',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/c2b/v2/registerurl' => Http::response([
                'ConversationID' => 'BATCH_REG_1',
                'ResponseCode' => '0',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/accountbalance/v1/query' => Http::response([
                'ConversationID' => 'BATCH_BAL_1',
                'ResponseCode' => '0',
            ]),
        ]);

        $client = new MpesaClient(config('mpesa'));

        $results = $client->batch([
            [
                'key' => 'register',
                'uri' => '/mpesa/c2b/v2/registerurl',
                'payload' => [
                    'ShortCode' => '600000',
                    'ResponseType' => 'Completed',
                ],
            ],
            [
                'key' => 'balance',
                'uri' => '/mpesa/accountbalance/v1/query',
                'secure' => true,
                'payload' => [
                    'CommandID' => 'AccountBalance',
                    'PartyA' => '174379',
                ],
            ],
        ]);

        $this->assertSame('BATCH_REG_1', $results['register']['ConversationID']);
        $this->assertSame('BATCH_BAL_1', $results['balance']['ConversationID']);
    }
}


