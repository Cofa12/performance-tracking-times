<?php

namespace Cofa\PerformanceTracking;

/**
 * The server-side measurements of one request while it is being recorded.
 * All timestamps are epoch seconds on the server clock.
 */
final class Sample
{
    /** Web server stamp, when one was present and trusted; otherwise php start. */
    public float $received;

    public ?float $delayMs = null;

    public ?float $sent = null;

    public float $dbMs = 0.0;

    public int $dbCount = 0;

    /** @var array<int, array{sql: string, connection: ?string, start_ms: float, ms: float}> */
    public array $queries = [];

    public bool $streamed = false;

    public bool $streaming = false;

    public ?float $streamMs = null;

    /** A streamed body has not been produced yet. */
    public bool $awaitingBody = false;

    /** terminate() ran before the streamed body finished; store once it does. */
    public bool $persistRequested = false;

    /** @var array<string, mixed> */
    public array $request = [];

    public float $bootMs = 0.0;

    public float $appMs = 0.0;

    public function __construct(
        public readonly string $id,
        public readonly float $phpStart,
        public readonly float $appStart,
    ) {
        $this->received = $phpStart;
    }

    /** Whether queries should still be counted. */
    public function isOpen(): bool
    {
        return $this->sent === null || $this->streaming;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->request + [
            'id' => $this->id,
            // As strings: PHP casts floats with 14 significant digits,
            // which would drop the fraction of an epoch-ms timestamp.
            'server_received_ms' => sprintf('%.3F', $this->received * 1000),
            'server_sent_ms' => sprintf('%.3F', $this->sent * 1000),
            'delay_ms' => $this->delayMs,
            'boot_ms' => $this->bootMs,
            'app_ms' => $this->appMs,
            'service_ms' => $this->bootMs + $this->appMs,
            'server_ms' => ($this->sent - $this->received) * 1000,
            'db_ms' => $this->dbMs,
            'db_count' => $this->dbCount,
            'queries' => $this->queries ?: null,
            'streamed' => $this->streamed,
            'stream_ms' => $this->streamMs,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'created_at' => now(),
        ];
    }
}
