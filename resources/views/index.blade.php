@extends('performance-tracking::layout')

@php use Cofa\PerformanceTracking\Format; use Cofa\PerformanceTracking\Timeline; @endphp

@section('title', 'Request timings')

@section('content')
    <header class="top">
        <h1>Request timings</h1>
        <span class="muted">Last {{ number_format($summary['count']) }} matching requests</span>
    </header>

    <div class="stack-gap">
        <div class="tiles">
            <div class="card tile">
                <div class="label">Median response time</div>
                <div class="value">{{ Format::ms($summary['p50']) }}</div>
            </div>
            <div class="card tile">
                <div class="label">95th percentile</div>
                <div class="value">{{ Format::ms($summary['p95']) }}</div>
            </div>
            <div class="card tile">
                <div class="label">Requests with client timings</div>
                <div class="value">{{ number_format($summary['client_share'] * 100, 0) }}%</div>
            </div>
        </div>

        <section class="card">
            <h2>Average breakdown</h2>
            @php $avgSegments = array_values(array_filter($summary['segments'], fn ($s) => $s['ms'] !== null)); @endphp
            @if ($avgSegments)
                @include('performance-tracking::partials.bar', ['segments' => $avgSegments, 'size' => 'lg'])
                <div class="legend">
                    @foreach ($summary['segments'] as $s)
                        <span><span class="sw k-{{ $s['kind'] }}"></span>{{ $s['label'] }}<b class="num">{{ Format::ms($s['ms']) }}</b></span>
                    @endforeach
                </div>
            @else
                <p class="muted">No requests recorded yet.</p>
            @endif
        </section>

        <section class="card">
            <form class="filters" method="get">
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Filter by path or route name" aria-label="Filter by path or route name">
                <select name="method" aria-label="Method">
                    <option value="">Any method</option>
                    @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m)
                        <option value="{{ $m }}" @selected(($filters['method'] ?? '') === $m)>{{ $m }}</option>
                    @endforeach
                </select>
                <select name="status" aria-label="Status">
                    <option value="">Any status</option>
                    @foreach (['2' => '2xx', '3' => '3xx', '4' => '4xx', '5' => '5xx'] as $v => $l)
                        <option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $l }}</option>
                    @endforeach
                </select>
                <label class="ink-2"><input type="checkbox" name="slow" value="1" @checked($filters['slow'] ?? false)> Slow only (≥ {{ Format::ms($slowMs) }})</label>
                <button type="submit">Apply</button>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Time</th>
                        <th>Request</th>
                        <th>Status</th>
                        <th style="width: 34%">Breakdown</th>
                        <th class="right">Response time</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($timings as $t)
                        <tr>
                            <td class="muted num" title="{{ $t->created_at }}">{{ $t->created_at->format('H:i:s') }}</td>
                            <td class="path">
                                <a href="{{ route('performance-tracking.show', $t->id) }}"><span class="ink-2">{{ $t->method }}</span> {{ $t->path }}</a>
                            </td>
                            <td><span class="pill s-{{ intdiv($t->status, 100) }}">{{ $t->status }}</span></td>
                            <td>@include('performance-tracking::partials.bar', ['segments' => Timeline::for($t)->stack, 'scale' => $maxTotal])</td>
                            <td class="right num">
                                {{ Format::ms($t->totalMs()) }}
                                @unless ($t->hasClientData())<span class="muted" title="Server time only, no client report">*</span>@endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No requests match.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <nav class="pager">
                <span class="muted">* server time only — no client report for that request</span>
                @if ($timings->previousPageUrl())<a class="btn" href="{{ $timings->previousPageUrl() }}">Newer</a>@endif
                @if ($timings->nextPageUrl())<a class="btn" href="{{ $timings->nextPageUrl() }}">Older</a>@endif
            </nav>
        </section>
    </div>
@endsection
