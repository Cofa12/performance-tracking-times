<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / disable recording
    |--------------------------------------------------------------------------
    */
    'enabled' => env('PERFORMANCE_TRACKING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Sample rate
    |--------------------------------------------------------------------------
    | Fraction of requests to record, between 0.0 and 1.0. Use a lower value
    | on busy production servers.
    */
    'sample_rate' => (float) env('PERFORMANCE_TRACKING_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Register the middleware globally
    |--------------------------------------------------------------------------
    | When true the recording middleware is prepended to the global middleware
    | stack, so it wraps every other middleware. Set to false to attach
    | \Cofa\PerformanceTracking\Http\Middleware\RecordPerformance yourself.
    */
    'auto_register_middleware' => true,

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    | Outside the "local" environment the dashboard is only reachable when the
    | "viewPerformanceTracking" gate allows it. Define it in a service provider:
    |
    |   Gate::define('viewPerformanceTracking', fn ($user) => $user->is_admin);
    */
    'path' => env('PERFORMANCE_TRACKING_PATH', 'performance-tracking'),

    'domain' => env('PERFORMANCE_TRACKING_DOMAIN'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Client report endpoint
    |--------------------------------------------------------------------------
    | Browsers (via the bundled script) and mobile apps POST their timestamps
    | here so the network legs and the calculating time can be computed.
    */
    'client_middleware' => ['throttle:120,1'],

    // Client reports older than this (after the request was recorded) are rejected.
    'client_report_window_minutes' => 10,

    /*
    |--------------------------------------------------------------------------
    | Delay (queue) time
    |--------------------------------------------------------------------------
    | The header your web server stamps when it receives the request. nginx:
    |
    |   proxy_set_header X-Request-Start "t=${msec}";     # reverse proxy
    |   fastcgi_param HTTP_X_REQUEST_START "t=${msec}";   # php-fpm
    |
    | Without it, delay time cannot be measured and is shown as "not measured".
    */
    'request_start_header' => 'X-Request-Start',

    /*
    |--------------------------------------------------------------------------
    | Cross-origin clients
    |--------------------------------------------------------------------------
    | Set to an origin (or "*") when the frontend runs on another origin, so
    | the browser exposes the timing headers to JavaScript.
    */
    'timing_allow_origin' => env('PERFORMANCE_TRACKING_ALLOW_ORIGIN'),

    /*
    |--------------------------------------------------------------------------
    | What to ignore
    |--------------------------------------------------------------------------
    | Paths matched with Request::is(). The dashboard path is always ignored.
    */
    'ignore_paths' => [
        'telescope*',
        'horizon*',
        '_debugbar*',
        'up',
    ],

    'ignore_methods' => ['OPTIONS', 'HEAD'],

    /*
    |--------------------------------------------------------------------------
    | Database queries
    |--------------------------------------------------------------------------
    | Query SQL (with placeholders, never bindings) is stored so it can be
    | drawn inside the service time. Totals are always recorded.
    */
    'record_queries' => true,

    'query_limit' => 50,

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */
    'connection' => env('PERFORMANCE_TRACKING_DB_CONNECTION'),

    'table' => 'performance_timings',

    // Used by `php artisan performance-tracking:prune`.
    'retention_hours' => 48,

    /*
    |--------------------------------------------------------------------------
    | Slow request threshold (ms)
    |--------------------------------------------------------------------------
    */
    'slow_threshold_ms' => 1000,
];
