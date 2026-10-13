<?php

namespace Cofa\PerformanceTracking\Http\Middleware;

use Closure;
use Cofa\PerformanceTracking\DashboardAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may see the dashboard:
 *  - someone signed in through the dashboard's own login page, when a
 *    username and password are configured;
 *  - an application user the "viewPerformanceTracking" gate allows;
 *  - anyone in the "local" environment, when no login is configured.
 */
class Authorize
{
    public const GATE = 'viewPerformanceTracking';

    public function handle(Request $request, Closure $next): Response
    {
        if (DashboardAuth::check($request) || (Gate::has(self::GATE) && Gate::allows(self::GATE))) {
            return $next($request);
        }

        if (! DashboardAuth::configured()) {
            abort_unless(app()->environment('local'), 403);

            return $next($request);
        }

        // The live updates fetch JSON: tell them, rather than redirecting.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Sign in to the dashboard.'], 401);
        }

        if ($request->isMethod('GET') && $request->hasSession()) {
            $request->session()->put(DashboardAuth::INTENDED_KEY, $request->fullUrl());
        }

        return redirect()->route('performance-tracking.login');
    }
}
