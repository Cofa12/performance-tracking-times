<?php

namespace Cofa\PerformanceTracking\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    public const GATE = 'viewPerformanceTracking';

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        abort_unless(Gate::has(self::GATE) && Gate::allows(self::GATE), 403);

        return $next($request);
    }
}
