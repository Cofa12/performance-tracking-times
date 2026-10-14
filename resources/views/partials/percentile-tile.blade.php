@php
    use Cofa\PerformanceTracking\Format;

    /** @var string $title */
    /** @var array{p50: ?float, p95: ?float, ratio: ?float, tail: ?string} $p */
    $tailLabels = ['steady' => 'steady', 'uneven' => 'some slow requests', 'long' => 'long tail'];
    $tailHelp = [
        'steady' => 'The slowest 5% of requests take about as long as a typical one.',
        'uneven' => 'The slowest 5% of requests take noticeably longer than a typical one.',
        'long' => 'The slowest 5% of requests take far longer than a typical one: look for what makes them slow.',
    ];
@endphp
<div class="card tile">
    <div class="label">{{ $title }}</div>
    <dl class="pct">
        <div><dt>Median</dt><dd class="num">{{ Format::ms($p['p50']) }}</dd></div>
        <div><dt>p95</dt><dd class="num">{{ Format::ms($p['p95']) }}</dd></div>
    </dl>
    @if ($p['tail'])
        <span class="tail tail-{{ $p['tail'] }}" title="{{ $tailHelp[$p['tail']] }}">
            <b class="num">{{ number_format($p['ratio'], 1) }}×</b> median · {{ $tailLabels[$p['tail']] }}
        </span>
    @else
        <span class="tail tail-none">No data yet</span>
    @endif
</div>
