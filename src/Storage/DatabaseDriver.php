<?php

namespace Cofa\PerformanceTracking\Storage;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * StorageDriver backed by the application's default (or configured) database.
 */
final class DatabaseDriver implements StorageDriver
{
    public function write(array $attributes): void
    {
        PerformanceTiming::create($attributes);
    }

    public function find(string $id, array $columns = ['*']): ?PerformanceTiming
    {
        return PerformanceTiming::query()->find($id, $columns);
    }

    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        return PerformanceTiming::query()
            ->whereIn('id', $ids)
            ->whereNull('client_reported_at')
            ->where('created_at', '>=', now()->subMinutes((int) config('performance-tracking.client_report_window_minutes', 10)))
            ->get($columns);
    }

    public function updateIfUnreported(string $id, array $values): bool
    {
        return (bool) PerformanceTiming::query()
            ->whereKey($id)
            ->whereNull('client_reported_at')
            ->update($values);
    }

    public function query(): QueryScope
    {
        return new EloquentQueryScope(PerformanceTiming::query());
    }

    public function prune(Carbon $cutoff, int $chunk = 5000): int
    {
        $deleted = 0;

        do {
            $ids = PerformanceTiming::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            $batch = $ids->isEmpty() ? 0 : PerformanceTiming::query()->whereKey($ids->all())->delete();
            $deleted += $batch;
        } while ($batch >= $chunk);

        return $deleted;
    }
}
