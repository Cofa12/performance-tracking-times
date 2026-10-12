<?php

namespace Cofa\PerformanceTracking;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Collects the server-side timings of the current request.
 *
 * Timeline (server clock):
 *   received (web server stamp) -> php start -> middleware start -> response
 *   |-------- delay ------------|---- boot ---|-------- app --------|
 */
class Recorder
{
    public const HEADER = 'X-Request-Timing';

    private ?Sample $current = null;

    public function __construct(private readonly Repository $config)
    {
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get('performance-tracking.'.$key, $default);
    }

    public function shouldRecord(Request $request): bool
    {
        if (! $this->config('enabled', false)) {
            return false;
        }

        if (in_array($request->getMethod(), (array) $this->config('ignore_methods', []), true)) {
            return false;
        }

        $path = trim($this->config('path', 'performance-tracking'), '/');
        $ignored = array_merge([$path, $path.'/*'], (array) $this->config('ignore_paths', []));

        if ($request->is(...$ignored)) {
            return false;
        }

        $rate = (float) $this->config('sample_rate', 1.0);

        return $rate >= 1.0 || ($rate > 0 && mt_rand() / mt_getrandmax() < $rate);
    }

    public function start(Request $request): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage(); // otherwise long-running workers report their lifetime peak
        }

        $now = microtime(true);

        $this->current = new Sample(self::newId(), $this->phpStart($request, $now), $now);
    }

    public function recordQuery(QueryExecuted $event): void
    {
        $sample = $this->current;

        if ($sample === null || ! $sample->isOpen()) {
            return;
        }

        $sample->dbMs += (float) $event->time;
        $sample->dbCount++;

        if ($this->config('record_queries', true)
            && count($sample->queries) < (int) $this->config('query_limit', 50)) {
            $startedAt = microtime(true) - $event->time / 1000;

            $sample->queries[] = [
                'sql' => Str::limit($event->sql, 2000),
                'connection' => $event->connectionName,
                'start_ms' => round(($startedAt - $sample->received) * 1000, 3),
                'ms' => round((float) $event->time, 3),
            ];
        }
    }

    public function stop(Request $request, Response $response): void
    {
        if ($this->current === null) {
            return;
        }

        try {
            $this->finish($this->current, $request, $response);
        } catch (Throwable $e) {
            // Monitoring must never break the application.
            $this->current = null;
            Log::warning('performance-tracking: could not finish timing', ['exception' => $e->getMessage()]);
        }
    }

    private function finish(Sample $sample, Request $request, Response $response): void
    {
        $sample->sent = microtime(true);

        // Read now rather than in start(): TrustProxies has run by this point.
        if (($received = $this->receivedAt($request, $sample->phpStart)) !== null) {
            $shift = ($sample->phpStart - $received) * 1000;
            $sample->received = $received;
            $sample->delayMs = $shift;

            foreach ($sample->queries as &$query) {
                $query['start_ms'] = round($query['start_ms'] + $shift, 3);
            }
            unset($query);
        }

        $sample->bootMs = ($sample->appStart - $sample->phpStart) * 1000;
        $sample->appMs = ($sample->sent - $sample->appStart) * 1000;
        $sample->request = [
            'method' => $request->getMethod(),
            'path' => $this->pathFor($request),
            'route_name' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'ip' => $this->config('store_ip', false) ? $request->ip() : null,
            'user_id' => $this->userId(),
        ];

        $this->trackBody($sample, $response);
        $this->addHeaders($sample, $response);
    }

    /**
     * The body of a streamed response is produced after t2, while it is being
     * sent. Time it (and count its queries) by wrapping the callback.
     */
    private function trackBody(Sample $sample, Response $response): void
    {
        if ($response instanceof BinaryFileResponse) {
            $sample->streamed = true;

            return;
        }

        if (! $response instanceof StreamedResponse || ! method_exists($response, 'getCallback')) {
            return;
        }

        $callback = $response->getCallback();

        if ($callback === null) {
            return;
        }

        $sample->streamed = true;
        $sample->awaitingBody = true;

        $response->setCallback(function () use ($callback, $sample) {
            $started = microtime(true);
            $sample->streaming = true;

            try {
                return $callback();
            } finally {
                $sample->streaming = false;
                $sample->awaitingBody = false;
                $sample->streamMs = (microtime(true) - $started) * 1000;

                if ($sample->persistRequested) {
                    $this->release($sample);
                    $this->store($sample);
                }
            }
        });
    }

    public function persist(): void
    {
        $sample = $this->current;

        if ($sample === null || $sample->sent === null) {
            $this->current = null;

            return;
        }

        if ($sample->awaitingBody) {
            // The body has not been sent yet; it stores the sample when done.
            $sample->persistRequested = true;

            return;
        }

        $this->release($sample);
        $this->store($sample);
    }

    private function release(Sample $sample): void
    {
        if ($this->current === $sample) {
            $this->current = null; // our own insert must not be recorded
        }
    }

    private function store(Sample $sample): void
    {
        try {
            PerformanceTiming::create($sample->attributes());
        } catch (Throwable $e) {
            // Monitoring must never break the application.
            Log::warning('performance-tracking: could not store timing', ['exception' => $e->getMessage()]);
        }
    }

    public function currentId(): ?string
    {
        return $this->current?->id;
    }

    public function nodeId(): string
    {
        $node = (string) ($this->config('node') ?: substr(sha1((string) gethostname()), 0, 8));

        return substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $node), 0, 32);
    }

    private function addHeaders(Sample $sample, Response $response): void
    {
        $exposed = [self::HEADER];

        if ($this->config('expose_server_timing', false)) {
            $metrics = [];

            if ($sample->delayMs !== null) {
                $metrics[] = sprintf('delay;dur=%.3F;desc="Queue"', $sample->delayMs);
            }

            $metrics[] = sprintf('boot;dur=%.3F;desc="Framework boot"', $sample->bootMs);
            $metrics[] = sprintf('app;dur=%.3F;desc="Middleware + controller"', $sample->appMs);
            $metrics[] = sprintf('db;dur=%.3F;desc="%d queries"', $sample->dbMs, $sample->dbCount);
            $metrics[] = sprintf('service;dur=%.3F', $sample->bootMs + $sample->appMs);

            $response->headers->set('Server-Timing', implode(', ', $metrics), false);
            $exposed[] = 'Server-Timing';
        }

        $response->headers->set(self::HEADER, sprintf(
            'id=%s;t1=%.3F;t2=%.3F;n=%s',
            $sample->id,
            $sample->received * 1000,
            $sample->sent * 1000,
            $this->nodeId(),
        ));

        if ($origin = $this->config('timing_allow_origin')) {
            $response->headers->set('Timing-Allow-Origin', $origin);

            $current = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Expose-Headers'))));
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', array_unique(array_merge($current, $exposed))));
        }
    }

    /**
     * When the web server received the request, from a source the client
     * cannot forge: a FastCGI param, or the header behind a trusted proxy.
     */
    private function receivedAt(Request $request, float $phpStart): ?float
    {
        $raw = null;

        if ($param = $this->config('request_start_server_param')) {
            $raw = $request->server($param);
        }

        if ($raw === null && ($header = $this->config('request_start_header'))) {
            $trust = $this->config('request_start_header_trust', 'proxies');

            if ($trust === 'always' || ($trust === 'proxies' && $request->isFromTrustedProxy())) {
                $raw = $request->header($header);
            }
        }

        $received = RequestStartHeader::parse(is_string($raw) ? $raw : null);

        if ($received === null) {
            return null;
        }

        // The stamp can come from another host whose clock differs slightly:
        // a stamp up to 1 s in the future counts as zero delay. Anything
        // further off, or a delay beyond the cap, is a bad stamp.
        if ($received - $phpStart > 1
            || ($phpStart - $received) * 1000 > (float) $this->config('max_delay_ms', 60000)) {
            return null;
        }

        return min($received, $phpStart);
    }

    private function pathFor(Request $request): string
    {
        $route = $request->route();

        $path = $this->config('path_mode', 'route') === 'route' && $route instanceof Route
            ? $route->uri()
            : $request->path();

        return Str::limit('/'.ltrim($path, '/'), 1000, '');
    }

    private function phpStart(Request $request, float $now): float
    {
        $candidates = [];

        if ($requestTime = $request->server('REQUEST_TIME_FLOAT')) {
            $candidates[] = (float) $requestTime;
        }

        if (defined('LARAVEL_START')) {
            $candidates[] = (float) LARAVEL_START;
        }

        // Under Octane LARAVEL_START is the worker's boot time, so take the
        // latest plausible value rather than the earliest.
        $candidates = array_filter($candidates, fn (float $t) => $t <= $now && $now - $t < 300);

        return $candidates ? max($candidates) : $now;
    }

    /**
     * Without triggering authentication: resolving the user here could run
     * a query or log someone in from a remember cookie after the response
     * was built.
     */
    private function userId(): ?string
    {
        if (! $this->config('store_user_id', true) || ! app()->bound('auth')) {
            return null;
        }

        try {
            $guard = app('auth')->guard();

            return $guard->hasUser() ? (string) $guard->id() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function newId(): string
    {
        // Time-ordered ids keep inserts at the end of the primary key index.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }
}
