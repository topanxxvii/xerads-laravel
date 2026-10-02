<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Throwable;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Sync\Http\Middleware\RefreshWhenStale;
use XerAds\Laravel\Sync\Http\Middleware\VerifyWebhookSignature;
use XerAds\Laravel\Sync\Http\StatusController;
use XerAds\Laravel\Sync\Http\WebhookController;

/**
 * Talking to XerAds: the webhook every paired delivery arrives at, and the
 * public status route.
 *
 * The webhook is registered outside every middleware group on purpose. The
 * `web` group's CSRF check would answer each delivery with 419, its session
 * would start one per delivery, and cookie encryption has nothing to do;
 * the `api` group's rate limit could throttle a burst of retries. The
 * signature is the authentication. `xerads:doctor` checks it stays that way.
 */
final class SyncServiceProvider extends ServiceProvider
{
    /** How long a sync's overlap lock outlives a sync that was killed. */
    public const MUTEX_MINUTES = 10;

    public function register(): void
    {
        $this->app->singleton(EventRouter::class);
        $this->app->singleton(ArticleReplies::class);
        $this->app->singleton(ArticleLock::class);
    }

    public function boot(): void
    {
        $this->registerScheduler();
        $this->registerRefreshMiddleware();

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make('config');
        $attributes = ['prefix' => trim((string) $config->get('xerads.routes.prefix', 'xerads/v1'), '/')];
        $domain = $config->get('xerads.routes.domain');

        if (is_string($domain) && $domain !== '') {
            $attributes['domain'] = $domain;
        }

        /** @var Router $router */
        $router = $this->app->make('router');

        $router->group($attributes, function (Router $router) use ($config) {
            $router->post('webhook', WebhookController::class)
                ->middleware(VerifyWebhookSignature::class)
                ->name('xerads.webhook');

            if ($config->get('xerads.routes.status', true)) {
                $router->get('status', StatusController::class)->name('xerads.status');
            }
        });
    }

    /**
     * The routine refresh and the hourly heartbeat, on the site's own
     * scheduler. Registered only once something resolves the scheduler, so
     * a web request pays nothing for it. `xerads.sync.scheduler` turns it off.
     *
     * Unless the schedules are configured, the minutes are picked from the
     * site id (the application key before pairing): if every site called
     * XerAds at :00, :15, :30 and :45, sites sharing a host's address would
     * share its rate limit too. A sync killed mid-run blocks the next ones
     * for at most `MUTEX_MINUTES`, not the scheduler's default of a day.
     */
    private function registerScheduler(): void
    {
        $config = $this->app->make('config');

        if (! $config->get('xerads.sync.scheduler', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($config) {
            $minute = $this->spreadMinute();

            $schedule->command('xerads:sync')
                ->cron($this->expression($config->get('xerads.sync.schedule'), sprintf('%d,%d,%d,%d * * * *', $minute % 15, $minute % 15 + 15, $minute % 15 + 30, $minute % 15 + 45)))
                ->withoutOverlapping(self::MUTEX_MINUTES);

            // Seven minutes off: never on a refresh minute.
            $schedule->command('xerads:sync --heartbeat')
                ->cron($this->expression($config->get('xerads.sync.heartbeat_schedule'), sprintf('%d * * * *', ($minute + 7) % 60)))
                ->withoutOverlapping(self::MUTEX_MINUTES);
        });
    }

    private function expression(mixed $configured, string $spread): string
    {
        return is_string($configured) && trim($configured) !== '' ? $configured : $spread;
    }

    /** A minute of the hour (0–59) that stays the same for this site. */
    private function spreadMinute(): int
    {
        try {
            $seed = $this->app->make(CredentialsResolver::class)->current()?->siteId;
        } catch (Throwable) {
            // Not migrated, or a broken key: the application key will do.
            $seed = null;
        }

        if ($seed === null) {
            $config = $this->app->make('config');
            $seed = (string) $config->get('app.key', '');
            $seed = $seed !== '' ? $seed : (string) $config->get('app.url', '');
        }

        return (int) (hexdec(substr(hash('sha256', 'xerads-schedule|'.$seed), 0, 7)) % 60);
    }

    /** The after-response refresh, for sites without cron (`xerads.sync.after_response`). */
    private function registerRefreshMiddleware(): void
    {
        if ($this->app->make('config')->get('xerads.sync.after_response', true)) {
            $this->appendToWebGroup($this->app->make(HttpKernel::class));
        }
    }

    /**
     * Through the HTTP kernel, which owns the groups it copies to the router.
     * Only when the application has a `web` group: an API-only application
     * may have none, and appending to a missing group throws on every boot.
     */
    private function appendToWebGroup(object $kernel): void
    {
        if (! method_exists($kernel, 'appendMiddlewareToGroup') || ! method_exists($kernel, 'getMiddlewareGroups')) {
            return;
        }

        $groups = $kernel->getMiddlewareGroups();

        if (is_array($groups) && array_key_exists('web', $groups)) {
            $kernel->appendMiddlewareToGroup('web', RefreshWhenStale::class);
        }
    }
}
