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

        $this->assertSame(['p50' => 10.0, 'p95' => 19.0, 'ratio' => 1.9, 'tail' => 'steady'], DashboardController::percentiles($values));
        $this->assertSame(['p50' => 7.0, 'p95' => 7.0, 'ratio' => 1.0, 'tail' => 'steady'], DashboardController::percentiles(collect([7])));
        $this->assertSame(['p50' => null, 'p95' => null, 'ratio' => null, 'tail' => null], DashboardController::percentiles(collect([null])));
    }

    public function test_the_p95_is_measured_against_the_median(): void
    {
        // 19 requests at 20 ms and one at 200 ms: median 20 ms, p95 still 20 ms.
        $steady = DashboardController::percentiles(collect(array_merge(array_fill(0, 19, 20), [200])));
        $this->assertSame([20.0, 20.0, 1.0, 'steady'], [$steady['p50'], $steady['p95'], $steady['ratio'], $steady['tail']]);

        // The slowest tenth of the requests are 4x slower: p95 / median = 4.
        $uneven = DashboardController::percentiles(collect(array_merge(array_fill(0, 18, 25), array_fill(0, 2, 100))));
        $this->assertSame([25.0, 100.0, 4.0, 'uneven'], [$uneven['p50'], $uneven['p95'], $uneven['ratio'], $uneven['tail']]);

        $long = DashboardController::percentiles(collect(array_merge(array_fill(0, 18, 10), array_fill(0, 2, 90))));
        $this->assertSame([9.0, 'long'], [$long['ratio'], $long['tail']]);
    }

    public function test_the_tail_levels_use_the_configured_multiples(): void
    {
        $this->assertSame('steady', DashboardController::tail(2.99));
        $this->assertSame('uneven', DashboardController::tail(3.0));
        $this->assertSame('uneven', DashboardController::tail(5.0));
        $this->assertSame('long', DashboardController::tail(5.01));
        $this->assertNull(DashboardController::tail(null));

        config(['performance-tracking.p95_ratio' => ['warning' => 2, 'critical' => 4]]);
        $this->assertSame('uneven', DashboardController::tail(2.5));
        $this->assertSame('long', DashboardController::tail(4.5));
    }

    public function test_a_zero_median_has_no_ratio(): void
    {
        $this->assertSame(['p50' => 0.0, 'p95' => 0.0, 'ratio' => null, 'tail' => null], DashboardController::percentiles(collect([0, 0])));
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
        $this->assertSame([70.0, 70.0], [$summary['client']['p50'], $summary['client']['p95']]);
        $this->assertSame([40.0, 1000.0, 25.0, 'long'], [$summary['server']['p50'], $summary['server']['p95'], $summary['server']['ratio'], $summary['server']['tail']]);
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
