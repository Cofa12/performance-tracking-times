<?php

namespace Cofa\PerformanceTracking\Http\Controllers;

use Cofa\PerformanceTracking\DashboardAuth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public function show(Request $request): View|RedirectResponse
    {
        abort_unless(DashboardAuth::configured(), 404);

        if (DashboardAuth::check($request)) {
            return redirect()->route('performance-tracking.index');
        }

        return view('performance-tracking::login');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(DashboardAuth::configured(), 404);

        $key = 'performance-tracking-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->failed('Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        if (! DashboardAuth::attempt($credentials['username'], $credentials['password'])) {
            RateLimiter::hit($key, 60);

            return $this->failed('Wrong username or password.');
        }

        RateLimiter::clear($key);
        $intended = (string) $request->session()->pull(DashboardAuth::INTENDED_KEY, '');
        DashboardAuth::login($request);

        // Only back into the dashboard, never to another address.
        $home = route('performance-tracking.index');

        return redirect()->to($intended !== '' && Str::startsWith($intended, $home) ? $intended : $home);
    }

    public function destroy(Request $request): RedirectResponse
    {
        DashboardAuth::logout($request);

        return redirect()->route('performance-tracking.login')->with('status', 'Signed out.');
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('performance-tracking.login')
            ->withErrors(['username' => $message])
            ->withInput(request()->only('username'));
    }
}
