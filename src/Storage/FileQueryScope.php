<?php

namespace Cofa\PerformanceTracking\Storage;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * In-memory implementation of QueryScope for the FileDriver.
 */
final class FileQueryScope implements QueryScope
{
    /** @var array<int, callable(Collection<int, PerformanceTiming>): Collection<int, PerformanceTiming>> */
    private array $filters = [];

    /** @var list<array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limit = null;

    /** @param  Collection<int, PerformanceTiming>  $source */
    public function __construct(private Collection $source) {}

    public function orderByDesc(string $column): static
    {
        $this->orders[] = ['column' => $column, 'direction' => 'desc'];

        return $this;
    }

    public function where(string $column, string $operator, mixed $value): static
    {
        $this->filters[] = fn (Collection $c) => $c->filter(
            fn (PerformanceTiming $t) => $this->compare($t->{$column}, $operator, $value)
        );

        return $this;
    }

    /**
     * @param  array<int, mixed>  $range
     */
    public function whereBetween(string $column, array $range): static
    {
        $this->filters[] = fn (Collection $c) => $c->filter(
            fn (PerformanceTiming $t) => $t->{$column} >= $range[0] && $t->{$column} <= $range[1]
        );

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->filters[] = fn (Collection $c) => $c->filter(
            fn (PerformanceTiming $t) => $t->{$column} === null
        );

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->filters[] = fn (Collection $c) => $c->filter(
            fn (PerformanceTiming $t) => $t->{$column} !== null
        );

        return $this;
    }

    public function whereLike(array $columns, string $pattern): static
    {
        $regex = $this->likeToRegex($pattern);

        $this->filters[] = fn (Collection $c) => $c->filter(function (PerformanceTiming $t) use ($columns, $regex) {
            foreach ($columns as $column) {
                $value = (string) ($t->{$column} ?? '');

                if (preg_match($regex, $value)) {
                    return true;
                }
            }

            return false;
        });

        return $this;
    }

    public function whereSlow(int $slowMs): static
    {
        $this->filters[] = fn (Collection $c) => $c->filter(function (PerformanceTiming $t) use ($slowMs) {
            $time = $t->response_ms ?? $t->server_ms;

            return $time >= $slowMs;
        });

        return $this;
    }

    public function orWhere(callable $callback): static
    {
        $branch = $this->clone();
        $branch->filters = [];
        $callback($branch);

        $existingFilters = $this->filters;
        $branchFilters = $branch->filters;

        $this->filters = [function (Collection $c) use ($existingFilters, $branchFilters) {
            $left = $this->applyFilters($c, $existingFilters);
            $right = $this->applyFilters($c, $branchFilters);

            return $left->merge($right)->unique('id')->values();
        }];

        return $this;
    }

    public function limit(int $count): static
    {
        $this->limit = $count;

        return $this;
    }

    public function get(array $columns = ['*']): Collection
    {
        $result = $this->execute();

        if ($columns !== ['*']) {
            $result = $result->map(fn (PerformanceTiming $t) => $this->selectColumns($t, $columns));
        }

        return $result->values();
    }

    public function simplePaginate(int $perPage, array $columns = ['*']): mixed
    {
        $all = $this->execute();

        $page = (int) (request()->query('page', 1));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $items = $all->slice($offset, $perPage + 1);
        $hasMore = $items->count() > $perPage;
        $items = $items->take($perPage);

        if ($columns !== ['*']) {
            $items = $items->map(fn (PerformanceTiming $t) => $this->selectColumns($t, $columns));
        }

        $paginator = new Paginator($items->values(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);

        if (! $hasMore) {
            $paginator->hasMorePagesWhen(false);
        }

        return $paginator;
    }

    public function clone(): static
    {
        $clone = new self($this->source);
        $clone->filters = $this->filters;
        $clone->orders = $this->orders;
        $clone->limit = $this->limit;

        return $clone;
    }

    public function __clone()
    {
        // For native PHP clone calls
    }

    /**
     * @return Collection<int, PerformanceTiming>
     */
    private function execute(): Collection
    {
        $result = $this->applyFilters($this->source, $this->filters);

        foreach ($this->orders as $order) {
            $col = $order['column'];
            $result = $order['direction'] === 'desc'
                ? $result->sortByDesc(fn (PerformanceTiming $t) => $t->{$col})->values()
                : $result->sortBy(fn (PerformanceTiming $t) => $t->{$col})->values();
        }

        if ($this->limit !== null) {
            $result = $result->take($this->limit);
        }

        return $result->values();
    }

    /**
     * @param  Collection<int, PerformanceTiming>  $source
     * @param  array<int, callable(Collection<int, PerformanceTiming>): Collection<int, PerformanceTiming>>  $filters
     * @return Collection<int, PerformanceTiming>
     */
    private function applyFilters(Collection $source, array $filters): Collection
    {
        $result = $source;

        foreach ($filters as $filter) {
            $result = $filter($result);
        }

        return $result;
    }

    private function compare(mixed $value, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '='     => $value == $expected,
            '!='    => $value != $expected,
            '<'     => $value < $expected,
            '<='    => $value <= $expected,
            '>'     => $value > $expected,
            '>='    => $value >= $expected,
            default => false,
        };
    }

    private function likeToRegex(string $pattern): string
    {
        $regex = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($char === '!' && $i + 1 < $len) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($char === '%') {
                $regex .= '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }

        return '/'.'^'.$regex.'$'.'/iu';
    }

    /**
     * @param  list<string>  $columns
     */
    private function selectColumns(PerformanceTiming $model, array $columns): PerformanceTiming
    {
        if ($columns === ['*']) {
            return $model;
        }

        $subset = array_intersect_key($model->getAttributes(), array_flip($columns));

        return (new PerformanceTiming)->forceFill($subset);
    }
}
