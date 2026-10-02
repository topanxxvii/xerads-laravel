<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
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
    public function register(): void
    {
        $this->app->singleton(EventRouter::class);
        $this->app->singleton(ArticleReplies::class);
        $this->app->singleton(ArticleLock::class);
    }

    public function boot(): void
    {
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
}
