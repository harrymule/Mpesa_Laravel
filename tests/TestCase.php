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

    protected function resetTestingDatabase(): void
    {
        $connection = config('database.connections.testing');

        if (($connection['driver'] ?? null) !== 'mysql' || ! extension_loaded('pdo_mysql')) {
            return;
        }

        $database = $connection['database'];

        $pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                $connection['host'],
                $connection['port'],
                $database
            ),
            $connection['username'],
            $connection['password']
        );

        $tables = $pdo->query(
            "select table_name from information_schema.tables where table_schema = '{$database}'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($tables)) {
            return;
        }

        $qualifiedTables = array_map(
            static fn (string $table): string => sprintf('`%s`.`%s`', $database, $table),
            $tables
        );

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DROP TABLE IF EXISTS '.implode(', ', $qualifiedTables));
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
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
        $app['config']->set('mpesa.callback_url', 'https://example.test/daraja/callbacks/stk');
        $app['config']->set('mpesa.timeout_url', 'https://example.test/daraja/callbacks/timeout');
        $app['config']->set('mpesa.result_url', 'https://example.test/daraja/callbacks/b2c/result');
        $app['config']->set('mpesa.connections.sandbox.consumer_key', 'consumer-key');
        $app['config']->set('mpesa.connections.sandbox.consumer_secret', 'consumer-secret');
        $app['config']->set('mpesa.connections.sandbox.security_credential', 'encoded-credential');

        $app['router']->aliasMiddleware('mpesa.test.deny', DenyMiddleware::class);
    }
}
