<?php

namespace Cofa\PerformanceTracking\Storage;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * StorageDriver that appends newline-delimited JSON (NDJSON) to disk.
 *
 * Each line can be encrypted using the application's encrypter (AES-256-CBC/GCM)
 * so sensitive request data (URLs, SQL queries, user IDs) is never stored in plain text.
 *
 * Key advantages:
 * - ZERO database connections used during request recording.
 * - Append-only operations with POSIX file locking (flock) for worker concurrency.
 * - Encrypted at rest.
 * - Fast and isolated from database connection pool exhaustion.
 */
final class FileDriver implements StorageDriver
{
    public function __construct(
        private readonly string $directory,
        private readonly ?Encrypter $encrypter = null,
        private readonly bool $encrypt = true,
    ) {}

    public function write(array $attributes): void
    {
        $this->append($attributes);
    }

    public function find(string $id, array $columns = ['*']): ?PerformanceTiming
    {
        return $this->allRecords()->first(fn (PerformanceTiming $t) => $t->id === $id);
    }

    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        $window = (int) config('performance-tracking.client_report_window_minutes', 10);
        $cutoff = now()->subMinutes($window);

        return $this->allRecords()->filter(
            fn (PerformanceTiming $t) =>
                in_array($t->id, $ids, true)
                && $t->client_reported_at === null
                && $t->created_at->greaterThanOrEqualTo($cutoff)
        )->values();
    }

    public function updateIfUnreported(string $id, array $values): bool
    {
        $record = $this->find($id);

        if ($record === null || $record->client_reported_at !== null) {
            return false;
        }

        // Append a patch entry; reading merges chronologically by id.
        $this->append(array_merge(['id' => $id], $values));

        return true;
    }

    public function query(): QueryScope
    {
        return new FileQueryScope($this->allRecords());
    }

    public function prune(Carbon $cutoff, int $chunk = 5000): int
    {
        $deleted = 0;

        foreach ($this->allFiles() as $file) {
            $records = $this->readFile($file);
            $kept = [];

            foreach ($records as $id => $attrs) {
                $createdAt = isset($attrs['created_at'])
                    ? Carbon::parse($attrs['created_at'])
                    : null;

                if ($createdAt !== null && $createdAt->lessThan($cutoff)) {
                    $deleted++;
                } else {
                    $kept[$id] = $attrs;
                }
            }

            if (count($kept) === 0) {
                @unlink($file);
            } elseif (count($kept) < count($records)) {
                $this->rewriteFile($file, $kept);
            }
        }

        return $deleted;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypt && $this->encrypter !== null;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    // -------------------------------------------------------------------------
    // Internal File Operations
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function append(array $attributes): void
    {
        $this->ensureDirectoryExists();

        $file = $this->currentFile();
        $json = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $line = ($this->isEncrypted() && $this->encrypter !== null)
            ? $this->encrypter->encrypt($json, false)
            : $json;

        $handle = @fopen($file, 'a');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $line."\n");
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Collection<int, PerformanceTiming>
     */
    public function allRecords(): Collection
    {
        /** @var array<string, array<string, mixed>> $merged */
        $merged = [];

        foreach ($this->allFiles() as $file) {
            foreach ($this->readFile($file) as $id => $attrs) {
                $merged[$id] = array_merge($merged[$id] ?? [], $attrs);
            }
        }

        return collect(array_values($merged))->map(fn (array $attrs) => $this->hydrate($attrs));
    }

    /**
     * @return array<string, array<string, mixed>> id => attributes
     */
    public function readFile(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return [];
        }

        $records = [];

        try {
            flock($handle, LOCK_SH);

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $attrs = null;

                if ($this->encrypter !== null) {
                    try {
                        $decrypted = $this->encrypter->decrypt($line, false);
                        $attrs = json_decode((string) $decrypted, true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        // Fall back to plain JSON in case unencrypted entries exist
                    }
                }

                if ($attrs === null) {
                    try {
                        $attrs = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        continue; // skip corrupt/tampered lines
                    }
                }

                if (! is_array($attrs) || ! isset($attrs['id'])) {
                    continue;
                }

                $id = (string) $attrs['id'];
                $records[$id] = array_merge($records[$id] ?? [], $attrs);
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $records;
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     */
    private function rewriteFile(string $file, array $records): void
    {
        $tmp = $file.'.tmp.'.getmypid().'.'.hrtime(true);
        $handle = @fopen($tmp, 'w');

        if ($handle === false) {
            return;
        }

        foreach ($records as $attrs) {
            $json = json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line = ($this->isEncrypted() && $this->encrypter !== null)
                ? $this->encrypter->encrypt($json, false)
                : $json;

            fwrite($handle, $line."\n");
        }

        fclose($handle);
        rename($tmp, $file);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hydrate(array $attributes): PerformanceTiming
    {
        $model = (new PerformanceTiming)->forceFill($attributes);

        if (isset($attributes['created_at'])) {
            $model->created_at = Carbon::parse($attributes['created_at']);
        }

        if (isset($attributes['client_reported_at'])) {
            $model->client_reported_at = Carbon::parse($attributes['client_reported_at']);
        }

        return $model;
    }

    private function currentFile(): string
    {
        return $this->directory.'/timings-'.now()->toDateString().'.ndjson';
    }

    /**
     * @return list<string>
     */
    public function allFiles(): array
    {
        $pattern = $this->directory.'/timings-*.ndjson';
        $files = glob($pattern) ?: [];
        sort($files);

        return $files;
    }

    private function ensureDirectoryExists(): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }
}
