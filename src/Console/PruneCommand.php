<?php

namespace Cofa\PerformanceTracking\Console;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Storage\DatabaseDriver;
use Cofa\PerformanceTracking\Storage\StorageDriver;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'performance-tracking:prune
        {--hours= : Keep records newer than this many hours}
        {--chunk=5000 : Rows deleted per statement}';

    protected $description = 'Delete old request timing records';

    public function handle(?StorageDriver $storage = null): int
    {
        $storage ??= app()->bound(StorageDriver::class) ? app(StorageDriver::class) : new DatabaseDriver();

        $hours = (int) ($this->option('hours') ?? config('performance-tracking.retention_hours', 48));
        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = now()->subHours($hours);

        $deleted = $storage->prune($cutoff, $chunk);

        $this->info("Deleted {$deleted} timing records older than {$hours} hours.");

        return self::SUCCESS;
    }
}
