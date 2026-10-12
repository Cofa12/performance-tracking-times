<?php

namespace Cofa\PerformanceTracking;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
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

    private ?array $current = null;

    public function __construct(private readonly Repository $config)
    {
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get('performance-tracking.'.$key, $default);
    }

    public function shouldRecord(Request $request): bool
    {
        if (! $this->config('enabled', true)) {
            return false;
        }

        if (in_array($request->getMethod(), $this->config('ignore_methods', []), true)) {
            return false;
        }

        $path = trim($this->config('path', 'performance-tracking'), '/');
        $ignored = array_merge([$path, $path.'/*'], $this->config('ignore_paths', []));

        if ($request->is(...$ignored)) {
            return false;
        }

        $rate = (float) $this->config('sample_rate', 1.0);

        return $rate >= 1.0 || ($rate > 0 && mt_rand() / mt_getrandmax() < $rate);
    }

    public function start(Request $request): void
    {
        $now = microtime(true);
        $phpStart = $this->phpStart($request, $now);
        $received = RequestStartHeader::parse($request->header($this->config('request_start_header', 'X-Request-Start')));

        // The stamp can come from another host whose clock differs slightly.
        // Small skew is clamped to zero delay; anything absurd is ignored.
        if ($received !== null && ($received - $phpStart > 1 || $phpStart - $received > 300)) {
            $received = null;
        }

        $this->current = [
            'id' => (string) Str::uuid(),
            'received' => $received !== null ? min($received, $phpStart) : $phpStart,
            'delay_measured' => $received !== null,
            'php_start' => $phpStart,
            'app_start' => $now,
            'db_ms' => 0.0,
            'db_count' => 0,
            'queries' => [],
            'sent' => null,
        ];
    }

    public function recordQuery(QueryExecuted $event): void
    {
        if ($this->current === null || $this->current['sent'] !== null) {
            return;
        }

        $this->current['db_ms'] += (float) $event->time;
        $this->current['db_count']++;

        if ($this->config('record_queries', true)
            && count($this->current['queries']) < (int) $this->config('query_limit', 50)) {
            $startedAt = microtime(true) - $event->time / 1000;

            $this->current['queries'][] = [
                'sql' => Str::limit($event->sql, 2000),
                'connection' => $event->connectionName,
                'start_ms' => round(($startedAt - $this->current['received']) * 1000, 3),
                'ms' => round((float) $event->time, 3),
            ];
        }
    }

    public function stop(Request $request, Response $response): void
    {
        if ($this->current === null) {
            return;
        }

        $sent = microtime(true);
        $c = &$this->current;
        $c['sent'] = $sent;
        $c['method'] = $request->getMethod();
        $c['path'] = Str::limit('/'.ltrim($request->path(), '/'), 1000, '');
        $c['route_name'] = $request->route()?->getName();
        $c['status'] = $response->getStatusCode();
        $c['ip'] = $request->ip();
        $c['user_id'] = $this->userId($request);

        $delay = $c['delay_measured'] ? ($c['php_start'] - $c['received']) * 1000 : null;
        $boot = ($c['app_start'] - $c['php_start']) * 1000;
        $app = ($sent - $c['app_start']) * 1000;

        $c['delay_ms'] = $delay;
        $c['boot_ms'] = $boot;
        $c['app_ms'] = $app;

        $this->addHeaders($response, $delay, $boot, $app);
        unset($c);
    }

    public function persist(): void
    {
        $c = $this->current;
        $this->current = null; // our own insert must not be recorded

        if ($c === null || $c['sent'] === null) {
            return;
        }

        try {
            PerformanceTiming::create([
                'id' => $c['id'],
                'method' => $c['method'],
                'path' => $c['path'],
                'route_name' => $c['route_name'],
                'status' => $c['status'],
                'ip' => $c['ip'],
                'user_id' => $c['user_id'],
                // As strings: PHP casts floats with 14 significant digits,
                // which would drop the fraction of an epoch-ms timestamp.
                'server_received_ms' => sprintf('%.3F', $c['received'] * 1000),
                'server_sent_ms' => sprintf('%.3F', $c['sent'] * 1000),
                'delay_ms' => $c['delay_ms'],
                'boot_ms' => $c['boot_ms'],
                'app_ms' => $c['app_ms'],
                'service_ms' => $c['boot_ms'] + $c['app_ms'],
                'server_ms' => ($c['sent'] - $c['received']) * 1000,
                'db_ms' => $c['db_ms'],
                'db_count' => $c['db_count'],
                'queries' => $c['queries'] ?: null,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Monitoring must never break the application.
            Log::warning('performance-tracking: could not store timing', ['exception' => $e->getMessage()]);
        }
    }

    public function currentId(): ?string
    {
        return $this->current['id'] ?? null;
    }

    private function addHeaders(Response $response, ?float $delay, float $boot, float $app): void
    {
        $c = $this->current;
        $metrics = [];

        if ($delay !== null) {
            $metrics[] = sprintf('delay;dur=%.3F;desc="Queue"', $delay);
        }

        $metrics[] = sprintf('boot;dur=%.3F;desc="Framework boot"', $boot);
        $metrics[] = sprintf('app;dur=%.3F;desc="Middleware + controller"', $app);
        $metrics[] = sprintf('db;dur=%.3F;desc="%d queries"', $c['db_ms'], $c['db_count']);
        $metrics[] = sprintf('service;dur=%.3F', $boot + $app);

        $response->headers->set('Server-Timing', implode(', ', $metrics), false);
        $response->headers->set(self::HEADER, sprintf(
            'id=%s;t1=%.3F;t2=%.3F',
            $c['id'],
            $c['received'] * 1000,
            $c['sent'] * 1000,
        ));

        if ($origin = $this->config('timing_allow_origin')) {
            $response->headers->set('Timing-Allow-Origin', $origin);

            $exposed = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Expose-Headers'))));
            $exposed = array_unique(array_merge($exposed, [self::HEADER, 'Server-Timing']));
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $exposed));
        }
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

    private function userId(Request $request): ?string
    {
        try {
            $id = $request->user()?->getAuthIdentifier();

            return $id === null ? null : (string) $id;
        } catch (Throwable) {
            return null;
        }
    }
}
