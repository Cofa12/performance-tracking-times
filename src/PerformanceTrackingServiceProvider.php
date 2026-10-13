<?php

namespace Cofa\PerformanceTracking;

use Cofa\PerformanceTracking\Console\PasswordCommand;
use Cofa\PerformanceTracking\Console\PruneCommand;
use Cofa\PerformanceTracking\Http\Middleware\RecordPerformance;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PerformanceTrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/performance-tracking.php', 'performance-tracking');

        // Scoped so Octane gets a fresh recorder per request.
        $this->app->scoped(Recorder::class, fn ($app) => new Recorder($app['config']));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'performance-tracking');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Blade::directive('performanceTrackingScript', fn () => '<?php echo \\'.ClientScript::class.'::tag(); ?>');

        RateLimiter::for('performance-tracking', function (Request $request) {
            $limits = config('performance-tracking.client_rate_limit', []);

            return [
                Limit::perMinute((int) ($limits['per_ip'] ?? 120))->by('ip:'.$request->ip()),
                Limit::perMinute((int) ($limits['global'] ?? 10000))->by('global'),
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/performance-tracking.php' => config_path('performance-tracking.php'),
            ], 'performance-tracking-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'performance-tracking-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/performance-tracking'),
            ], 'performance-tracking-views');

            $this->commands([PruneCommand::class, PasswordCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                if ($cron = config('performance-tracking.prune_schedule')) {
                    $schedule->command(PruneCommand::class)->cron($cron)->withoutOverlapping();
                }
            });
        }

        if (! config('performance-tracking.enabled')) {
            return;
        }

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) {
            // The current container, not $this->app: Octane serves each
            // request from a clone, and the request's recorder lives there.
            Container::getInstance()->make(Recorder::class)->recordQuery($event);
        });

        if (config('performance-tracking.auto_register_middleware') && $this->app->bound(Kernel::class)) {
            $kernel = $this->app->make(Kernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(RecordPerformance::class);
            }
        }
    }
}
