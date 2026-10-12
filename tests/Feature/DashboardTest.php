<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DashboardTest extends TestCase
{
    private function timing(array $attributes = []): PerformanceTiming
    {
        return PerformanceTiming::create(array_merge([
            'id' => (string) Str::uuid(),
            'method' => 'POST',
            'path' => '/checkout',
            'route_name' => 'checkout.store',
            'status' => 201,
            'server_received_ms' => 1_000_030.0,
            'server_sent_ms' => 1_000_080.0,
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

    public function test_it_is_forbidden_outside_local_without_a_gate(): void
    {
        $this->get('/performance-tracking')->assertForbidden();
    }

    public function test_it_is_open_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->get('/performance-tracking')->assertOk();
    }

    public function test_the_list_and_the_diagram_render(): void
    {
        Gate::define('viewPerformanceTracking', fn ($user = null) => true);
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

    public function test_the_diagram_explains_what_was_not_measured(): void
    {
        Gate::define('viewPerformanceTracking', fn ($user = null) => true);
        $timing = $this->timing(['client_reported_at' => null, 'delay_ms' => null, 'response_ms' => null, 'calc_ms' => null]);

        $this->get('/performance-tracking/requests/'.$timing->id)
            ->assertOk()
            ->assertSee('Server time')
            ->assertSee('Calculating &amp; network not measured', false)
            ->assertSee('Delay not measured');
    }

    public function test_the_client_script_is_served(): void
    {
        $this->get('/performance-tracking/client.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }
}
