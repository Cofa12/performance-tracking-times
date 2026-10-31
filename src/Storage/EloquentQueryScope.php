<?php

namespace Cofa\PerformanceTracking\Storage;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Bridges the Eloquent Builder to the QueryScope interface.
 */
final class EloquentQueryScope implements QueryScope
{
    /**
     * @param  Builder<PerformanceTiming>  $builder
     */
    public function __construct(private Builder $builder) {}

    public function orderByDesc(string $column): static
    {
        $this->builder = $this->builder->orderByDesc($column);

        return $this;
    }

    public function where(string $column, string $operator, mixed $value): static
    {
        $this->builder = $this->builder->where($column, $operator, $value);

        return $this;
    }

    /**
     * @param  array<int, mixed>  $range
     */
    public function whereBetween(string $column, array $range): static
    {
        $this->builder = $this->builder->whereBetween($column, $range);

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->builder = $this->builder->whereNull($column);

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->builder = $this->builder->whereNotNull($column);

        return $this;
    }

    public function whereLike(array $columns, string $pattern): static
    {
        $this->builder = $this->builder->where(function (Builder $q) use ($columns, $pattern) {
            $grammar = $q->getQuery()->getGrammar();

            foreach ($columns as $i => $column) {
                $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                $q->{$method}($grammar->wrap($column)." like ? escape '!'", [$pattern]);
            }
        });

        return $this;
    }

    public function whereSlow(int $slowMs): static
    {
        $this->builder = $this->builder->where(function (Builder $q) use ($slowMs) {
            $q->where('response_ms', '>=', $slowMs)
                ->orWhere(fn (Builder $q) => $q->whereNull('response_ms')->where('server_ms', '>=', $slowMs));
        });

        return $this;
    }

    public function orWhere(callable $callback): static
    {
        $this->builder = $this->builder->orWhere(function (Builder $q) use ($callback) {
            $callback(new self($q));
        });

        return $this;
    }

    public function limit(int $count): static
    {
        $this->builder = $this->builder->limit($count);

        return $this;
    }

    /**
     * @param  list<string>  $columns
     * @return Collection<int, PerformanceTiming>
     */
    public function get(array $columns = ['*']): Collection
    {
        /** @var Collection<int, PerformanceTiming> $collection */
        $collection = $this->builder->get($columns);

        return $collection;
    }

    public function simplePaginate(int $perPage, array $columns = ['*']): mixed
    {
        return $this->builder->simplePaginate($perPage, $columns);
    }

    public function clone(): static
    {
        return new self(clone $this->builder);
    }

    public function __clone()
    {
        $this->builder = clone $this->builder;
    }

    /**
     * @return Builder<PerformanceTiming>
     */
    public function getBuilder(): Builder
    {
        return $this->builder;
    }
}
