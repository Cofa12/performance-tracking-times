<?php

namespace Cofa\PerformanceTracking\Tests;

use Cofa\PerformanceTracking\PerformanceTrackingServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        // Off by default outside "local"; the tests run as "testing".
        $app['config']->set('performance-tracking.enabled', true);
        $app['config']->set('database.default', 'testing');

        // SQLite by default; TEST_DB=mysql or TEST_DB=pgsql runs the same suite there.
        $app['config']->set('database.connections.testing', match (getenv('TEST_DB')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: getenv('MYSQL_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: getenv('MYSQL_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: getenv('MYSQL_USER') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: 'postgres',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        });
    }

    protected function tearDown(): void
    {
        // Trusted proxies are static; do not leak them into the next test.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }
}
