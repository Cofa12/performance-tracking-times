@extends('performance-tracking::layout')

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
        @include('performance-tracking::partials.live-status', ['label' => 'Waiting for client timings'])
    </header>

    <div id="pt-request" @if ($live) data-live="{{ $live }}" data-live-every="{{ $refreshSeconds }}" data-live-done="Client timings received" @endif>
        @include('performance-tracking::partials.request')
    </div>
@endsection
