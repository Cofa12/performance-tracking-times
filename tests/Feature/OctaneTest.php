<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Recorder;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;

/**
 * Octane serves each request from a clone of the booted application and
 * makes that clone the current container. This reproduces that without
 * Octane itself.
 */
class OctaneTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/orders', function () {
            DB::select('select 1');
            DB::select('select 2');

            return 'ok';
        });
    }

    private function handleInSandbox(): void
    {
        $base = $this->app;
        $base->make(Recorder::class); // resolved in the base container, as a worker would have

        $sandbox = clone $base;
        $sandbox->forgetScopedInstances();
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        Container::setInstance($sandbox);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($sandbox);

        try {
            $kernel = $sandbox->make(Kernel::class);
            $kernel->setApplication($sandbox);
            $sandbox['router']->setContainer($sandbox);

            $request = Request::create('/orders');
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } finally {
            Container::setInstance($base);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($base);
            $sandbox['router']->setContainer($base);
            $base->make(Kernel::class)->setApplication($base);
        }
    }

    public function test_queries_are_counted_when_the_request_runs_in_a_cloned_container(): void
    {
        $this->handleInSandbox();

        $timing = PerformanceTiming::sole();
        $this->assertSame(2, $timing->db_count);
        $this->assertCount(2, $timing->queries);
    }
}
