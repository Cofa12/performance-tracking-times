{{-- Shown when the page updates itself; the layout's script drives it. --}}
@if ($live)
    <div class="live-bar">
        <span class="live" id="live-status" data-state="live">
            <span class="dot" aria-hidden="true"></span><span class="t">{{ $label }}</span>
        </span>
        <button type="button" class="btn-sm" id="live-toggle" aria-pressed="false">Pause</button>
    </div>
@endif
