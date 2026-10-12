<?php

namespace Cofa\PerformanceTracking\Http\Middleware;

use Closure;
use Cofa\PerformanceTracking\Recorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordPerformance
{
    public function __construct(private readonly Recorder $recorder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->recorder->shouldRecord($request)) {
            return $next($request);
        }

        $this->recorder->start($request);

        $response = $next($request);

        $this->recorder->stop($request, $response);

        return $response;
    }

    /**
     * Runs after the response has been sent, so storing the timing does not
     * add to the response time.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->recorder->persist();
    }
}
