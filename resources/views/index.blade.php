@extends('performance-tracking::layout')

@php use Cofa\PerformanceTracking\Format; @endphp

@section('title', 'Request timings')

@section('content')
    <header class="top">
        <h1>Request timings</h1>
        @include('performance-tracking::partials.live-status', ['label' => 'Live'])
    </header>

    <div class="stack-gap" @if ($live) data-live="{{ $live }}" data-live-every="{{ $refreshSeconds }}" @endif>
        <div id="pt-summary">
            @include('performance-tracking::partials.summary')
        </div>

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

            <div id="pt-list">
                @include('performance-tracking::partials.list')
            </div>
        </section>
    </div>
@endsection
