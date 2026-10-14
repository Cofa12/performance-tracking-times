@php use Cofa\PerformanceTracking\Format; @endphp
<div class="stack-gap">
    <div class="tiles">
        @include('performance-tracking::partials.percentile-tile', ['title' => 'Response time', 'p' => $summary['client']])
        @include('performance-tracking::partials.percentile-tile', ['title' => 'Server time', 'p' => $summary['server']])
        <div class="card tile">
            <div class="label">Requests with client timings</div>
            <div class="value">{{ number_format($summary['client_share'] * 100, 0) }}%</div>
        </div>
    </div>

    <section class="card">
        <h2>Average breakdown <span class="muted h2-note">last {{ number_format($summary['count']) }} matching requests</span></h2>
        @php $avgSegments = array_values(array_filter($summary['segments'], fn ($s) => $s['ms'] !== null)); @endphp
        @if ($avgSegments)
            @include('performance-tracking::partials.bar', ['segments' => $avgSegments, 'size' => 'lg'])
            <div class="legend">
                @foreach ($summary['segments'] as $s)
                    <span><span class="sw k-{{ $s['kind'] }}"></span>{{ $s['label'] }}<b class="num">{{ Format::ms($s['ms']) }}</b></span>
                @endforeach
            </div>
            <p class="muted">
                Averaged over the {{ number_format($summary['basis']['count']) }} {{ $summary['basis']['client'] ? 'requests with client timings' : 'requests (none has client timings)' }};
                calculating and delay over those that measured them.
            </p>
        @else
            <p class="muted">No requests recorded yet.</p>
        @endif
    </section>
</div>
