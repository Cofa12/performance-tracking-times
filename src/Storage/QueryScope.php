<?php

namespace Cofa\PerformanceTracking\Storage;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Support\Collection;

/**
 * A wrapper around an ordered, filterable set of PerformanceTiming records
 * that the dashboard can build on without caring about the underlying driver.
 */
interface QueryScope
{
    /** @return static */
    public function orderByDesc(string $column): static;

    /** @return static */
    public function where(string $column, string $operator, mixed $value): static;

    /**
     * @param  array<int, mixed>  $range
     * @return static
     */
    public function whereBetween(string $column, array $range): static;

    /** @return static */
    public function whereNull(string $column): static;

    /** @return static */
    public function whereNotNull(string $column): static;

    /**
     * LIKE filter applied to a set of columns (OR'd together).
     *
     * @param  list<string>  $columns
     * @return static
     */
    public function whereLike(array $columns, string $pattern): static;

    /**
     * Filter for slow requests (response_ms >= $slowMs OR (response_ms IS NULL AND server_ms >= $slowMs)).
     *
     * @return static
     */
    public function whereSlow(int $slowMs): static;

    /** @return static */
    public function orWhere(callable $callback): static;

    /** @return static */
    public function limit(int $count): static;

    /**
     * @param  list<string>  $columns
     * @return Collection<int, PerformanceTiming>
     */
    public function get(array $columns = ['*']): Collection;

    /**
     * Returns a simple paginator compatible with Laravel's simplePaginate().
     *
     * @param  list<string>  $columns
     */
    public function simplePaginate(int $perPage, array $columns = ['*']): mixed;

    /** Clone the scope. */
    public function clone(): static;
}
