<?php

namespace Cofa\PerformanceTracking\Tests\Unit;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Storage\FileDriver;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FileDriverTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/pt-test-'.Str::random(10);
        mkdir($this->storageDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageDir);
        parent::tearDown();
    }

    private function createDriver(bool $encrypt = true): FileDriver
    {
        $encrypter = $this->app->make('encrypter');

        return new FileDriver($this->storageDir, $encrypter, $encrypt);
    }

    public function test_it_encrypts_records_at_rest_and_never_writes_plain_text(): void
    {
        $driver = $this->createDriver(encrypt: true);
        $this->assertTrue($driver->isEncrypted());

        $sensitivePath = '/api/v1/confidential-resource';
        $sensitiveSql = 'SELECT secret_column FROM users WHERE id = 42';

        $driver->write([
            'id' => (string) Str::uuid(),
            'method' => 'GET',
            'path' => $sensitivePath,
            'status' => 200,
            'server_received_ms' => 1000.0,
            'server_sent_ms' => 1050.0,
            'server_ms' => 50.0,
            'queries' => [
                ['sql' => $sensitiveSql, 'ms' => 2.5],
            ],
            'created_at' => now()->toDateTimeString(),
        ]);

        $files = $driver->allFiles();
        $this->assertCount(1, $files);

        $rawContents = file_get_contents($files[0]);

        // Verify the raw file does NOT contain sensitive strings in plain text
        $this->assertStringNotContainsString($sensitivePath, $rawContents);
        $this->assertStringNotContainsString($sensitiveSql, $rawContents);
        $this->assertStringNotContainsString('confidential-resource', $rawContents);

        // Verify records can be read and decrypted back into models
        $record = $driver->findMany([Str::uuid()->toString()])->first() ?? $driver->allRecords()->first();
        $this->assertNotNull($record);
        $this->assertInstanceOf(PerformanceTiming::class, $record);
        $this->assertSame($sensitivePath, $record->path);
        $this->assertSame($sensitiveSql, $record->queries[0]['sql']);
    }

    public function test_it_merges_subsequent_client_reports(): void
    {
        $driver = $this->createDriver();
        $id = (string) Str::uuid();

        $driver->write([
            'id' => $id,
            'method' => 'POST',
            'path' => '/orders',
            'status' => 201,
            'server_received_ms' => 1000.0,
            'server_sent_ms' => 1050.0,
            'server_ms' => 50.0,
            'created_at' => now()->toDateTimeString(),
        ]);

        $found = $driver->find($id);
        $this->assertNotNull($found);
        $this->assertNull($found->client_reported_at);
        $this->assertNull($found->response_ms);

        // Apply client report patch
        $updated = $driver->updateIfUnreported($id, [
            'network_out_ms' => 15.0,
            'network_return_ms' => 10.0,
            'response_ms' => 75.0,
            'client_reported_at' => now()->toDateTimeString(),
        ]);

        $this->assertTrue($updated);

        // Read again: should reflect merged state
        $merged = $driver->find($id);
        $this->assertNotNull($merged);
        $this->assertSame('/orders', $merged->path);
        $this->assertEquals(75.0, $merged->response_ms);
        $this->assertNotNull($merged->client_reported_at);

        // A second client report on the same id should be rejected
        $this->assertFalse($driver->updateIfUnreported($id, ['response_ms' => 99.0]));
    }

    public function test_it_queries_filters_and_paginates_in_memory(): void
    {
        $driver = $this->createDriver();

        for ($i = 1; $i <= 5; $i++) {
            $driver->write([
                'id' => (string) Str::uuid(),
                'method' => $i % 2 === 0 ? 'POST' : 'GET',
                'path' => '/items/'.$i,
                'status' => $i === 5 ? 500 : 200,
                'server_received_ms' => 1000.0 + $i,
                'server_sent_ms' => 1000.0 + $i + ($i * 100),
                'server_ms' => (float) ($i * 100),
                'created_at' => now()->toDateTimeString(),
            ]);
        }

        $all = $driver->query()->get();
        $this->assertCount(5, $all);

        // Filter by method
        $posts = $driver->query()->where('method', '=', 'POST')->get();
        $this->assertCount(2, $posts);

        // Filter by path LIKE
        $searched = $driver->query()->whereLike(['path'], '%items/3%')->get();
        $this->assertCount(1, $searched);
        $this->assertSame('/items/3', $searched->first()->path);

        // Filter by status between
        $serverErrors = $driver->query()->whereBetween('status', [500, 599])->get();
        $this->assertCount(1, $serverErrors);
        $this->assertSame(500, $serverErrors->first()->status);

        // Filter slow requests
        $slow = $driver->query()->whereSlow(300)->get();
        $this->assertCount(3, $slow); // 300, 400, 500 ms

        // Pagination
        $page1 = $driver->query()->simplePaginate(2);
        $this->assertCount(2, $page1->items());
    }

    public function test_it_prunes_records_older_than_cutoff(): void
    {
        $driver = $this->createDriver();
        $oldId = (string) Str::uuid();
        $freshId = (string) Str::uuid();

        $driver->write([
            'id' => $oldId,
            'method' => 'GET',
            'path' => '/old',
            'status' => 200,
            'server_received_ms' => 1000.0,
            'server_sent_ms' => 1020.0,
            'server_ms' => 20.0,
            'created_at' => now()->subDays(5)->toDateTimeString(),
        ]);

        $driver->write([
            'id' => $freshId,
            'method' => 'GET',
            'path' => '/fresh',
            'status' => 200,
            'server_received_ms' => 1000.0,
            'server_sent_ms' => 1020.0,
            'server_ms' => 20.0,
            'created_at' => now()->toDateTimeString(),
        ]);

        $this->assertCount(2, $driver->allRecords());

        $deleted = $driver->prune(now()->subDays(2));
        $this->assertSame(1, $deleted);

        $remaining = $driver->allRecords();
        $this->assertCount(1, $remaining);
        $this->assertSame($freshId, $remaining->first()->id);
    }
}
