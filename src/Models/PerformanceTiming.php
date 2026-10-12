<?php

namespace Cofa\PerformanceTracking\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $method
 * @property string $path
 * @property ?string $route_name
 * @property int $status
 * @property float $server_received_ms
 * @property float $server_sent_ms
 * @property ?float $delay_ms
 * @property float $boot_ms
 * @property float $app_ms
 * @property float $service_ms
 * @property float $server_ms
 * @property float $db_ms
 * @property int $db_count
 * @property ?array<int, array{sql: string, connection: ?string, start_ms: float, ms: float}> $queries
 * @property ?float $calc_ms
 * @property ?float $connect_ms
 * @property ?float $network_out_ms
 * @property ?float $network_return_ms
 * @property ?float $response_ms
 * @property ?string $network_split
 * @property bool $streamed
 * @property ?float $stream_ms
 * @property bool $headers_only
 * @property ?float $memory_peak_mb
 * @property ?string $user_id
 * @property ?\Illuminate\Support\Carbon $client_reported_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class PerformanceTiming extends Model
{
    /** Every column but the query log, which can be large: for lists and summaries. */
    public const SUMMARY_COLUMNS = [
        'id', 'method', 'path', 'route_name', 'status', 'user_id',
        'server_received_ms', 'server_sent_ms',
        'delay_ms', 'boot_ms', 'app_ms', 'service_ms', 'server_ms', 'db_ms', 'db_count', 'memory_peak_mb',
        'calc_ms', 'connect_ms', 'network_out_ms', 'network_return_ms', 'clock_offset_ms', 'response_ms',
        'network_split', 'streamed', 'stream_ms', 'headers_only', 'client_reported_at', 'created_at',
    ];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'queries' => 'array',
        'server_received_ms' => 'float',
        'server_sent_ms' => 'float',
        'delay_ms' => 'float',
        'boot_ms' => 'float',
        'app_ms' => 'float',
        'service_ms' => 'float',
        'server_ms' => 'float',
        'db_ms' => 'float',
        'db_count' => 'integer',
        'status' => 'integer',
        'memory_peak_mb' => 'float',
        'calc_ms' => 'float',
        'connect_ms' => 'float',
        'network_out_ms' => 'float',
        'network_return_ms' => 'float',
        'clock_offset_ms' => 'float',
        'response_ms' => 'float',
        'streamed' => 'boolean',
        'stream_ms' => 'float',
        'headers_only' => 'boolean',
        'client_reported_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function getConnectionName()
    {
        return config('performance-tracking.connection') ?? parent::getConnectionName();
    }

    public function getTable()
    {
        return config('performance-tracking.table', 'performance_timings');
    }

    public function hasClientData(): bool
    {
        return $this->client_reported_at !== null;
    }

    /**
     * The end-to-end response time when the client reported, otherwise the
     * time the server spent on the request.
     */
    public function totalMs(): float
    {
        return $this->response_ms ?? $this->server_ms;
    }
}
