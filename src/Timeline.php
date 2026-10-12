<?php

namespace Cofa\PerformanceTracking;

use Cofa\PerformanceTracking\Models\PerformanceTiming;

/**
 * Lays a recorded request out on one time axis for the diagrams.
 *
 * Order: calculating -> connect -> network out -> delay -> service
 * (boot, app, db queries) -> network return.
 */
final class Timeline
{
    /** Segment kinds, in the order their colors are assigned. */
    public const KINDS = [
        'network' => 'Network',
        'calc' => 'Calculating',
        'delay' => 'Delay',
        'service' => 'Service',
    ];

    /**
     * @param  array<int, array{key: string, kind: string, label: string, start: float, ms: float, depth: int, detail?: string}>  $rows
     * @param  array<int, array{kind: string, label: string, ms: float}>  $stack
     * @param  array<int, array{label: string, reason: string}>  $missing
     */
    private function __construct(
        public readonly float $total,
        public readonly array $rows,
        public readonly array $stack,
        public readonly array $missing,
    ) {
    }

    public static function for(PerformanceTiming $t): self
    {
        $rows = [];
        $stack = [];
        $missing = [];
        $cursor = 0.0;

        $add = function (string $key, string $kind, string $label, float $ms, int $depth = 0, ?string $detail = null) use (&$rows, &$cursor) {
            $rows[] = ['key' => $key, 'kind' => $kind, 'label' => $label, 'start' => $cursor, 'ms' => max(0.0, $ms), 'depth' => $depth, 'detail' => $detail];
            $cursor += max(0.0, $ms);
        };

        if ($t->hasClientData()) {
            if ($t->calc_ms !== null) {
                $add('calc', 'calc', 'Calculating', $t->calc_ms, 0, 'Client prepares the request');
                $stack[] = ['kind' => 'calc', 'label' => 'Calculating', 'ms' => $t->calc_ms];
            } else {
                $missing[] = ['label' => 'Calculating', 'reason' => 'The client did not mark a calculating start (RequestTiming.measure()).'];
            }

            $connect = (float) ($t->connect_ms ?? 0);
            $out = $connect + (float) $t->network_out_ms;
            $rows[] = ['key' => 'network_out', 'kind' => 'network', 'label' => 'Network out', 'start' => $cursor, 'ms' => $out, 'depth' => 0,
                'detail' => $t->network_split === 'symmetric' ? 'Estimated as half of the network time' : 'Request travels to the server'];
            if ($connect > 0) {
                $rows[] = ['key' => 'connect', 'kind' => 'network', 'label' => 'Connect', 'start' => $cursor, 'ms' => $connect, 'depth' => 1, 'detail' => 'Browser queue + DNS + TCP + TLS'];
            }
            $cursor += $out;
            $stack[] = ['kind' => 'network', 'label' => 'Network out', 'ms' => $out];
        } else {
            $missing[] = ['label' => 'Calculating & network', 'reason' => 'No client report for this request. Load the client script or post timings from your app.'];
        }

        $serverStart = $cursor;

        if ($t->delay_ms !== null) {
            $add('delay', 'delay', 'Delay', $t->delay_ms, 0, 'Waiting for a PHP worker');
            $stack[] = ['kind' => 'delay', 'label' => 'Delay', 'ms' => $t->delay_ms];
        } else {
            $missing[] = ['label' => 'Delay', 'reason' => 'The web server did not send an X-Request-Start header.'];
        }

        $rows[] = ['key' => 'service', 'kind' => 'service', 'label' => 'Service', 'start' => $cursor, 'ms' => $t->service_ms, 'depth' => 0, 'detail' => 'Server works on the request'];
        $stack[] = ['kind' => 'service', 'label' => 'Service', 'ms' => $t->service_ms];
        $add('boot', 'service', 'Framework boot', $t->boot_ms, 1);
        $add('app', 'service', 'Middleware + controller', $t->app_ms, 1);

        foreach ($t->queries ?? [] as $i => $q) {
            $rows[] = [
                'key' => 'query-'.$i,
                'kind' => 'service',
                'label' => 'Query '.($i + 1),
                'start' => $serverStart + max(0.0, (float) $q['start_ms']),
                'ms' => (float) $q['ms'],
                'depth' => 2,
                'detail' => $q['sql'],
            ];
        }

        if ($t->hasClientData()) {
            $add('network_return', 'network', 'Network return', (float) $t->network_return_ms, 0, 'Response travels back and downloads');
            $stack[] = ['kind' => 'network', 'label' => 'Network return', 'ms' => (float) $t->network_return_ms];
        }

        return new self(max($cursor, 0.001), $rows, $stack, $missing);
    }
}
