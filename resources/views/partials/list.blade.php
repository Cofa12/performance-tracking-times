@php use Cofa\PerformanceTracking\Format; use Cofa\PerformanceTracking\Timeline; @endphp
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
            <tr data-row-id="{{ $t->id }}{{ $t->hasClientData() ? ':c' : '' }}">
                <td class="muted num" title="{{ $t->created_at }}">{{ $t->created_at->format('H:i:s') }}</td>
                <td class="path">
                    <a href="{{ route('performance-tracking.show', $t->id) }}"><span class="ink-2">{{ $t->method }}</span> {{ $t->path }}</a>
                </td>
                <td><span class="pill s-{{ intdiv($t->status, 100) }}">{{ $t->status }}</span></td>
                <td>@include('performance-tracking::partials.bar', ['segments' => Timeline::for($t, detailed: false)->stack, 'scale' => $maxTotal])</td>
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
