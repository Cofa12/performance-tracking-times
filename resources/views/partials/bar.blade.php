@php
    /** @var array $segments  [['kind' => ..., 'label' => ..., 'ms' => ...], ...] */
    $sum = max(0.001, collect($segments)->sum(fn ($s) => (float) ($s['ms'] ?? 0)));
    $scale = $scale ?? $sum;
@endphp
<div class="bar {{ $size ?? '' }}" style="width: {{ min(100, max(0.5, $sum / max($scale, 0.001) * 100)) }}%">
    @foreach ($segments as $s)
        @if (($s['ms'] ?? 0) > 0)
            <div class="seg k-{{ $s['kind'] }}"
                 style="flex: {{ $s['ms'] }} 1 0"
                 data-tip="{{ $s['label'] }}: {{ \Cofa\PerformanceTracking\Format::ms($s['ms']) }}"
                 data-tip-detail="{{ number_format($s['ms'] / $sum * 100, 1) }}% of the total"></div>
        @endif
    @endforeach
</div>
