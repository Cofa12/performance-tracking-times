<?php

namespace Cofa\PerformanceTracking;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Storage\DatabaseDriver;
use Cofa\PerformanceTracking\Storage\StorageDriver;

/**
 * Applies the client side of each exchange to its recorded request.
 *
 * Entry: {
 *   "id": "<X-Request-Timing id>",
 *   "t0": <epoch ms, request sent>,
 *   "t3": <epoch ms, response fully received>,
 *   "calc_ms": <ms spent preparing the request, optional>,
 *   "connect_ms": <ms for browser queue + DNS + TCP + TLS, optional>,
 *   "offset_ms": <server clock minus client clock, optional>,
 *   "headers_only": <true when t3 is when the headers arrived, optional>
 * }
 *
 * Client data is untrusted: every entry is checked on its own, so one bad
 * entry never costs the others in its batch.
 */
final class ClientReport
{
    public const MAX_ENTRIES = 50;

    public function __construct(private ?StorageDriver $storage = null)
    {
    }

    private function storage(): StorageDriver
    {
        return $this->storage ?? (app()->bound(StorageDriver::class) ? app(StorageDriver::class) : new DatabaseDriver());
    }

    private const RULES = [
        'id' => ['required', 'uuid'],
        't0' => ['required', 'numeric', 'min:0'],
        't3' => ['required', 'numeric', 'min:0'],
        'calc_ms' => ['nullable', 'numeric', 'min:0', 'max:600000'],
        'connect_ms' => ['nullable', 'numeric', 'min:0', 'max:600000'],
        'offset_ms' => ['nullable', 'numeric', 'between:-86400000,86400000'],
        'headers_only' => ['nullable', 'boolean'],
    ];

    /**
     * @param  array<mixed>  $entries
     * @return array{accepted: int, rejected: int}
     */
    public function apply(array $entries): array
    {
        $valid = [];
        $rejected = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry) || validator($entry, self::RULES)->fails()
                || isset($valid[strtolower($entry['id'])])) {
                $rejected++;

                continue;
            }

            $valid[strtolower($entry['id'])] = $entry;
        }

        $records = $valid === [] ? collect() : $this->storage()->findMany(
            array_keys($valid),
            ['id', 'server_received_ms', 'server_sent_ms', 'server_ms']
        );

        $accepted = 0;

        foreach ($records as $record) {
            $values = $this->values($record, $valid[strtolower($record->id)]);

            // Conditional, so two concurrent reports cannot both apply.
            if ($values !== null && $this->storage()->updateIfUnreported($record->id, $values)) {
                $accepted++;
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected + count($valid) - $accepted];
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>|null  null when the report is implausible
     */
    private function values(PerformanceTiming $record, array $client): ?array
    {
        $t0 = (float) $client['t0'];
        $t3 = (float) $client['t3'];
        $t1 = $record->server_received_ms;
        $t2 = $record->server_sent_ms;
        $maxNetwork = (float) config('performance-tracking.max_network_ms', 60000);

        // The client cannot have waited less than the server worked, nor
        // spent longer on the wire than the configured maximum.
        $network = ($t3 - $t0) - $record->server_ms;

        if ($network < -1 || $network > $maxNetwork) {
            return null;
        }

        $offset = isset($client['offset_ms']) ? (float) $client['offset_ms'] : null;

        // With an offset, t0 can be placed on the server clock. A send time
        // far from when the server received the request means the id came
        // from a cached page or response, not from this exchange.
        if ($offset !== null && abs(($t1 - $offset) - $t0) > $maxNetwork) {
            return null;
        }

        $split = NetworkSplit::compute($t0, $t1, $t2, $t3, $offset);
        $calc = isset($client['calc_ms']) ? (float) $client['calc_ms'] : null;
        $connect = isset($client['connect_ms']) ? (float) $client['connect_ms'] : null;

        return [
            'calc_ms' => $calc,
            'connect_ms' => $connect,
            'network_out_ms' => $split->outMs,
            'network_return_ms' => $split->returnMs,
            'clock_offset_ms' => $offset,
            'network_split' => $split->method,
            'headers_only' => filter_var($client['headers_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'response_ms' => ($calc ?? 0) + ($connect ?? 0) + $split->outMs + $record->server_ms + $split->returnMs,
            'client_reported_at' => now(),
        ];
    }
}
