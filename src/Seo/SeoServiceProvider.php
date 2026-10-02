<?php

namespace XerAds\Laravel\Seo;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use XerAds\Laravel\Seo\Http\ApplyRobotsHeader;
use XerAds\Laravel\Seo\Http\ServeSeoFiles;
use XerAds\Laravel\Seo\Inertia\ShareHead;
use XerAds\Laravel\Seo\Inertia\ShareHeadWithInertia;
use XerAds\Laravel\Seo\NotFound\Http\RecordNotFound;
use XerAds\Laravel\Seo\Redirects\Http\HandleRedirects;

/**
 * SEO beyond the head tags themselves (which `@xeradsHead` and
 * `<x-xerads::head>` print whatever modules are on):
 *
 * - global middleware, pushed through the HTTP kernel: the redirects (for
 *   requests the site answers 404), the package's files past a catch-all
 *   route of the site's, the 404 monitor and the `X-Robots-Tag` header.
 *   `xerads.middleware.global` turns them all off, and
 *   `xerads.middleware.{redirects,seo_files,not_found,robots_header}` each
 *   one;
 * - robots.txt, the sitemaps, llms.txt and the IndexNow key, registered after
 *   the site's own routes, so a site's own `/robots.txt` keeps answering;
 * - the head shared with Inertia pages.
 */
final class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SiteAddress::class);
    }

    public function boot(): void
    {
        $config = $this->app->make('config');

        if ($config->get('xerads.middleware.global', true)) {
            $this->pushGlobal($this->app->make(HttpKernel::class), $config->get('xerads.middleware', []));
        }

        // Per request, in the web group: shared once at boot, the prop would
        // be gone after a long-running worker's first request.
        if ($config->get('xerads.seo.inertia.share', true) && ShareHead::available()) {
            $this->appendToWebGroup($this->app->make(HttpKernel::class));
        }

        $this->app->booted(function (): void {
            if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
                return;
            }

            $this->loadRoutesFrom(__DIR__.'/../../routes/seo.php');

            /** @var Router $router */
            $router = $this->app->make('router');
            $router->getRoutes()->refreshNameLookups();
            $router->getRoutes()->refreshActionLookups();
        });
    }

    /**
     * Global middleware, through the HTTP kernel: Laravel's kernel has the
     * method, the contract does not promise it.
     *
     * @param  mixed  $switches  `xerads.middleware`
     */
    private function pushGlobal(object $kernel, mixed $switches): void
    {
        if (! method_exists($kernel, 'pushMiddleware')) {
            return;
        }

        $switches = is_array($switches) ? $switches : [];

        // In this order: a response comes back through them innermost first,
        // so the package's files are served before a 404 is redirected, and
        // the 404 monitor sees only what is left.
        foreach ([
            'robots_header' => ApplyRobotsHeader::class,
            'redirects' => HandleRedirects::class,
            'seo_files' => ServeSeoFiles::class,
            'not_found' => RecordNotFound::class,
        ] as $switch => $middleware) {
            if (($switches[$switch] ?? true) !== false) {
                $kernel->pushMiddleware($middleware);
            }
        }
    }

    private function appendToWebGroup(object $kernel): void
    {
        if (method_exists($kernel, 'appendMiddlewareToGroup') && method_exists($kernel, 'getMiddlewareGroups') && array_key_exists('web', (array) $kernel->getMiddlewareGroups())) {
            $kernel->appendMiddlewareToGroup('web', ShareHeadWithInertia::class);
        }
    }
}
