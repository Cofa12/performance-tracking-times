<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DashboardTest extends TestCase
{
    private int $sequence = 0;

    private function timing(array $attributes = []): PerformanceTiming
    {
        $this->sequence++;

        return PerformanceTiming::create(array_merge([
            'id' => (string) Str::uuid(),
            'method' => 'POST',
            'path' => '/checkout',
            'route_name' => 'checkout.store',
            'status' => 201,
            'server_received_ms' => 1_000_030.0 + $this->sequence,
            'server_sent_ms' => 1_000_080.0 + $this->sequence,
            'delay_ms' => 5,
            'boot_ms' => 15,
            'app_ms' => 30,
            'service_ms' => 45,
            'server_ms' => 50,
            'db_ms' => 4,
            'db_count' => 1,
            'queries' => [['sql' => 'select * from "orders" where "id" = ?', 'connection' => 'testing', 'start_ms' => 25, 'ms' => 4]],
            'calc_ms' => 12,
            'connect_ms' => 3,
            'network_out_ms' => 30,
            'network_return_ms' => 10,
            'response_ms' => 105,
            'network_split' => 'offset',
            'client_reported_at' => now(),
            'created_at' => now(),
        ], $attributes));
    }

    private function allow(): void
    {
        Gate::define('viewPerformanceTracking', fn ($user = null) => true);
    }

    public function test_it_is_forbidden_outside_local_without_a_gate(): void
    {
        $this->get('/performance-tracking')->assertForbidden();
    }

    public function test_the_gate_decides_for_signed_in_users(): void
    {
        Gate::define('viewPerformanceTracking', fn ($user) => $user->getAuthIdentifier() === 1);

        $admin = (new User)->forceFill(['id' => 1]);
        $other = (new User)->forceFill(['id' => 2]);

        $this->get('/performance-tracking')->assertForbidden();
        $this->actingAs($other)->get('/performance-tracking')->assertForbidden();
        $this->actingAs($admin)->get('/performance-tracking')->assertOk();
    }

    public function test_it_is_open_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->get('/performance-tracking')->assertOk();
    }

    public function test_the_list_and_the_diagram_render(): void
    {
        $this->allow();
        $timing = $this->timing();
        $this->timing(['path' => '/slow', 'client_reported_at' => null, 'response_ms' => null, 'server_ms' => 2500, 'service_ms' => 2495, 'app_ms' => 2480]);

        $this->get('/performance-tracking')
            ->assertOk()
            ->assertSee('/checkout')
            ->assertSee('Average breakdown')
            ->assertSee('Network return');

        $this->get('/performance-tracking?slow=1')
            ->assertOk()
            ->assertSee('/slow')
            ->assertDontSee('/checkout</a>', false);

        $this->get('/performance-tracking/requests/'.$timing->id)
            ->assertOk()
            ->assertSee('Waterfall')
            ->assertSee('Calculating')
            ->assertSee('Network out')
            ->assertSee('Delay')
            ->assertSee('Framework boot')
            ->assertSee('select * from &quot;orders&quot;', false)
            ->assertSee('105 ms');
    }

    public function test_the_list_never_loads_the_query_log(): void
    {
        $this->allow();
        $this->timing();
        $selects = [];
        DB::listen(function ($q) use (&$selects) {
            if (str_contains($q->sql, 'performance_timings')) {
                $selects[] = $q->sql;
            }
        });

        // Strict apps throw on attributes that were not selected.
        Model::preventAccessingMissingAttributes();

        try {
            $this->get('/performance-tracking')->assertOk();
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }

        $this->assertNotEmpty($selects);
        foreach ($selects as $sql) {
            $this->assertStringNotContainsString('*', $sql);
            $this->assertStringNotContainsString('queries', $sql);
            $this->assertStringNotContainsString('count(', strtolower($sql));
        }
    }

    public function test_server_and_client_percentiles_are_reported_separately(): void
    {
        $this->allow();
        $this->timing(['server_ms' => 50, 'response_ms' => 400]);
        $this->timing(['server_ms' => 60, 'client_reported_at' => null, 'response_ms' => null]);

        $this->get('/performance-tracking')
            ->assertOk()
            ->assertSeeInOrder(['Response time, median / p95', '400 ms', '400 ms', 'Server time, median / p95', '50.0 ms', '60.0 ms']);
    }

    public function test_pages_are_stable_when_requests_share_a_second(): void
    {
        $this->allow();
        $createdAt = now()->startOfSecond();

        // Same created_at second; request times not in insertion order.
        $ids = collect(range(1, 60))->shuffle()
            ->map(fn (int $i) => $this->timing(['created_at' => $createdAt, 'server_received_ms' => 2_000_000 + $i]))
            ->sortBy('server_received_ms')
            ->pluck('id');

        $seen = collect([1, 2])->flatMap(function (int $page) {
            preg_match_all('#requests/([0-9a-f-]{36})#', $this->get('/performance-tracking?page='.$page)->getContent(), $m);

            return $m[1];
        });

        $this->assertCount(60, $seen->unique());
        $this->assertSame($ids->reverse()->values()->all(), $seen->values()->all());
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->allow();
        $this->timing(['path' => '/my_route']);
        $this->timing(['path' => '/myXroute']);
        $this->timing(['path' => '/100%_off']);

        $this->get('/performance-tracking?q=my_route')
            ->assertSee('/my_route')
            ->assertDontSee('/myXroute');

        $this->get('/performance-tracking?q=100%')
            ->assertSee('/100%_off');

        $this->get('/performance-tracking?q=checkout.store')->assertSee('/my_route');
    }

    public function test_the_diagram_explains_what_was_not_measured(): void
    {
        $this->allow();
        $timing = $this->timing(['client_reported_at' => null, 'delay_ms' => null, 'response_ms' => null, 'calc_ms' => null]);

        $this->get('/performance-tracking/requests/'.$timing->id)
            ->assertOk()
            ->assertSee('Server time')
            ->assertSee('Calculating &amp; network not measured', false)
            ->assertSee('Delay not measured');
    }

    public function test_the_diagram_explains_streamed_and_headers_only_timings(): void
    {
        $this->allow();
        $timing = $this->timing(['streamed' => true, 'stream_ms' => 80, 'headers_only' => true]);

        $this->get('/performance-tracking/requests/'.$timing->id)
            ->assertOk()
            ->assertSee('Streaming the body')
            ->assertSee('streamed or file response')
            ->assertSee('does not include downloading the body');
    }

    public function test_an_invalid_id_is_not_found(): void
    {
        $this->allow();

        $this->get('/performance-tracking/requests/abc')->assertNotFound();
        $this->get('/performance-tracking/requests/'.Str::uuid())->assertNotFound();
    }

    public function test_the_first_page_updates_live(): void
    {
        $this->allow();
        $this->timing();

        $this->get('/performance-tracking?method=post')
            ->assertOk()
            ->assertSee('data-live="http://localhost/performance-tracking/live?method=post"', false)
            ->assertSee('data-live-every="5"', false)
            ->assertSee('id="live-status"', false);

        // Later pages would shift under the reader, so they stay still.
        $this->get('/performance-tracking?page=2')->assertDontSee('data-live=', false);

        config(['performance-tracking.dashboard_refresh_seconds' => 0]);
        $this->get('/performance-tracking')->assertDontSee('data-live=', false)->assertDontSee('id="live-status"', false);
    }

    public function test_the_live_endpoint_returns_the_changing_parts_with_the_filters_applied(): void
    {
        $this->allow();
        $this->timing(['path' => '/orders', 'method' => 'GET']);
        $this->timing(['path' => '/checkout', 'method' => 'POST']);

        $response = $this->getJson('/performance-tracking/live?method=GET')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('done', false);

        $html = $response->json('html');
        $this->assertSame(['pt-summary', 'pt-list'], array_keys($html));
        $this->assertStringContainsString('/orders', $html['pt-list']);
        $this->assertStringNotContainsString('/checkout', $html['pt-list']);
        $this->assertStringContainsString('data-row-id=', $html['pt-list']);
        $this->assertStringContainsString('Response time, median / p95', $html['pt-summary']);
    }

    public function test_live_pager_links_point_at_the_dashboard(): void
    {
        $this->allow();
        foreach (range(1, 51) as $i) {
            $this->timing();
        }

        $html = $this->getJson('/performance-tracking/live?q=checkout')->json('html.pt-list');

        $this->assertStringContainsString('href="http://localhost/performance-tracking?q=checkout&amp;page=2"', $html);
    }

    public function test_the_live_endpoint_is_protected_like_the_dashboard(): void
    {
        $this->getJson('/performance-tracking/live')->assertForbidden();
        $this->getJson('/performance-tracking/requests/'.Str::uuid().'/live')->assertForbidden();
    }

    public function test_a_request_page_updates_until_its_client_report_arrives(): void
    {
        $this->allow();
        $timing = $this->timing(['client_reported_at' => null, 'response_ms' => null]);

        $this->get('/performance-tracking/requests/'.$timing->id)
            ->assertSee('data-live="http://localhost/performance-tracking/requests/'.$timing->id.'/live"', false)
            ->assertSee('Waiting for client timings');

        $this->getJson('/performance-tracking/requests/'.$timing->id.'/live')
            ->assertOk()
            ->assertJsonPath('done', false)
            ->assertJsonPath('html.pt-request', fn ($html) => str_contains($html, 'Server time'));

        $timing->forceFill(['client_reported_at' => now(), 'response_ms' => 105])->save();

        $this->getJson('/performance-tracking/requests/'.$timing->id.'/live')
            ->assertJsonPath('done', true)
            ->assertJsonPath('html.pt-request', fn ($html) => str_contains($html, 'Response time') && str_contains($html, '105 ms'));

        $this->get('/performance-tracking/requests/'.$timing->id)->assertDontSee('data-live=', false);
    }

    public function test_a_request_page_stops_waiting_once_no_report_can_arrive(): void
    {
        $this->allow();
        $timing = $this->timing(['client_reported_at' => null, 'response_ms' => null, 'created_at' => now()->subMinutes(11)]);

        $this->get('/performance-tracking/requests/'.$timing->id)->assertDontSee('data-live=', false);
        $this->getJson('/performance-tracking/requests/'.$timing->id.'/live')->assertJsonPath('done', true);
    }

    public function test_the_client_script_is_served(): void
    {
        $this->get('/performance-tracking/client.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $this->assertStringContainsString(
            'immutable',
            $this->get('/performance-tracking/client.js?v=abc')->headers->get('Cache-Control'),
        );
    }
}
