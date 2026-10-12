<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\ClientReport;
use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientReportTest extends TestCase
{
    private const URL = '/performance-tracking/api/client';

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

    private function entry(PerformanceTiming $timing, array $values = []): array
    {
        return array_merge(['id' => $timing->id, 't0' => 1_000_000.0, 't3' => 1_000_090.0], $values);
    }

    private function beacon(string $body)
    {
        return $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_it_computes_both_network_legs_from_the_client_report(): void
    {
        $timing = $this->timing();

        // Clocks in sync (offset 0): out 30 ms, return 10 ms.
        $this->postJson(self::URL, ['timings' => [$this->entry($timing, [
            'calc_ms' => 12.5,
            'connect_ms' => 3,
            'offset_ms' => 0,
        ])]])->assertOk()->assertExactJson(['accepted' => 1, 'rejected' => 0]);

        $timing->refresh();
        $this->assertEqualsWithDelta(30, $timing->network_out_ms, 0.001);
        $this->assertEqualsWithDelta(10, $timing->network_return_ms, 0.001);
        $this->assertEqualsWithDelta(12.5 + 3 + 90, $timing->response_ms, 0.001);
        $this->assertSame('offset', $timing->network_split);
        $this->assertFalse($timing->headers_only);
        $this->assertNotNull($timing->client_reported_at);
    }

    public function test_beacon_text_plain_bodies_are_accepted(): void
    {
        $timing = $this->timing();

        $this->beacon(json_encode(['timings' => [$this->entry($timing, ['headers_only' => true])]]))
            ->assertOk()
            ->assertJson(['accepted' => 1]);

        $timing->refresh();
        $this->assertSame('symmetric', $timing->network_split);
        $this->assertEqualsWithDelta(20, $timing->network_out_ms, 0.001);
        $this->assertTrue($timing->headers_only);
    }

    public function test_a_request_can_only_be_reported_once_and_only_recently(): void
    {
        $reported = $this->timing(['client_reported_at' => now(), 'network_out_ms' => 1]);
        $old = $this->timing(['created_at' => now()->subHour()]);

        $this->postJson(self::URL, ['timings' => [$this->entry($reported), $this->entry($old)]])
            ->assertOk()
            ->assertExactJson(['accepted' => 0, 'rejected' => 2]);

        $this->assertEquals(1, $reported->refresh()->network_out_ms);
        $this->assertNull($old->refresh()->client_reported_at);
    }

    public function test_a_duplicate_in_one_batch_is_applied_once(): void
    {
        $timing = $this->timing();

        $this->postJson(self::URL, ['timings' => [
            $this->entry($timing, ['calc_ms' => 1]),
            $this->entry($timing, ['calc_ms' => 2]),
        ]])->assertExactJson(['accepted' => 1, 'rejected' => 1]);

        $this->assertEquals(1, $timing->refresh()->calc_ms);
    }

    public function test_a_report_that_lost_the_race_does_not_overwrite_the_first(): void
    {
        $timing = $this->timing();
        $raced = false;

        // A concurrent report writes the row right after this one has read it.
        DB::listen(function ($query) use ($timing, &$raced) {
            if (! $raced && str_starts_with(strtolower($query->sql), 'select')) {
                $raced = true;
                PerformanceTiming::query()->whereKey($timing->id)->update(['calc_ms' => 1, 'client_reported_at' => now()]);
            }
        });

        $result = (new ClientReport)->apply([$this->entry($timing, ['calc_ms' => 2])]);

        $this->assertTrue($raced);
        $this->assertSame(['accepted' => 0, 'rejected' => 1], $result);
        $this->assertEquals(1, $timing->refresh()->calc_ms);
    }

    public function test_impossible_reports_are_ignored(): void
    {
        $tooFast = $this->timing();
        $tooSlow = $this->timing();

        $this->postJson(self::URL, ['timings' => [
            // The client claims the whole exchange took 10 ms, less than the server's 50 ms.
            $this->entry($tooFast, ['t3' => 1_000_010.0]),
            // Two minutes on the wire.
            $this->entry($tooSlow, ['t3' => 1_000_000.0 + 50 + 120_000]),
        ]])->assertOk()->assertExactJson(['accepted' => 0, 'rejected' => 2]);

        $this->assertNull($tooFast->refresh()->client_reported_at);
        $this->assertNull($tooSlow->refresh()->client_reported_at);
    }

    public function test_an_id_replayed_from_a_cached_page_is_rejected(): void
    {
        $timing = $this->timing();

        // In sync clocks, but the client sent its request five minutes after
        // the server handled this id: the page came from a cache.
        $later = 5 * 60_000;

        $this->postJson(self::URL, ['timings' => [$this->entry($timing, [
            't0' => 1_000_000.0 + $later,
            't3' => 1_000_090.0 + $later,
            'offset_ms' => 0,
        ])]])->assertExactJson(['accepted' => 0, 'rejected' => 1]);
    }

    public function test_one_invalid_entry_does_not_discard_the_rest_of_the_batch(): void
    {
        $good = $this->timing();

        $this->postJson(self::URL, ['timings' => [
            $this->entry($good),
            ['id' => (string) Str::uuid(), 't0' => 1, 't3' => 2, 'calc_ms' => 700_000],
            ['id' => 'nope'],
            'not an object',
        ]])->assertOk()->assertExactJson(['accepted' => 1, 'rejected' => 3]);

        $this->assertNotNull($good->refresh()->client_reported_at);
    }

    public function test_it_validates_the_envelope(): void
    {
        $this->postJson(self::URL, ['nope' => []])->assertUnprocessable();
        $this->postJson(self::URL, ['timings' => array_fill(0, 51, [])])->assertUnprocessable();
    }

    public function test_malformed_bodies_are_rejected_not_errors(): void
    {
        foreach (['123', '"text"', 'null', 'true', '{not json', ''] as $body) {
            $this->beacon($body)->assertUnprocessable();
        }

        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], '123')
            ->assertUnprocessable();
    }

    public function test_reports_are_rate_limited_per_address(): void
    {
        config(['performance-tracking.client_rate_limit.per_ip' => 2]);

        $this->postJson(self::URL, ['timings' => []])->assertUnprocessable();
        $this->postJson(self::URL, ['timings' => []])->assertUnprocessable();
        $this->postJson(self::URL, ['timings' => []])->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson(self::URL, ['timings' => []])
            ->assertUnprocessable();
    }

    public function test_reports_are_rate_limited_globally(): void
    {
        config(['performance-tracking.client_rate_limit.global' => 2]);

        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(self::URL, ['timings' => []])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
            ->postJson(self::URL, ['timings' => []])
            ->assertStatus(429);
    }
}
