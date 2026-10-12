<?php

namespace Cofa\PerformanceTracking\Console;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'performance-tracking:prune {--hours= : Keep records newer than this many hours}';

    protected $description = 'Delete old request timing records';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('performance-tracking.retention_hours', 48));

        $deleted = PerformanceTiming::query()
            ->where('created_at', '<', now()->subHours($hours))
            ->delete();

        $this->info("Deleted {$deleted} timing records older than {$hours} hours.");

        return self::SUCCESS;
    }
}
