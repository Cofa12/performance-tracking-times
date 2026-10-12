<?php

namespace Cofa\PerformanceTracking\Tests\Unit;

use Cofa\PerformanceTracking\Http\Controllers\DashboardController;
use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Timeline;
use Cofa\PerformanceTracking\Tests\TestCase;

class SummaryTest extends TestCase
{
    private function timing(array $attributes): PerformanceTiming
    {
        return (new PerformanceTiming)->forceFill(array_merge([
            'delay_ms' => null, 'boot_ms' => 10, 'app_ms' => 30, 'service_ms' => 40, 'server_ms' => 40,
            'calc_ms' => null, 'connect_ms' => null, 'network_out_ms' => null, 'network_return_ms' => null,
            'response_ms' => null, 'network_split' => null, 'streamed' => false, 'stream_ms' => null,
            'headers_only' => false, 'client_reported_at' => null,
        ], $attributes));
    }

    public function test_percentiles_use_the_nearest_rank(): void
    {
        $values = collect(range(1, 20))->shuffle();

        $this->assertSame(['p50' => 10.0, 'p95' => 19.0], DashboardController::percentiles($values));
        $this->assertSame(['p50' => 7.0, 'p95' => 7.0], DashboardController::percentiles(collect([7])));
        $this->assertSame(['p50' => null, 'p95' => null], DashboardController::percentiles(collect([null])));
    }

    public function test_the_breakdown_is_averaged_over_one_set_of_requests(): void
    {
        $client = ['client_reported_at' => now(), 'network_out_ms' => 20, 'network_return_ms' => 10, 'response_ms' => 70];

        $summary = DashboardController::summary(collect([
            $this->timing($client + ['service_ms' => 40]),
            $this->timing($client + ['service_ms' => 60]),
            $this->timing(['service_ms' => 1000, 'server_ms' => 1000]), // server only
        ]));

        $this->assertTrue($summary['basis']['client']);
        $this->assertSame(2, $summary['basis']['count']);
        $this->assertEquals(50, collect($summary['segments'])->firstWhere('label', 'Service')['ms']);
        $this->assertSame(['p50' => 70.0, 'p95' => 70.0], $summary['client']);
        $this->assertSame(['p50' => 40.0, 'p95' => 1000.0], $summary['server']);
    }

    public function test_the_list_timeline_does_not_read_the_query_log(): void
    {
        $timing = $this->timing([]);

        $this->assertArrayNotHasKey('queries', $timing->getAttributes());
        $this->assertNotEmpty(Timeline::for($timing, detailed: false)->stack);
    }

    public function test_a_streamed_body_extends_a_server_only_timeline(): void
    {
        $timeline = Timeline::for($this->timing(['streamed' => true, 'stream_ms' => 100]));

        $this->assertEqualsWithDelta(140, $timeline->total, 0.001);
        $this->assertSame('stream', collect($timeline->rows)->last()['key']);
    }
}
