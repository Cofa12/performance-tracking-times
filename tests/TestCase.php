<?php

namespace Cofa\PerformanceTracking\Tests;

use Cofa\PerformanceTracking\PerformanceTrackingServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [PerformanceTrackingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        // SQLite by default; TEST_DB=mysql runs the same suite against MySQL.
        $app['config']->set('database.connections.testing', getenv('TEST_DB') === 'mysql'
            ? [
                'driver' => 'mysql',
                'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
                'port' => getenv('MYSQL_PORT') ?: '3306',
                'database' => getenv('MYSQL_DATABASE') ?: 'testing',
                'username' => getenv('MYSQL_USER') ?: 'root',
                'password' => getenv('MYSQL_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]
            : [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
    }
}
