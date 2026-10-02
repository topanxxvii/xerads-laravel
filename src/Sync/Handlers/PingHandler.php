<?php

namespace XerAds\Laravel\Sync\Handlers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Support\Diagnostics;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Support\Version;
use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\EventRouter;
use XerAds\Laravel\Sync\WebhookReply;

/**
 * `ping`: what the site is and how it is doing, for the XerAds dashboard.
 *
 * Signed, so it may say more than the public status route: PHP and Laravel
 * versions (to warn about unsupported ones) and the health checks. The
 * `events` list is what XerAds will send from now on.
 */
final class PingHandler implements EventHandler
{
    public function __construct(
        private readonly EventRouter $router,
        private readonly Features $features,
        private readonly Diagnostics $diagnostics,
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    public function handle(Envelope $envelope): WebhookReply
    {
        return WebhookReply::ok([
            'plugin' => 'xerads',
            'platform' => 'laravel',
            'version' => Version::VERSION,
            'contract' => Version::CONTRACT,
            'mode' => (string) $this->config->get('xerads.content.mode', 'mapped'),
            'events' => $this->router->events(),
            'features' => $this->features->enabled(),
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'time' => Carbon::now()->getTimestamp(),
            'checks' => $this->diagnostics->pingChecks(),
        ]);
    }
}
