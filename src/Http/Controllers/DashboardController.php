<?php

namespace Cofa\PerformanceTracking\Http\Controllers;

use Cofa\PerformanceTracking\ClientScript;
use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Timeline;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardController extends Controller
{
    public const SUMMARY_SIZE = 1000;

    public function index(Request $request): View
    {
        // Only the first page updates live: later pages would shift under the reader.
        $live = $this->refreshSeconds() > 0 && (int) $request->query('page', 1) <= 1
            ? route('performance-tracking.live', $request->except('page'))
            : null;

        return view('performance-tracking::index', $this->listing($request) + [
            'filters' => $request->only(['q', 'method', 'status', 'slow']),
            'live' => $live,
            'refreshSeconds' => $this->refreshSeconds(),
        ]);
    }

    /**
     * The parts of the list page that change, re-rendered for the poller.
     */
    public function live(Request $request): JsonResponse
    {
        $data = $this->listing($request);

        return $this->fragments([
            'pt-summary' => view('performance-tracking::partials.summary', $data)->render(),
            'pt-list' => view('performance-tracking::partials.list', $data)->render(),
        ]);
    }

    public function show(string $id): View
    {
        $timing = PerformanceTiming::findOrFail($id);

        return view('performance-tracking::show', [
            'timing' => $timing,
            'timeline' => Timeline::for($timing),
            'live' => $this->awaitingClient($timing) ? route('performance-tracking.show-live', $timing->id) : null,
            'refreshSeconds' => $this->refreshSeconds(),
        ]);
    }

    /**
     * A request page refreshes until its client report arrives, or until the
     * report window has passed and none can arrive any more.
     */
    public function showLive(string $id): JsonResponse
    {
        $timing = PerformanceTiming::findOrFail($id);

        return $this->fragments([
            'pt-request' => view('performance-tracking::partials.request', [
                'timing' => $timing,
                'timeline' => Timeline::for($timing),
            ])->render(),
        ], done: ! $this->awaitingClient($timing));
    }

    /**
     * @param  array<string, string>  $html  element id => replacement HTML
     */
    private function fragments(array $html, bool $done = false): JsonResponse
    {
        return response()
            ->json(['html' => $html, 'done' => $done])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(Request $request): array
    {
        $query = $this->filtered($request);

        // simplePaginate: no COUNT(*) over the whole table on every visit.
        $timings = $this->newestFirst(clone $query)
            ->simplePaginate(50, PerformanceTiming::SUMMARY_COLUMNS)
            ->withPath(route('performance-tracking.index'))
            ->withQueryString();

        // Summary over the most recent matching requests.
        $sample = $this->newestFirst(clone $query)
            ->limit(self::SUMMARY_SIZE)
            ->get(PerformanceTiming::SUMMARY_COLUMNS);

        return [
            'timings' => $timings,
            'summary' => self::summary($sample),
            'maxTotal' => max(0.001, $timings->getCollection()->max(fn (PerformanceTiming $t) => $t->totalMs()) ?? 0),
            'slowMs' => (int) config('performance-tracking.slow_threshold_ms', 1000),
        ];
    }

    private function awaitingClient(PerformanceTiming $timing): bool
    {
        $window = (int) config('performance-tracking.client_report_window_minutes', 10);

        return $this->refreshSeconds() > 0
            && ! $timing->hasClientData()
            && $timing->created_at->greaterThan(now()->subMinutes($window));
    }

    private function refreshSeconds(): int
    {
        $seconds = (int) config('performance-tracking.dashboard_refresh_seconds', 5);

        return $seconds <= 0 ? 0 : max(2, $seconds);
    }

    public function clientScript(Request $request): BinaryFileResponse
    {
        return response()->file(ClientScript::PATH, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // The Blade tag adds ?v=<version>, so a versioned URL never changes.
            'Cache-Control' => $request->query('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
        ]);
    }

    /**
     * @param  Builder<PerformanceTiming>  $query
     * @return Builder<PerformanceTiming>
     */
    private function newestFirst(Builder $query): Builder
    {
        // created_at has one-second resolution; the server timestamp has
        // milliseconds, and the id breaks the remaining ties.
        return $query->orderByDesc('server_received_ms')->orderByDesc('id');
    }

    /**
     * @return Builder<PerformanceTiming>
     */
    private function filtered(Request $request): Builder
    {
        $slowMs = (int) config('performance-tracking.slow_threshold_ms', 1000);

        return PerformanceTiming::query()
            ->when($request->filled('q'), fn (Builder $q) => $q->where(function (Builder $q) use ($request) {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $request->string('q')).'%';
                $grammar = $q->getQuery()->getGrammar();

                // An explicit ESCAPE, since SQLite has none by default; "!"
                // rather than a backslash, which each database quotes differently.
                $q->whereRaw($grammar->wrap('path')." like ? escape '!'", [$term])
                    ->orWhereRaw($grammar->wrap('route_name')." like ? escape '!'", [$term]);
            }))
            ->when($request->filled('method'), fn (Builder $q) => $q->where('method', strtoupper((string) $request->string('method'))))
            ->when(in_array($request->input('status'), ['2', '3', '4', '5'], true), function (Builder $q) use ($request) {
                $base = (int) $request->input('status') * 100;
                $q->whereBetween('status', [$base, $base + 99]);
            })
            ->when($request->boolean('slow'), fn (Builder $q) => $q->where(function (Builder $q) use ($slowMs) {
                $q->where('response_ms', '>=', $slowMs)
                    ->orWhere(fn (Builder $q) => $q->whereNull('response_ms')->where('server_ms', '>=', $slowMs));
            }));
    }

    /**
     * Server time and end-to-end response time are different measurements,
     * so each gets its own percentiles. The average breakdown uses one set
     * of requests: those with client timings when there are any.
     *
     * @param  Collection<int, PerformanceTiming>  $sample
     * @return array<string, mixed>
     */
    public static function summary(Collection $sample): array
    {
        $clients = $sample->filter->hasClientData()->values();
        $basis = $clients->isEmpty() ? $sample : $clients;

        $avg = fn (Collection $items, string $field) => $items->isEmpty() ? null : $items->avg($field);
        $measured = fn (string $field) => $basis->whereNotNull($field);

        return [
            'count' => $sample->count(),
            'server' => self::percentiles($sample->pluck('server_ms')),
            'client' => self::percentiles($clients->pluck('response_ms')),
            'client_share' => $sample->isEmpty() ? 0 : $clients->count() / $sample->count(),
            'basis' => ['client' => $clients->isNotEmpty(), 'count' => $basis->count()],
            'segments' => [
                ['kind' => 'calc', 'label' => 'Calculating', 'ms' => $avg($measured('calc_ms'), 'calc_ms')],
                ['kind' => 'network', 'label' => 'Network out', 'ms' => $clients->isEmpty() ? null : $clients->avg(fn ($t) => $t->network_out_ms + ($t->connect_ms ?? 0))],
                ['kind' => 'delay', 'label' => 'Delay', 'ms' => $avg($measured('delay_ms'), 'delay_ms')],
                ['kind' => 'service', 'label' => 'Service', 'ms' => $avg($basis, 'service_ms')],
                ['kind' => 'network', 'label' => 'Network return', 'ms' => $avg($clients, 'network_return_ms')],
            ],
        ];
    }

    /**
     * Nearest-rank percentiles, and how far the slow tail (p95) sits from a
     * typical request (the median).
     *
     * @param  Collection<int, float|null>  $values
     * @return array{p50: ?float, p95: ?float, ratio: ?float, tail: ?string}
     */
    public static function percentiles(Collection $values): array
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->sort()->values();
        $at = fn (float $p) => $sorted->isEmpty() ? null : $sorted[max(0, (int) ceil($p * $sorted->count()) - 1)];

        $p50 = $at(0.50);
        $p95 = $at(0.95);
        $ratio = $p50 !== null && $p95 !== null && $p50 > 0 ? $p95 / $p50 : null;

        return ['p50' => $p50, 'p95' => $p95, 'ratio' => $ratio, 'tail' => self::tail($ratio)];
    }

    /**
     * "steady" while the p95 stays within the warning multiple of the
     * median, "uneven" up to the critical multiple, "long" beyond it.
     */
    public static function tail(?float $ratio): ?string
    {
        if ($ratio === null) {
            return null;
        }

        $warning = (float) config('performance-tracking.p95_ratio.warning', 3);
        $critical = (float) config('performance-tracking.p95_ratio.critical', 5);

        return match (true) {
            $ratio > $critical => 'long',
            $ratio >= $warning => 'uneven',
            default => 'steady',
        };
    }
}
