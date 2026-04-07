<?php

namespace Harri\LaravelMpesa\Tests\Unit;

use Harri\LaravelMpesa\Facades\Mpesa as MpesaFacade;
use Harri\LaravelMpesa\MpesaClient;
use Harri\LaravelMpesa\MpesaServiceProvider;
use Harri\LaravelMpesa\Services\MpesaCallbackProcessor;
use Harri\LaravelMpesa\Services\StkPushService;
use Harri\LaravelMpesa\Services\TransactionService;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

class PackageStructureTest extends TestCase
{
    public function test_service_provider_registers_expected_singletons_and_aliases(): void
    {
        $client = $this->app->make(MpesaClient::class);
        $resolvedAlias = $this->app->make('mpesa');
        $stkService = $this->app->make(StkPushService::class);
        $transactionService = $this->app->make(TransactionService::class);
        $callbackProcessor = $this->app->make(MpesaCallbackProcessor::class);

        $this->assertInstanceOf(MpesaClient::class, $client);
        $this->assertSame($client, $resolvedAlias);
        $this->assertInstanceOf(StkPushService::class, $stkService);
        $this->assertInstanceOf(TransactionService::class, $transactionService);
        $this->assertInstanceOf(MpesaCallbackProcessor::class, $callbackProcessor);
        $this->assertSame($client, $this->app->make(MpesaClient::class));
    }

    public function test_facade_resolves_to_the_bound_mpesa_client(): void
    {
        $facadeRoot = MpesaFacade::getFacadeRoot();

        $this->assertInstanceOf(MpesaClient::class, $facadeRoot);
        $this->assertSame($this->app->make('mpesa'), $facadeRoot);
    }

    public function test_package_config_is_merged_with_expected_defaults(): void
    {
        $this->assertSame('daraja', config('mpesa.route_prefix'));
        $this->assertSame('sandbox', config('mpesa.default'));
        $this->assertSame('/mpesa/b2c/v3/paymentrequest', config('mpesa.b2c.payment_uri'));
        $this->assertSame('/mpesa/qrcode/v1/generate', config('mpesa.qr.generate_uri'));
        $this->assertSame('stack', config('mpesa.log_channel'));
        $this->assertSame('stack', config('mpesa.log_channels.default'));
        $this->assertSame(false, config('mpesa.c2b.fallback.enabled'));
    }

    public function test_runtime_config_overrides_take_effect(): void
    {
        config()->set('mpesa.route_prefix', 'payments');
        config()->set('mpesa.c2b.fallback.enabled', true);
        config()->set('mpesa.b2c.payment_uri', '/custom/b2c');

        $this->assertSame('payments', config('mpesa.route_prefix'));
        $this->assertTrue(config('mpesa.c2b.fallback.enabled'));
        $this->assertSame('/custom/b2c', config('mpesa.b2c.payment_uri'));
    }

    public function test_config_publish_paths_are_registered(): void
    {
        $paths = ServiceProvider::pathsToPublish(MpesaServiceProvider::class, 'mpesa-config');

        $this->assertNotEmpty($paths);

        $source = realpath(__DIR__ . '/../../config/mpesa.php');
        $this->assertContains($source, array_map('realpath', array_keys($paths)));
    }
}
