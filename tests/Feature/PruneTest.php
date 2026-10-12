<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Console\PruneCommand;
use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PruneTest extends TestCase
{
    private function seedTimings(int $count, $createdAt): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'method' => 'GET', 'path' => '/', 'status' => 200,
                'server_received_ms' => 1, 'server_sent_ms' => 2,
                'boot_ms' => 1, 'app_ms' => 1, 'service_ms' => 2, 'server_ms' => 2,
                'created_at' => $createdAt,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            PerformanceTiming::query()->insert($chunk);
        }
    }

    public function test_it_deletes_old_rows_in_bounded_batches(): void
    {
        $this->seedTimings(25, now()->subDays(3));
        $this->seedTimings(5, now());

        $deletes = 0;
        DB::listen(function ($q) use (&$deletes) {
            if (str_starts_with(strtolower($q->sql), 'delete')) {
                $deletes++;
                $this->assertLessThanOrEqual(10, count($q->bindings));
            }
        });

        $this->artisan('performance-tracking:prune', ['--chunk' => 10])
            ->expectsOutput('Deleted 25 timing records older than 48 hours.')
            ->assertSuccessful();

        $this->assertSame(5, PerformanceTiming::count());
        $this->assertSame(3, $deletes);
    }

    public function test_the_retention_can_be_overridden(): void
    {
        $this->seedTimings(3, now()->subHours(2));

        $this->artisan('performance-tracking:prune', ['--hours' => 1])->assertSuccessful();

        $this->assertSame(0, PerformanceTiming::count());
    }

    public function test_it_is_scheduled(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'performance-tracking:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('17 * * * *', $events->first()->expression);
        $this->assertTrue(class_exists(PruneCommand::class));
    }
}
