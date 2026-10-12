<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class RecordingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/orders', function () {
            DB::select('select 1');
            DB::select('select 2');

            return 'ok';
        })->name('orders.index');
    }

    public function test_it_records_a_request_and_exposes_the_timing_headers(): void
    {
        $response = $this->get('/orders', ['X-Request-Start' => sprintf('t=%.3F', microtime(true) - 0.05)]);

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/^id=[0-9a-f-]{36};t1=\d+\.\d{3};t2=\d+\.\d{3}$/',
            $response->headers->get('X-Request-Timing'),
        );
        $this->assertStringContainsString('delay;dur=', $response->headers->get('Server-Timing'));
        $this->assertStringContainsString('db;dur=', $response->headers->get('Server-Timing'));

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
    }

    public function test_stored_server_timestamps_match_the_header_to_the_microsecond(): void
    {
        $response = $this->get('/orders');
        preg_match('/t1=([\d.]+);t2=([\d.]+)/', $response->headers->get('X-Request-Timing'), $m);

        $timing = PerformanceTiming::sole();
        $this->assertEqualsWithDelta((float) $m[1], $timing->server_received_ms, 0.001);
        $this->assertEqualsWithDelta((float) $m[2], $timing->server_sent_ms, 0.001);
    }

    public function test_delay_is_not_measured_without_the_web_server_header(): void
    {
        $this->get('/orders')->assertOk();

        $this->assertNull(PerformanceTiming::sole()->delay_ms);
    }

    public function test_it_ignores_the_dashboard_and_configured_paths(): void
    {
        config(['performance-tracking.ignore_paths' => ['orders']]);

        $this->get('/orders');
        $this->get('/performance-tracking/client.js');

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
}
