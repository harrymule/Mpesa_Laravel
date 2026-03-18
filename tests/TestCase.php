<?php

namespace Harri\LaravelMpesa\Tests;

use Harri\LaravelMpesa\MpesaServiceProvider;
use Harri\LaravelMpesa\Tests\Support\DenyMiddleware;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MpesaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'mpesa_package_test',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('mpesa.load_routes', true);
        $app['config']->set('mpesa.load_migrations', true);
        $app['config']->set('mpesa.default', 'sandbox');
        $app['config']->set('mpesa.shortcode', '174379');
        $app['config']->set('mpesa.passkey', 'test-passkey');
        $app['config']->set('mpesa.initiator_name', 'testapi');
        $app['config']->set('mpesa.initiator_password', 'secret');
        $app['config']->set('mpesa.callback_url', 'https://example.test/mpesa/callbacks/stk');
        $app['config']->set('mpesa.timeout_url', 'https://example.test/mpesa/callbacks/timeout');
        $app['config']->set('mpesa.result_url', 'https://example.test/mpesa/callbacks/b2c/result');
        $app['config']->set('mpesa.connections.sandbox.consumer_key', 'consumer-key');
        $app['config']->set('mpesa.connections.sandbox.consumer_secret', 'consumer-secret');
        $app['config']->set('mpesa.connections.sandbox.security_credential', 'encoded-credential');

        $app['router']->aliasMiddleware('mpesa.test.deny', DenyMiddleware::class);
    }
}
