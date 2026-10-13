<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\RequiresFunction;

class RecordingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/orders', function () {
            DB::select('select 1');
            DB::select('select 2');

            return 'ok';
        })->name('orders.index');

        Route::get('/reset/{token}', fn () => 'ok');

        Route::get('/fail', fn () => throw new \RuntimeException('boom'));

        Route::get('/stream', fn () => response()->stream(function () {
            DB::select('select 1');
            usleep(100_000);
            echo 'streamed';
        }));
    }

    protected function defineDatabaseMigrations(): void
    {
        // As a migration, not Schema::create() inside a test: on MySQL that
        // DDL commits RefreshDatabase's transaction and rows leak between tests.
        $this->loadLaravelMigrations();
    }

    private function stamp(float $secondsAgo): string
    {
        return sprintf('t=%.3F', microtime(true) - $secondsAgo);
    }

    private function trustLocalProxy(): void
    {
        // As an application does: TrustProxies reads this on every request.
        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
    }

    public function test_it_records_a_request_and_exposes_the_timing_header(): void
    {
        $this->trustLocalProxy();

        $response = $this->get('/orders', ['X-Request-Start' => $this->stamp(0.05)]);

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/^id=[0-9a-f-]{36};t1=\d+\.\d{3};t2=\d+\.\d{3};n=[A-Za-z0-9_-]+$/',
            $response->headers->get('X-Request-Timing'),
        );

        $timing = PerformanceTiming::sole();
        $this->assertSame('GET', $timing->method);
        $this->assertSame('/orders', $timing->path);
        $this->assertSame('orders.index', $timing->route_name);
        $this->assertSame(200, $timing->status);
        $this->assertSame(2, $timing->db_count);
        $this->assertCount(2, $timing->queries);
        $this->assertGreaterThanOrEqual(45, $timing->delay_ms);
        $this->assertEqualsWithDelta($timing->boot_ms + $timing->app_ms, $timing->service_ms, 0.01);
        $this->assertEqualsWithDelta($timing->delay_ms + $timing->service_ms, $timing->server_ms, 0.5);
        $this->assertNull($timing->client_reported_at);

        // Query start times are measured from when the web server received the request.
        $this->assertGreaterThanOrEqual($timing->delay_ms, $timing->queries[0]['start_ms']);
    }

    public function test_server_timing_is_not_exposed_unless_enabled(): void
    {
        $this->assertNull($this->get('/orders')->headers->get('Server-Timing'));

        config(['performance-tracking.expose_server_timing' => true]);

        $header = $this->get('/orders')->headers->get('Server-Timing');
        $this->assertStringContainsString('db;dur=', $header);
        $this->assertStringContainsString('service;dur=', $header);
    }

    public function test_cross_origin_headers_expose_only_what_is_sent(): void
    {
        config(['performance-tracking.timing_allow_origin' => 'https://app.example']);

        $response = $this->get('/orders');

        $this->assertSame('https://app.example', $response->headers->get('Timing-Allow-Origin'));
        $this->assertSame('X-Request-Timing', $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_stored_server_timestamps_match_the_header_to_the_microsecond(): void
    {
        $response = $this->get('/orders');
        preg_match('/t1=([\d.]+);t2=([\d.]+)/', $response->headers->get('X-Request-Timing'), $m);

        $timing = PerformanceTiming::sole();
        $this->assertEqualsWithDelta((float) $m[1], $timing->server_received_ms, 0.001);
        $this->assertEqualsWithDelta((float) $m[2], $timing->server_sent_ms, 0.001);
    }

    public function test_delay_is_not_measured_without_a_stamp(): void
    {
        $this->get('/orders')->assertOk();

        $this->assertNull(PerformanceTiming::sole()->delay_ms);
    }

    public function test_the_fastcgi_param_is_trusted(): void
    {
        $this->withServerVariables(['REQUEST_START_TIME' => $this->stamp(0.05)])->get('/orders');

        $this->assertGreaterThanOrEqual(45, PerformanceTiming::sole()->delay_ms);
    }

    public function test_the_header_is_ignored_unless_it_came_through_a_trusted_proxy(): void
    {
        $this->get('/orders', ['X-Request-Start' => $this->stamp(5)]);

        $this->assertNull(PerformanceTiming::sole()->delay_ms);
    }

    public function test_the_header_can_be_trusted_from_anyone(): void
    {
        config(['performance-tracking.request_start_header_trust' => 'always']);

        $this->get('/orders', ['X-Request-Start' => $this->stamp(0.05)]);

        $this->assertGreaterThanOrEqual(45, PerformanceTiming::sole()->delay_ms);
    }

    public function test_an_implausible_delay_is_ignored(): void
    {
        $this->trustLocalProxy();

        $this->get('/orders', ['X-Request-Start' => $this->stamp(299)]);

        $this->assertNull(PerformanceTiming::sole()->delay_ms);
    }

    public function test_paths_are_stored_as_route_templates(): void
    {
        $this->get('/reset/s3cr3t-token');
        $this->assertSame('/reset/{token}', PerformanceTiming::sole()->path);

        PerformanceTiming::query()->delete();
        config(['performance-tracking.path_mode' => 'raw']);

        $this->get('/reset/s3cr3t-token');
        $this->assertSame('/reset/s3cr3t-token', PerformanceTiming::sole()->path);
    }

    public function test_unmatched_requests_keep_their_raw_path(): void
    {
        $this->get('/missing/page')->assertNotFound();

        $this->assertSame('/missing/page', PerformanceTiming::sole()->path);
    }

    public function test_ip_is_only_stored_when_enabled(): void
    {
        $this->get('/orders');
        $this->assertNull(PerformanceTiming::sole()->ip);

        PerformanceTiming::query()->delete();
        config(['performance-tracking.store_ip' => true]);

        $this->get('/orders');
        $this->assertSame('127.0.0.1', PerformanceTiming::sole()->ip);
    }

    public function test_the_user_is_stored_without_resolving_authentication(): void
    {
        $user = new User;
        $user->forceFill(['id' => 42]);

        $this->actingAs($user)->get('/orders');
        $this->assertSame('42', PerformanceTiming::sole()->user_id);

        // A remember-me cookie on a route that never touched auth: resolving
        // the user would look it up (and log them in) after the response
        // was built. The guard is only asked whether it already has one.
        PerformanceTiming::query()->delete();
        $this->app['auth']->forgetGuards();
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $this->withUnencryptedCookie($this->app['auth']->guard()->getRecallerName(), '1|token|'.str_repeat('a', 60))
            ->get('/orders');

        $this->assertNull(PerformanceTiming::sole()->user_id);
        $this->assertEmpty(array_filter($queries, fn ($sql) => str_contains($sql, 'users')));
    }

    public function test_an_exception_response_is_recorded_with_its_status(): void
    {
        $this->get('/fail')->assertStatus(500);

        $this->assertSame(500, PerformanceTiming::sole()->status);
    }

    public function test_a_streamed_body_is_timed_and_its_queries_counted(): void
    {
        $response = $this->get('/stream');

        // Testing terminates the request before reading the body; the
        // sample is stored once the body has been produced.
        $this->assertSame(0, PerformanceTiming::count());
        $this->assertSame('streamed', $response->streamedContent());

        $timing = PerformanceTiming::sole();
        $this->assertTrue($timing->streamed);
        $this->assertGreaterThanOrEqual(95, $timing->stream_ms);
        $this->assertSame(1, $timing->db_count);
    }

    // memory_reset_peak_usage() exists from PHP 8.2; on 8.1 the peak cannot be reset.
    #[RequiresFunction('memory_reset_peak_usage')]
    public function test_peak_memory_is_per_request(): void
    {
        $this->get('/orders');
        $baseline = PerformanceTiming::sole()->memory_peak_mb;
        PerformanceTiming::query()->delete();

        $ballast = str_repeat('x', 64 * 1048576);
        unset($ballast);

        $this->get('/orders');

        $this->assertLessThan($baseline + 32, PerformanceTiming::sole()->memory_peak_mb);
    }

    public function test_it_ignores_the_dashboard_and_configured_paths(): void
    {
        config(['performance-tracking.ignore_paths' => ['orders']]);

        $this->get('/orders');
        $this->get('/performance-tracking/client.js');

        $this->assertSame(0, PerformanceTiming::count());
    }

    public function test_it_samples(): void
    {
        config(['performance-tracking.sample_rate' => 0.0]);

        $response = $this->get('/orders');

        $this->assertNull($response->headers->get('X-Request-Timing'));
        $this->assertSame(0, PerformanceTiming::count());
    }

    public function test_it_can_be_disabled(): void
    {
        config(['performance-tracking.enabled' => false]);
        $this->app->forgetScopedInstances();

        $response = $this->get('/orders');

        $this->assertNull($response->headers->get('X-Request-Timing'));
        $this->assertSame(0, PerformanceTiming::count());
    }

    public function test_it_is_off_by_default_outside_local(): void
    {
        $config = require __DIR__.'/../../config/performance-tracking.php';

        $this->assertFalse($config['enabled']);
        $this->assertFalse($config['expose_server_timing']);
    }
}
