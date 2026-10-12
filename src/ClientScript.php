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
    public static function tag(): string
    {
        $id = app(Recorder::class)->currentId();

        return sprintf(
            '<script src="%s" data-endpoint="%s"%s></script>',
            e(route('performance-tracking.client-script')),
            e(route('performance-tracking.client-report')),
            $id ? ' data-request-id="'.e($id).'"' : '',
        );
    }
}
