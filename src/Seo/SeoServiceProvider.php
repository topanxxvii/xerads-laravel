<?php

namespace XerAds\Laravel\Seo;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use XerAds\Laravel\Seo\Http\ApplyRobotsHeader;
use XerAds\Laravel\Seo\Inertia\ShareHead;
use XerAds\Laravel\Seo\Inertia\ShareHeadWithInertia;

/**
 * On-page SEO beyond the head tags themselves (which `@xeradsHead` and
 * `<x-xerads::head>` print whatever modules are on): the `X-Robots-Tag`
 * header on every response, and the head shared with Inertia pages.
 */
final class SeoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $config = $this->app->make('config');

        if ($config->get('xerads.middleware.global', true)) {
            $this->pushGlobal($this->app->make(HttpKernel::class));
        }

        // Per request, in the web group: shared once at boot, the prop would
        // be gone after a long-running worker's first request.
        if ($config->get('xerads.seo.inertia.share', true) && ShareHead::available()) {
            $this->appendToWebGroup($this->app->make(HttpKernel::class));
        }
    }

    /**
     * Global middleware, through the HTTP kernel: Laravel's kernel has the
     * method, the contract does not promise it.
     */
    private function pushGlobal(object $kernel): void
    {
        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(ApplyRobotsHeader::class);
        }
    }

    private function appendToWebGroup(object $kernel): void
    {
        if (method_exists($kernel, 'appendMiddlewareToGroup') && method_exists($kernel, 'getMiddlewareGroups') && array_key_exists('web', (array) $kernel->getMiddlewareGroups())) {
            $kernel->appendMiddlewareToGroup('web', ShareHeadWithInertia::class);
        }
    }
}
