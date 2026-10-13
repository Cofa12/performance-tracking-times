<?php

namespace Cofa\PerformanceTracking;

use Illuminate\Http\Request;

/**
 * The dashboard's own login, separate from the application's users.
 *
 * Enabled when a username and password are configured. The password may be
 * plain text or a password_hash() hash (see `performance-tracking:password`).
 * The session holds a fingerprint of the credentials, so changing either one
 * signs everybody out.
 */
final class DashboardAuth
{
    public const SESSION_KEY = 'performance-tracking.auth';

    public const INTENDED_KEY = 'performance-tracking.intended';

    public static function configured(): bool
    {
        return self::username() !== '' && self::password() !== '';
    }

    public static function attempt(string $username, string $password): bool
    {
        if (! self::configured()) {
            return false;
        }

        // Both checks always run, so the response time does not reveal which one failed.
        $userMatches = hash_equals(self::username(), $username);
        $passwordMatches = self::isHash(self::password())
            ? password_verify($password, self::password())
            : hash_equals(self::password(), $password);

        return $userMatches && $passwordMatches;
    }

    public static function check(Request $request): bool
    {
        return self::configured()
            && $request->hasSession()
            && hash_equals(self::fingerprint(), (string) $request->session()->get(self::SESSION_KEY, ''));
    }

    public static function login(Request $request): void
    {
        $request->session()->regenerate(); // a new session id on every sign-in
        $request->session()->put(self::SESSION_KEY, self::fingerprint());
    }

    public static function logout(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        // Only our key: the session may also belong to the application.
        $request->session()->forget([self::SESSION_KEY, self::INTENDED_KEY]);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
    }

    public static function isHash(string $value): bool
    {
        return password_get_info($value)['algo'] !== null;
    }

    private static function fingerprint(): string
    {
        return hash_hmac('sha256', self::username()."\0".self::password(), (string) config('app.key'));
    }

    private static function username(): string
    {
        return (string) config('performance-tracking.dashboard_auth.username', '');
    }

    private static function password(): string
    {
        return (string) config('performance-tracking.dashboard_auth.password', '');
    }
}
