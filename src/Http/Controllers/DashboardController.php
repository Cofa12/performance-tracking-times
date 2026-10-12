<?php

namespace Cofa\PerformanceTracking\Http\Controllers;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Timeline;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $query = $this->filtered($request);

        $timings = (clone $query)->orderByDesc('created_at')->paginate(50)->withQueryString();

        // Summary over the most recent 1000 matching requests.
        $sample = (clone $query)->orderByDesc('created_at')->limit(1000)->get();

        return view('performance-tracking::index', [
            'timings' => $timings,
            'summary' => $this->summary($sample),
            'maxTotal' => max(0.001, $timings->getCollection()->max(fn (PerformanceTiming $t) => $t->totalMs()) ?? 0),
            'filters' => $request->only(['q', 'method', 'status', 'slow']),
            'slowMs' => (int) config('performance-tracking.slow_threshold_ms', 1000),
        ]);
    }

    public function show(string $id): View
    {
        $timing = PerformanceTiming::findOrFail($id);

        return view('performance-tracking::show', [
            'timing' => $timing,
            'timeline' => Timeline::for($timing),
        ]);
    }

    public function clientScript()
    {
        return response()->file(__DIR__.'/../../../resources/js/client.js', [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function filtered(Request $request): Builder
    {
        $slowMs = (int) config('performance-tracking.slow_threshold_ms', 1000);

        return PerformanceTiming::query()
            ->when($request->filled('q'), fn (Builder $q) => $q->where(function (Builder $q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')).'%';
                $q->where('path', 'like', $term)->orWhere('route_name', 'like', $term);
            }))
            ->when($request->filled('method'), fn (Builder $q) => $q->where('method', strtoupper($request->string('method'))))
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
     * @param  \Illuminate\Support\Collection<int, PerformanceTiming>  $sample
     */
    private function summary($sample): array
    {
        $totals = $sample->map(fn (PerformanceTiming $t) => $t->totalMs())->sort()->values();
        $clients = $sample->filter->hasClientData();

        $avg = fn ($items, string $field) => $items->isEmpty() ? null : $items->avg($field);

        return [
            'count' => $sample->count(),
            'p50' => $this->percentile($totals, 0.50),
            'p95' => $this->percentile($totals, 0.95),
            'segments' => [
                ['kind' => 'calc', 'label' => 'Calculating', 'ms' => $avg($clients->whereNotNull('calc_ms'), 'calc_ms')],
                ['kind' => 'network', 'label' => 'Network out', 'ms' => $clients->isEmpty() ? null : $clients->avg(fn ($t) => $t->network_out_ms + ($t->connect_ms ?? 0))],
                ['kind' => 'delay', 'label' => 'Delay', 'ms' => $avg($sample->whereNotNull('delay_ms'), 'delay_ms')],
                ['kind' => 'service', 'label' => 'Service', 'ms' => $avg($sample, 'service_ms')],
                ['kind' => 'network', 'label' => 'Network return', 'ms' => $avg($clients, 'network_return_ms')],
            ],
            'client_share' => $sample->isEmpty() ? 0 : $clients->count() / $sample->count(),
        ];
    }

    private function percentile($sorted, float $p): ?float
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        return $sorted[(int) min($sorted->count() - 1, floor($p * $sorted->count()))];
    }
}
