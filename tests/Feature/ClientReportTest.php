<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Support\Str;

class ClientReportTest extends TestCase
{
    private function timing(array $attributes = []): PerformanceTiming
    {
        return PerformanceTiming::create(array_merge([
            'id' => (string) Str::uuid(),
            'method' => 'GET',
            'path' => '/orders',
            'status' => 200,
            'server_received_ms' => 1_000_030.0, // t1 (server clock)
            'server_sent_ms' => 1_000_080.0,     // t2
            'delay_ms' => 5,
            'boot_ms' => 15,
            'app_ms' => 30,
            'service_ms' => 45,
            'server_ms' => 50,
            'created_at' => now(),
        ], $attributes));
    }

    public function test_it_computes_both_network_legs_from_the_client_report(): void
    {
        $timing = $this->timing();

        // Clocks in sync (offset 0): out 30 ms, return 10 ms.
        $this->postJson('/performance-tracking/api/client', ['timings' => [[
            'id' => $timing->id,
            't0' => 1_000_000.0,
            't3' => 1_000_090.0,
            'calc_ms' => 12.5,
            'connect_ms' => 3,
            'offset_ms' => 0,
        ]]])->assertOk()->assertJson(['accepted' => 1]);

        $timing->refresh();
        $this->assertEqualsWithDelta(30, $timing->network_out_ms, 0.001);
        $this->assertEqualsWithDelta(10, $timing->network_return_ms, 0.001);
        $this->assertEqualsWithDelta(12.5 + 3 + 90, $timing->response_ms, 0.001);
        $this->assertSame('offset', $timing->network_split);
        $this->assertNotNull($timing->client_reported_at);
    }

    public function test_beacon_text_plain_bodies_are_accepted(): void
    {
        $timing = $this->timing();
        $body = json_encode(['timings' => [['id' => $timing->id, 't0' => 1_000_000.0, 't3' => 1_000_090.0]]]);

        $this->call('POST', '/performance-tracking/api/client', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)
            ->assertOk()
            ->assertJson(['accepted' => 1]);

        $this->assertSame('symmetric', $timing->refresh()->network_split);
        $this->assertEqualsWithDelta(20, $timing->network_out_ms, 0.001);
    }

    public function test_a_request_can_only_be_reported_once_and_only_recently(): void
    {
        $reported = $this->timing(['client_reported_at' => now(), 'network_out_ms' => 1]);
        $old = $this->timing(['created_at' => now()->subHour()]);

        $this->postJson('/performance-tracking/api/client', ['timings' => [
            ['id' => $reported->id, 't0' => 1_000_000.0, 't3' => 1_000_090.0],
            ['id' => $old->id, 't0' => 1_000_000.0, 't3' => 1_000_090.0],
        ]])->assertOk()->assertJson(['accepted' => 0]);

        $this->assertEquals(1, $reported->refresh()->network_out_ms);
        $this->assertNull($old->refresh()->client_reported_at);
    }

    public function test_impossible_reports_are_ignored(): void
    {
        $timing = $this->timing();

        // The client claims the whole exchange took 10 ms, less than the server's 50 ms.
        $this->postJson('/performance-tracking/api/client', ['timings' => [
            ['id' => $timing->id, 't0' => 1_000_000.0, 't3' => 1_000_010.0],
        ]])->assertOk();

        $this->assertNull($timing->refresh()->client_reported_at);
    }

    public function test_it_validates_the_payload(): void
    {
        $this->postJson('/performance-tracking/api/client', ['timings' => [['id' => 'nope']]])
            ->assertUnprocessable();
    }
}
