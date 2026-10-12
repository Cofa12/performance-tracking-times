<?php

namespace Cofa\PerformanceTracking;

/**
 * The <script> tag behind @performanceTrackingScript.
 *
 * It loads without defer so it wraps fetch/XHR before any inline script on
 * the page sends a request, and carries the current request's timing id so
 * the page load itself can be reported, not only the AJAX calls after it.
 */
final class ClientScript
{
    public const PATH = __DIR__.'/../resources/js/client.js';

    private static ?string $version = null;

    public static function tag(): string
    {
        $recorder = app(Recorder::class);
        $id = $recorder->currentId();

        return sprintf(
            '<script src="%s" data-endpoint="%s"%s></script>',
            e(route('performance-tracking.client-script', ['v' => self::version()])),
            e(route('performance-tracking.client-report')),
            $id ? ' data-request-id="'.e($id).'" data-node="'.e($recorder->nodeId()).'"' : '',
        );
    }

    /** A content hash, so browsers fetch the script again after an upgrade. */
    public static function version(): string
    {
        return self::$version ??= (string) hash_file('crc32b', self::PATH);
    }
}
