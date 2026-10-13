{{-- Right side of the dashboard header: live status and, for the dashboard's own login, sign out. --}}
<div class="actions">
    @include('performance-tracking::partials.live-status', ['label' => $label])
    @if (\Cofa\PerformanceTracking\DashboardAuth::check(request()))
        <form method="post" action="{{ route('performance-tracking.logout') }}">
            @csrf
            <button type="submit" class="btn-sm">Sign out</button>
        </form>
    @endif
</div>
