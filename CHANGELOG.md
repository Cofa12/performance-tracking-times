# Changelog

## 2.3.1 - 2026-10-26

### Added
- Reference reporting clients for native apps: iOS (`docs/clients/RequestTiming.swift`, URLSession with async/await) and Android (`docs/clients/RequestTiming.kt`, an OkHttp interceptor that also covers Retrofit).
- Every reference client (iOS, Android, Flutter, React Native) has `measure()`, which times the work that prepares a request with a monotonic clock and reports it as calculating time; on Android `time()` does the same for Retrofit through a `@Tag`. Implausible values (over 10 minutes) are dropped instead of getting the report rejected. All four clients were run against a real server.

## 2.3.0 - 2026-10-14

### Added
- The dashboard's response time and server time tiles show the p95 against the median: how many times slower the slowest 5% of requests are than a typical one, green below 3×, amber up to 5×, red above (`p95_ratio.warning` / `p95_ratio.critical`).

### Fixed
- Dashboard tiles placed side by side no longer sit at different heights, and they stack on narrow screens.

## 2.2.0 - 2026-10-13

### Added
- The dashboard can have its own login page: set `PERFORMANCE_TRACKING_USERNAME` and `PERFORMANCE_TRACKING_PASSWORD` (plain or hashed with `php artisan performance-tracking:password`). Sign-in is rate limited, renews the session, and a credential change signs everyone out. The dashboard header gets a Sign out button; live updates answer 401 when signed out. The `viewPerformanceTracking` gate keeps working alongside it.

## 2.1.1 - 2026-10-13

### Fixed
- CI: MySQL jobs connected with the wrong password, and the per-request peak memory test ran on PHP 8.1, where `memory_reset_peak_usage()` does not exist. No change to the package's behaviour.
- README: per-request peak memory needs PHP 8.2+.

## 2.1.0 - 2026-10-13

### Added
- The dashboard updates itself while open: new requests appear every 5 seconds (`PERFORMANCE_TRACKING_REFRESH_SECONDS`, 0 turns it off), rows are highlighted when they arrive or receive their client report, polling pauses in background tabs, and a request's page refreshes until its client report arrives.
- `PERFORMANCE_TRACKING_TRUST_HEADER` sets `request_start_header_trust` from the environment (`always` for Apache/LiteSpeed `.htaccess` setups).
- README section and printable guide (`docs/frontend-setup.pdf`) for APIs with a separate web frontend or mobile app, with reference reporting clients for Flutter and React Native in `docs/clients/`.

## 2.0.0 - 2026-10-12

### Security
- `Server-Timing` (per-request query counts and durations) is no longer sent outside `local` unless `expose_server_timing` is on.
- The web server's receive stamp is read from the `REQUEST_START_TIME` FastCGI param, or from `X-Request-Start` only behind a trusted proxy (`request_start_header_trust`); stamps implying more than `max_delay_ms` of delay are ignored.
- Paths are stored as route templates (`/reset/{token}`) by default (`path_mode`); IP addresses are only stored with `store_ip`.
- Malformed client report bodies return 422 instead of a 500.
- Client reports whose send time is far from the server's receive time (an id replayed from a cached page) are rejected, as are reports with more than `max_network_ms` on the wire.
- The client report endpoint uses a `performance-tracking` rate limiter with a per-IP and a global limit.

### Fixed
- Under Octane, database queries were not counted: the query listener resolved the recorder from the base container instead of the request's.
- One invalid entry no longer discards the whole batch of client reports; the response lists `accepted` and `rejected`.
- Two concurrent reports for the same request can no longer both apply.
- Streamed responses: the body's time and its queries are recorded (`streamed`, `stream_ms`); file responses are marked as streamed.
- Peak memory is measured per request in long-running workers.
- The stored user id no longer triggers authentication (no extra query or remember-me login after the response is built).
- Dashboard: percentiles are nearest-rank and reported separately for server time and end-to-end response time; the average breakdown uses one set of requests; the summary no longer loads the query log; ordering is stable within one second; no `COUNT(*)` on every visit; search treats `%` and `_` literally on every database; invalid request ids return 404.
- Browser client: clock offsets are kept per server node; a reused XHR no longer reports earlier requests again; a calculating mark expires after a minute and `measure()` never leaks it; offsets are not used when the page clock has drifted from the wall clock; responses without a Resource Timing entry are marked `headers_only`; the fallback report request no longer needs a CORS preflight.

### Changed
- Recording is off outside `local` by default (`PERFORMANCE_TRACKING_ENABLED`).
- `performance-tracking:prune` deletes in batches (`--chunk`) and is scheduled hourly (`prune_schedule`).
- Request ids are time-ordered UUIDs (v7 on Laravel 11+).
- The client script URL is versioned and cached for a year.
- Requires `laravel/framework` rather than individual `illuminate/*` packages.
- New migration: `streamed`, `stream_ms`, `headers_only` columns and indexes on `server_received_ms` and `(status, server_received_ms)`.
