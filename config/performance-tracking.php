<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / disable recording
    |--------------------------------------------------------------------------
    | Off outside the "local" environment unless you turn it on explicitly, so
    | installing the package never starts writing a row per request in
    | production by surprise. When you enable it there, lower the sample rate.
    */
    'enabled' => (bool) env('PERFORMANCE_TRACKING_ENABLED', env('APP_ENV') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Sample rate
    |--------------------------------------------------------------------------
    | Fraction of requests to record, between 0.0 and 1.0. Use a lower value
    | (0.01 - 0.1) on busy production servers.
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
    | Give the dashboard its own login page by setting a username and
    | password; it then works the same in every environment. The password
    | may be plain text or a hash from `php artisan performance-tracking:password`.
    |
    | Without them, the dashboard is open in "local" and elsewhere reachable
    | only when the "viewPerformanceTracking" gate allows the signed-in user:
    |
    |   Gate::define('viewPerformanceTracking', fn ($user) => $user->is_admin);
    */
    'dashboard_auth' => [
        'username' => env('PERFORMANCE_TRACKING_USERNAME'),
        'password' => env('PERFORMANCE_TRACKING_PASSWORD'),
    ],

    'path' => env('PERFORMANCE_TRACKING_PATH', 'performance-tracking'),

    'domain' => env('PERFORMANCE_TRACKING_DOMAIN'),

    'middleware' => ['web'],

    // The dashboard fetches new requests this often (seconds) while it is
    // open and visible, so it updates without reloading. 0 turns it off.
    'dashboard_refresh_seconds' => (int) env('PERFORMANCE_TRACKING_REFRESH_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Client report endpoint
    |--------------------------------------------------------------------------
    | Browsers (via the bundled script) and mobile apps POST their timestamps
    | here so the network legs and the calculating time can be computed.
    |
    | The "performance-tracking" rate limiter allows `per_ip` reports per
    | minute from one address and `global` per minute in total. Behind a load
    | balancer, configure TrustProxies, or every client shares one address.
    */
    'client_middleware' => ['throttle:performance-tracking'],

    'client_rate_limit' => [
        'per_ip' => 120,
        'global' => 10000,
    ],

    // Client reports older than this (after the request was recorded) are rejected.
    'client_report_window_minutes' => 10,

    // Reports claiming more network time than this are rejected as implausible.
    'max_network_ms' => 60000,

    /*
    |--------------------------------------------------------------------------
    | Delay (queue) time
    |--------------------------------------------------------------------------
    | The web server stamps the time it received the request. Preferred, with
    | nginx in front of PHP-FPM (clients cannot set FastCGI params):
    |
    |   fastcgi_param REQUEST_START_TIME "t=${msec}";
    |
    | With a reverse proxy, the header is used instead:
    |
    |   proxy_set_header X-Request-Start "t=${msec}";
    |
    | Clients can send that header themselves, so by default it is only
    | honoured when the request came through a trusted proxy (TrustProxies).
    | "always" trusts it from anyone, "never" ignores it.
    |
    | Without either, delay time is shown as "not measured".
    */
    'request_start_server_param' => 'REQUEST_START_TIME',

    'request_start_header' => 'X-Request-Start',

    'request_start_header_trust' => env('PERFORMANCE_TRACKING_TRUST_HEADER', 'proxies'), // "proxies" | "always" | "never"

    // A larger delay than this is treated as a bad stamp and ignored.
    'max_delay_ms' => 60000,

    /*
    |--------------------------------------------------------------------------
    | Response headers
    |--------------------------------------------------------------------------
    | X-Request-Timing (an id and two timestamps) is always sent, the client
    | needs it. Server-Timing reveals per-request query counts and durations
    | to anyone, which helps timing attacks, so it is off outside "local".
    */
    'expose_server_timing' => (bool) env('PERFORMANCE_TRACKING_SERVER_TIMING', env('APP_ENV') === 'local'),

    // Set to an origin (or "*") when the frontend runs on another origin, so
    // the browser exposes the timing headers to JavaScript.
    'timing_allow_origin' => env('PERFORMANCE_TRACKING_ALLOW_ORIGIN'),

    // Identifies this server in X-Request-Timing so clients keep a separate
    // clock offset per node behind a load balancer. Defaults to a short hash
    // of the hostname.
    'node' => env('PERFORMANCE_TRACKING_NODE'),

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
    | Stored data
    |--------------------------------------------------------------------------
    | "route" stores the matched route's URI ("/reset/{token}"), so tokens in
    | URLs never reach the table; unmatched requests keep their raw path.
    | "raw" stores the path as requested.
    */
    'path_mode' => 'route', // "route" | "raw"

    'store_ip' => false,

    'store_user_id' => true,

    /*
    |--------------------------------------------------------------------------
    | Database queries
    |--------------------------------------------------------------------------
    | Query SQL is stored as Laravel sent it: with placeholders, never the
    | bindings. Values written into the SQL itself (raw expressions,
    | whereIntegerInRaw) are stored as written. Totals are always recorded.
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

    // The prune command is scheduled with this cron expression when the
    // application runs `schedule:run`. Null leaves scheduling to you.
    'prune_schedule' => '17 * * * *',

    /*
    |--------------------------------------------------------------------------
    | Slow request threshold (ms)
    |--------------------------------------------------------------------------
    */
    'slow_threshold_ms' => 1000,
];
