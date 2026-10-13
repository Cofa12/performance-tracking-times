<?php

namespace Cofa\PerformanceTracking\Tests\Feature;

use Cofa\PerformanceTracking\Models\PerformanceTiming;
use Cofa\PerformanceTracking\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class LoginTest extends TestCase
{
    private const DASHBOARD = '/performance-tracking';

    private const LOGIN = '/performance-tracking/login';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'performance-tracking.dashboard_auth.username' => 'admin',
            'performance-tracking.dashboard_auth.password' => 'correct horse',
        ]);
    }

    private function signIn(string $password = 'correct horse')
    {
        return $this->post(self::LOGIN, ['username' => 'admin', 'password' => $password]);
    }

    public function test_the_dashboard_sends_visitors_to_the_login_page(): void
    {
        $this->get(self::DASHBOARD)->assertRedirect(self::LOGIN);

        $this->get(self::LOGIN)
            ->assertOk()
            ->assertSee('Sign in to view the dashboard.')
            ->assertSee('autocomplete="current-password"', false);
    }

    public function test_it_signs_in_and_out(): void
    {
        $this->signIn()->assertRedirect('http://localhost'.self::DASHBOARD);

        $this->get(self::DASHBOARD)->assertOk()->assertSee('Sign out');
        $this->get(self::LOGIN)->assertRedirect('http://localhost'.self::DASHBOARD);

        $this->post('/performance-tracking/logout')
            ->assertRedirect(self::LOGIN)
            ->assertSessionHas('status', 'Signed out.');

        $this->get(self::DASHBOARD)->assertRedirect(self::LOGIN);
    }

    public function test_wrong_credentials_are_rejected(): void
    {
        $this->signIn('wrong')
            ->assertRedirect(self::LOGIN)
            ->assertSessionHasErrors(['username' => 'Wrong username or password.']);

        $this->post(self::LOGIN, ['username' => 'someone', 'password' => 'correct horse'])
            ->assertSessionHasErrors('username');

        $this->get(self::DASHBOARD)->assertRedirect(self::LOGIN);
    }

    public function test_a_hashed_password_works(): void
    {
        config(['performance-tracking.dashboard_auth.password' => password_hash('correct horse', PASSWORD_DEFAULT)]);

        $this->signIn('wrong')->assertSessionHasErrors('username');
        $this->signIn()->assertRedirect('http://localhost'.self::DASHBOARD);
        $this->get(self::DASHBOARD)->assertOk();
    }

    public function test_after_signing_in_it_returns_to_the_page_that_was_asked_for(): void
    {
        $timing = PerformanceTiming::create([
            'id' => (string) Str::uuid(), 'method' => 'GET', 'path' => '/orders', 'status' => 200,
            'server_received_ms' => 1, 'server_sent_ms' => 2,
            'boot_ms' => 1, 'app_ms' => 1, 'service_ms' => 2, 'server_ms' => 2, 'created_at' => now(),
        ]);
        $page = 'http://localhost/performance-tracking/requests/'.$timing->id;

        $this->get($page)->assertRedirect(self::LOGIN);

        $this->signIn()->assertRedirect($page);
    }

    public function test_it_never_redirects_outside_the_dashboard(): void
    {
        $this->withSession(['performance-tracking.intended' => 'https://evil.example/phish'])
            ->post(self::LOGIN, ['username' => 'admin', 'password' => 'correct horse'])
            ->assertRedirect('http://localhost'.self::DASHBOARD);
    }

    public function test_live_updates_get_a_401_instead_of_a_redirect(): void
    {
        $this->getJson('/performance-tracking/live')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Sign in to the dashboard.']);
    }

    public function test_changing_the_password_signs_everyone_out(): void
    {
        $this->signIn();
        $this->get(self::DASHBOARD)->assertOk();

        config(['performance-tracking.dashboard_auth.password' => 'a new password']);

        $this->get(self::DASHBOARD)->assertRedirect(self::LOGIN);
    }

    public function test_signing_in_starts_a_new_session(): void
    {
        $this->get(self::LOGIN);
        $before = session()->getId();

        $this->signIn();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_attempts_are_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->signIn('wrong')->assertSessionHasErrors(['username' => 'Wrong username or password.']);
        }

        // Even the right password is refused while locked out.
        $this->signIn()->assertSessionHasErrors('username');
        $this->assertStringStartsWith('Too many attempts.', session('errors')->first('username'));
        $this->get(self::DASHBOARD)->assertRedirect(self::LOGIN);
    }

    public function test_the_login_is_required_in_local_too(): void
    {
        $this->app['env'] = 'local';

        $this->get(self::DASHBOARD)->assertRedirect(self::LOGIN);
    }

    public function test_the_gate_still_lets_application_admins_in(): void
    {
        Gate::define('viewPerformanceTracking', fn ($user) => $user->getAuthIdentifier() === 1);

        $this->actingAs((new User)->forceFill(['id' => 1]))
            ->get(self::DASHBOARD)
            ->assertOk()
            ->assertDontSee('Sign out');
    }

    public function test_without_credentials_there_is_no_login_page(): void
    {
        config(['performance-tracking.dashboard_auth.password' => null]);

        $this->get(self::LOGIN)->assertNotFound();
        $this->post(self::LOGIN, ['username' => 'admin', 'password' => ''])->assertNotFound();
        $this->get(self::DASHBOARD)->assertForbidden();
    }

    public function test_the_password_command_prints_a_hash_for_env(): void
    {
        $this->artisan('performance-tracking:password')
            ->expectsQuestion('Dashboard password', 'correct horse')
            ->expectsQuestion('Repeat it', 'correct horse')
            ->expectsOutputToContain("PERFORMANCE_TRACKING_PASSWORD='\$2y\$")
            ->assertSuccessful();

        $this->artisan('performance-tracking:password')
            ->expectsQuestion('Dashboard password', 'short')
            ->assertFailed();
    }
}
