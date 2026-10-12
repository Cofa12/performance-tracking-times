@extends('performance-tracking::layout')

@php
    use Cofa\PerformanceTracking\Format;
    $total = $timeline->total;
    $ticks = Format::ticks($total);
    $pct = fn (float $v) => round($v / $total * 100, 4);
@endphp

@section('title', $timing->method.' '.$timing->path)

@push('head')
    <style>
        .hero { display: flex; flex-wrap: wrap; gap: 8px 24px; align-items: baseline; }
        .hero .big { font-size: 40px; font-weight: 600; line-height: 1.1; }
        .meta { display: flex; flex-wrap: wrap; gap: 4px 16px; color: var(--ink-2); font-size: 13px; margin-top: 8px; }

        .wf { --label-w: 260px; }
        .wf-row { display: grid; grid-template-columns: var(--label-w) 1fr; align-items: center; min-height: 30px; }
        .wf-row:hover { background: var(--surface-2); }
        .wf-label { padding: 4px 8px 4px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wf-label .d { color: var(--muted); font-size: 12px; margin-left: 6px; }
        .wf-label .v { display: none; }
        .wf-track { position: relative; height: 30px; }
        .wf-bar { position: absolute; top: 9px; height: 12px; min-width: 2px; border-radius: 4px; }
        .wf-row.depth-0 .wf-bar { top: 7px; height: 16px; }
        .wf-row.depth-2 .wf-bar { top: 11px; height: 8px; }
        .wf-val { position: absolute; top: 5px; font-size: 12px; color: var(--ink-2); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .wf-grid { position: absolute; top: 0; bottom: 0; width: 1px; background: var(--grid); }
        .wf-axis { display: grid; grid-template-columns: var(--label-w) 1fr; border-top: 1px solid var(--axis); margin-top: 4px; }
        .wf-axis .ticks { position: relative; height: 22px; }
        .wf-axis .tick { position: absolute; top: 4px; transform: translateX(-50%); font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .wf-axis .tick:first-child { transform: none; }
        .wf-axis .tick.last { transform: translateX(-100%); }
        .wf-body { position: relative; }
        .note { border-left: 3px solid var(--axis); padding: 4px 10px; color: var(--ink-2); margin: 6px 0; }
        code.sql { font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre-wrap; word-break: break-word; color: var(--ink-2); }
        @media (max-width: 640px) {
            .wf { --label-w: 150px; }
            .wf-label { display: flex; gap: 6px; }
            .wf-label .d, .wf-val { display: none; }
            .wf-label .l { overflow: hidden; text-overflow: ellipsis; min-width: 0; }
            .wf-label .v { display: inline; flex: none; margin-left: auto; color: var(--ink-2); font-size: 12px; }
            .hero .big { font-size: 32px; }
        }
    </style>
@endpush

@section('content')
    <header class="top">
        <h1><a href="{{ route('performance-tracking.index') }}">Request timings</a> <span class="muted">/</span> Request</h1>
    </header>

    <div class="stack-gap">
        <section class="card">
            <div class="hero">
                <div>
                    <div class="ink-2">{{ $timing->hasClientData() ? 'Response time' : 'Server time' }}</div>
                    <div class="big num">{{ Format::ms($total) }}</div>
                </div>
                <div>
                    <div><span class="pill s-{{ intdiv($timing->status, 100) }}">{{ $timing->status }}</span>
                        <strong>{{ $timing->method }}</strong> {{ $timing->path }}</div>
                    <div class="meta">
                        <span>{{ $timing->created_at->toDateTimeString() }}</span>
                        @if ($timing->route_name)<span>Route {{ $timing->route_name }}</span>@endif
                        <span>{{ $timing->db_count }} {{ \Illuminate\Support\Str::plural('query', $timing->db_count) }} · {{ Format::ms($timing->db_ms) }}</span>
                        @if ($timing->memory_peak_mb)<span>{{ $timing->memory_peak_mb }} MB peak</span>@endif
                        @if ($timing->user_id)<span>User {{ $timing->user_id }}</span>@endif
                    </div>
                </div>
            </div>

            <div style="margin-top: 16px">
                @include('performance-tracking::partials.bar', ['segments' => $timeline->stack, 'size' => 'lg'])
            </div>
            <div class="legend">
                @foreach ($timeline->stack as $s)
                    <span><span class="sw k-{{ $s['kind'] }}"></span>{{ $s['label'] }}<b class="num">{{ Format::ms($s['ms']) }}</b></span>
                @endforeach
            </div>
        </section>

        <section class="card">
            <h2>Waterfall</h2>
            <div class="wf" role="img" aria-label="Waterfall diagram of the request timeline; the table below lists the same values">
                <div class="wf-body">
                    @foreach ($timeline->rows as $row)
                        @php
                            $left = $pct($row['start']);
                            $width = $pct($row['ms']);
                            $valueRight = $left + $width > 80;
                        @endphp
                        <div class="wf-row depth-{{ $row['depth'] }}"
                             data-tip="{{ $row['label'] }}: {{ Format::ms($row['ms']) }}"
                             data-tip-detail="{{ $row['detail'] ? $row['detail']."\n" : '' }}Starts at {{ Format::ms($row['start']) }}">
                            <div class="wf-label" style="padding-left: {{ $row['depth'] * 14 }}px">
                                <span class="l">{{ $row['label'] }}</span>
                                @if ($row['depth'] < 2 && $row['detail'])<span class="d">{{ $row['detail'] }}</span>@endif
                                <span class="v num">{{ Format::ms($row['ms']) }}</span>
                            </div>
                            <div class="wf-track">
                                @foreach ($ticks as $tick)
                                    <div class="wf-grid" style="left: {{ $pct($tick) }}%"></div>
                                @endforeach
                                <div class="wf-bar k-{{ $row['kind'] }}" style="left: {{ $left }}%; width: {{ $width }}%"></div>
                                <div class="wf-val" style="{{ $valueRight ? 'right: '.(100 - $left + 0.8).'%' : 'left: '.($left + $width + 0.8).'%' }}">{{ Format::ms($row['ms']) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="wf-axis">
                    <div></div>
                    <div class="ticks">
                        @foreach ($ticks as $i => $tick)
                            @if ($pct($tick) <= 100)
                                <span class="tick {{ $loop->last && $pct($tick) > 92 ? 'last' : '' }}" style="left: {{ $pct($tick) }}%">{{ Format::ms($tick) }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>

            @foreach ($timeline->missing as $m)
                <p class="note"><strong>{{ $m['label'] }} not measured.</strong> {{ $m['reason'] }}</p>
            @endforeach
            @if ($timing->network_split === 'symmetric')
                <p class="note">The client sent no clock offset, so the network time is split evenly between the two directions.</p>
            @endif
        </section>

        <section class="card">
            <h2>Values</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Segment</th><th>Measured on</th><th class="right">Duration</th><th class="right">Share</th></tr></thead>
                    <tbody>
                    @php
                        $values = [
                            ['Calculating', 'Client', $timing->calc_ms],
                            ['Network out: connect (browser queue, DNS, TCP, TLS)', 'Client', $timing->connect_ms],
                            ['Network out: transfer', 'Client + server', $timing->network_out_ms],
                            ['Delay (queue)', 'Web server + PHP', $timing->delay_ms],
                            ['Service: framework boot', 'Server', $timing->boot_ms],
                            ['Service: middleware + controller', 'Server', $timing->app_ms],
                            ['… of which database', 'Server', $timing->db_ms],
                            ['Network return', 'Client + server', $timing->network_return_ms],
                        ];
                    @endphp
                    @foreach ($values as [$label, $where, $ms])
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="ink-2">{{ $where }}</td>
                            <td class="right num">{{ Format::ms($ms) }}</td>
                            <td class="right num muted">{{ $ms === null ? '' : number_format($ms / $total * 100, 1).'%' }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td><strong>{{ $timing->hasClientData() ? 'Response time' : 'Server time' }}</strong></td>
                        <td></td>
                        <td class="right num"><strong>{{ Format::ms($total) }}</strong></td>
                        <td class="right num muted">100%</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        @if ($timing->queries)
            <section class="card">
                <h2>Queries</h2>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th>SQL</th><th class="right">Starts after server received</th><th class="right">Duration</th></tr></thead>
                        <tbody>
                        @foreach ($timing->queries as $i => $q)
                            <tr>
                                <td class="muted num">{{ $i + 1 }}</td>
                                <td style="white-space: normal"><code class="sql">{{ $q['sql'] }}</code></td>
                                <td class="right num ink-2">{{ Format::ms($q['start_ms']) }}</td>
                                <td class="right num">{{ Format::ms($q['ms']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($timing->db_count > count($timing->queries))
                    <p class="muted">Showing the first {{ count($timing->queries) }} of {{ $timing->db_count }} queries.</p>
                @endif
            </section>
        @endif
    </div>
@endsection
