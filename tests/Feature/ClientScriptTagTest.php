<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

class ClientScriptTagTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/page', fn () => Blade::render('<head>@performanceTrackingScript</head>'));
    }

    public function test_the_tag_carries_the_pages_timing_id(): void
    {
        $response = $this->get('/page')->assertOk();

        preg_match('/^id=([0-9a-f-]{36});/', $response->headers->get('X-Request-Timing'), $m);

        $response->assertSee('src="http://localhost/performance-tracking/client.js"', false);
        $response->assertSee('data-endpoint="http://localhost/performance-tracking/api/client"', false);
        $response->assertSee('data-request-id="'.$m[1].'"', false);
        $this->assertStringNotContainsString(' defer', $response->getContent());
    }

    public function test_an_unrecorded_page_gets_the_tag_without_an_id(): void
    {
        config(['performance-tracking.ignore_paths' => ['page']]);

        $this->get('/page')
            ->assertOk()
            ->assertSee('client.js', false)
            ->assertDontSee('data-request-id', false);
    }
}
