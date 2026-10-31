<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Storage\FileDriver;
use Cofa\PerformanceTracking\Storage\StorageDriver;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class FileStorageIntegrationTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/pt-integration-'.Str::random(10);
        mkdir($this->storageDir, 0755, true);

        config([
            'performance-tracking.enabled' => true,
            'performance-tracking.driver' => 'file',
            'performance-tracking.file.path' => $this->storageDir,
            'performance-tracking.file.encrypt' => true,
        ]);

        // Re-bind storage driver with updated test config
        $this->app->forgetInstance(StorageDriver::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageDir);
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        Route::get('/test-route', fn () => 'response from test route');
    }

    public function test_it_records_requests_to_encrypted_file_with_zero_database_rows(): void
    {
        $response = $this->get('/test-route');
        $response->assertOk();

        // 0 database rows created!
        $this->assertSame(0, PerformanceTiming::count());

        $header = $response->headers->get('X-Request-Timing');
        $this->assertNotNull($header);
        preg_match('/id=([0-9a-f-]{36})/', $header, $matches);
        $this->assertNotEmpty($matches[1]);
        $id = $matches[1];

        /** @var StorageDriver $storage */
        $storage = $this->app->make(StorageDriver::class);
        $this->assertInstanceOf(FileDriver::class, $storage);

        $timing = $storage->find($id);
        $this->assertNotNull($timing);
        $this->assertSame('/test-route', $timing->path);
        $this->assertSame(200, $timing->status);
        $this->assertNull($timing->client_reported_at);

        // Check raw file on disk is encrypted
        $files = glob($this->storageDir.'/*.ndjson');
        $this->assertCount(1, $files);
        $raw = file_get_contents($files[0]);
        $this->assertStringNotContainsString('/test-route', $raw);
    }

    public function test_client_report_and_dashboard_work_seamlessly_with_file_storage(): void
    {
        Gate::define('viewPerformanceTracking', fn ($user = null) => true);

        $response = $this->get('/test-route');
        preg_match('/id=([0-9a-f-]{36})/', $response->headers->get('X-Request-Timing'), $matches);
        $id = $matches[1];

        /** @var StorageDriver $storage */
        $storage = $this->app->make(StorageDriver::class);
        $timing = $storage->find($id);

        $t0 = $timing->server_received_ms - 10.0;
        $t3 = $timing->server_sent_ms + 10.0;

        // Post client report
        $clientResponse = $this->postJson('/performance-tracking/api/client', [
            'timings' => [
                [
                    'id' => $id,
                    't0' => $t0,
                    't3' => $t3,
                    'calc_ms' => 5.0,
                    'connect_ms' => 2.0,
                    'offset_ms' => 0.0,
                ],
            ],
        ]);

        $clientResponse->assertOk()->assertExactJson(['accepted' => 1, 'rejected' => 0]);

        // Dashboard index
        $indexResponse = $this->get('/performance-tracking');
        $indexResponse->assertOk();
        $indexResponse->assertSee('/test-route');

        // Dashboard show
        $showResponse = $this->get('/performance-tracking/requests/'.$id);
        $showResponse->assertOk();
        $showResponse->assertSee('/test-route');
    }

    public function test_prune_command_cleans_up_file_storage(): void
    {
        /** @var StorageDriver $storage */
        $storage = $this->app->make(StorageDriver::class);

        $storage->write([
            'id' => (string) Str::uuid(),
            'method' => 'GET',
            'path' => '/old-call',
            'status' => 200,
            'server_received_ms' => 1000.0,
            'server_sent_ms' => 1020.0,
            'server_ms' => 20.0,
            'created_at' => now()->subHours(50)->toDateTimeString(),
        ]);

        $this->artisan('performance-tracking:prune', ['--hours' => 24])
            ->expectsOutput('Deleted 1 timing records older than 24 hours.')
            ->assertSuccessful();
    }
}
