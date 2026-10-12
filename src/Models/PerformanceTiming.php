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
 * @property ?array $queries
 * @property ?float $calc_ms
 * @property ?float $connect_ms
 * @property ?float $network_out_ms
 * @property ?float $network_return_ms
 * @property ?float $response_ms
 * @property ?string $network_split
 * @property ?\Illuminate\Support\Carbon $client_reported_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class PerformanceTiming extends Model
{
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
