# Performance Tracking Times

A Laravel package that breaks every request's response time into its parts and shows each one as a waterfall diagram:

| Segment | What it is | Measured by |
|---|---|---|
| **Calculating** | Time the client spends preparing the request (building the payload, serialising, signing) | Client |
| **Network out** | The request travelling to the server, including connection setup (browser queue, DNS, TCP, TLS) | Client + server |
| **Delay** | Time the request waits in the web server or PHP-FPM queue before a worker picks it up | Web server + PHP |
| **Service** | Time the server works on the request: framework boot, middleware, controller, and the database queries inside it | Server |
| **Network return** | The response travelling back and downloading | Client + server |
| **Response time** | The sum of the segments above, as the user experiences it | |

The dashboard at `/performance-tracking` lists recent requests with a stacked bar for each one, plus averages and percentiles. Open a request to see its waterfall, a table of the values, and the SQL of its queries placed on the timeline.

![Waterfall diagram of one request](docs/waterfall.jpg)

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12 or 13

## Installation

```bash
composer require cofa/performance-tracking-times
php artisan migrate
```

The service provider is auto-discovered. It prepends the recording middleware to the global middleware stack, so it wraps every other middleware and route.

To publish the config:

```bash
php artisan vendor:publish --tag=performance-tracking-config
```

## 1. Server side: delay and service time

This part works as soon as the package is installed. Service time (framework boot plus middleware and controller) and database time are measured for every request.

To measure **delay** (queue time), the web server has to stamp the time it received the request. With nginx in front of PHP-FPM:

```nginx
location ~ \.php$ {
    fastcgi_param HTTP_X_REQUEST_START "t=${msec}";
    # ...
}
```

If nginx is a reverse proxy instead:

```nginx
proxy_set_header X-Request-Start "t=${msec}";
```

With Apache, use `RequestHeader set X-Request-Start "%t"`. Without this header the dashboard shows delay as "not measured".

Every recorded response carries two headers:

```
Server-Timing: delay;dur=8.7;desc="Queue", boot;dur=17.5;desc="Framework boot", app;dur=238;desc="Middleware + controller", db;dur=4.0;desc="8 queries", service;dur=255.5
X-Request-Timing: id=<uuid>;t1=<server received, epoch ms>;t2=<server sent, epoch ms>
```

Browser devtools show the `Server-Timing` values in the Network tab under Timing.

## 2. Client side: calculating and network time

The server cannot measure the network legs on its own. Only the client knows when the request left and when the response finished arriving, so the client reports those timestamps back.

### Browser (Blade, Inertia, Livewire, any JS frontend)

Add the script to your layout's `<head>`, before any other script:

```blade
@performanceTrackingScript
```

It wraps `fetch` and `XMLHttpRequest` (so axios and jQuery work too), reads precise timestamps from the Resource Timing API, and sends them in batches with `navigator.sendBeacon`. It never blocks or changes your requests.

On a server-rendered page, the tag also carries that page's timing id, so the **page load itself** gets network times (from the Navigation Timing API), not only the AJAX calls made after it. The first page of a visit splits network time evenly; once the browser has made an AJAX call it knows the clock offset and splits it precisely.

For a frontend on another origin (React, Vue, …), load it directly instead:

```html
<script>window.RequestTimingConfig = { endpoint: 'https://api.example.com/performance-tracking/api/client' };</script>
<script src="https://api.example.com/performance-tracking/client.js"></script>
```

To also measure **calculating** time, wrap the work that comes before a request:

```js
await RequestTiming.measure(() => {
    const body = buildExpensivePayload();   // counted as "calculating"
    return fetch('/api/orders', { method: 'POST', body });
});

// or mark the start yourself; the next request picks it up
RequestTiming.markCalculating();
```

### Mobile apps and other clients

Read the `X-Request-Timing` header from the response and POST your timestamps to `/performance-tracking/api/client`:

```json
{
  "timings": [{
    "id": "<id from X-Request-Timing>",
    "t0": 1791807203600.12,
    "t3": 1791807203910.55,
    "calc_ms": 12.5,
    "connect_ms": 3.1,
    "offset_ms": -4.2
  }]
}
```

- `t0` is when the request was sent and `t3` is when the response finished arriving, both in epoch milliseconds on the client clock. Both are required.
- `calc_ms`, `connect_ms` and `offset_ms` are optional.
- `offset_ms` is the server clock minus the client clock. Estimate it from the request with the lowest round trip: `((t1 - t0) + (t2 - t3)) / 2`, using `t1` and `t2` from the header. Without it, the network time is split evenly between the two directions.
- Send up to 50 timings per call, within 10 minutes of the request. Each request accepts one report.

## How the network split works

```
client   t0 ──── network out ────▶                              ◀──── network return ──── t3
server                            t1 ─ delay ─ boot ─ app ─ t2
```

- `(t3 − t0) − (t2 − t1)` is the total time on the wire. It does not depend on the client and server clocks agreeing.
- Splitting that total into the out and return legs does depend on the clocks. The client keeps the clock offset measured on its fastest exchange (Cristian's algorithm) and sends it with each report. The server uses it to place `t1` and `t2` on the client's clock.
- If the offset drifts, a leg is clamped at zero so the total stays correct.

## Dashboard access

In the `local` environment the dashboard is open to anyone. Everywhere else, define a gate:

```php
// App\Providers\AppServiceProvider::boot()
Gate::define('viewPerformanceTracking', fn ($user) => $user->is_admin);
```

## Configuration highlights

| Key | Default | |
|---|---|---|
| `enabled` | `true` | `PERFORMANCE_TRACKING_ENABLED` |
| `sample_rate` | `1.0` | Record this fraction of requests; lower it on busy servers |
| `path` | `performance-tracking` | Dashboard URL prefix |
| `ignore_paths` / `ignore_methods` | telescope, horizon, … / `OPTIONS`, `HEAD` | |
| `record_queries` / `query_limit` | `true` / `50` | SQL is stored with placeholders, never bindings |
| `timing_allow_origin` | `null` | Set it when the frontend runs on another origin, so the browser exposes the headers |
| `connection` / `table` | default / `performance_timings` | Keep timings in a separate database if you like |
| `retention_hours` | `48` | Used by the prune command |

## Pruning

Schedule the prune command:

```php
Schedule::command('performance-tracking:prune')->hourly();
```

## Notes

- Timings are written in the middleware's `terminate` step, after the response has been sent. Storing them does not add to the response time.
- Under Octane the recorder is scoped per request, and the PHP start time comes from `REQUEST_TIME_FLOAT`, not `LARAVEL_START`.
- Full-page navigations are reported only for pages that include `@performanceTrackingScript` (it carries the page's timing id). A frontend on another origin reports its `fetch`/XHR calls.

## Testing

```bash
composer test
```

## License

MIT
