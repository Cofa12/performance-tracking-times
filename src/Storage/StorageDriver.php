<?php

namespace Cofa\PerformanceTracking\Storage;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Abstracts where timing records are persisted and read from.
 */
interface StorageDriver
{
    /**
     * Persist a new timing record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function write(array $attributes): void;

    /**
     * Find a single record by primary key, selecting only the given columns.
     * Returns null when not found.
     *
     * @param  list<string>  $columns
     */
    public function find(string $id, array $columns = ['*']): ?PerformanceTiming;

    /**
     * Find multiple records by primary key, restricted to the recent client report window.
     *
     * @param  list<string>   $ids
     * @param  list<string>   $columns
     * @return Collection<int, PerformanceTiming>
     */
    public function findMany(array $ids, array $columns = ['*']): Collection;

    /**
     * Apply an update to a single record, only when client_reported_at is null.
     * Returns true when the record was updated, false when it was not found,
     * already had a client report, or the update failed.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateIfUnreported(string $id, array $values): bool;

    /**
     * Return a QueryScope instance to build filtered, paginated result sets.
     */
    public function query(): QueryScope;

    /**
     * Delete records older than $cutoff.
     *
     * @param  Carbon  $cutoff
     * @param  int     $chunk
     * @return int Number of records deleted.
     */
    public function prune(Carbon $cutoff, int $chunk = 5000): int;
}
