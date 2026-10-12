<?php

namespace Cofa\PerformanceTracking\Console;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'performance-tracking:prune
        {--hours= : Keep records newer than this many hours}
        {--chunk=5000 : Rows deleted per statement}';

    protected $description = 'Delete old request timing records';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('performance-tracking.retention_hours', 48));
        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = now()->subHours($hours);
        $deleted = 0;

        // Small batches keep each statement short: one huge DELETE holds
        // locks for minutes and stalls replicas on a busy table.
        do {
            $ids = PerformanceTiming::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            $batch = $ids->isEmpty() ? 0 : PerformanceTiming::query()->whereKey($ids->all())->delete();
            $deleted += $batch;
        } while ($batch >= $chunk);

        $this->info("Deleted {$deleted} timing records older than {$hours} hours.");

        return self::SUCCESS;
    }
}
