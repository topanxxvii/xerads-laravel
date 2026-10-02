<?php

namespace XerAds\Laravel\Widgets;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use XerAds\Laravel\Widgets\Http\InjectWidgetLoader;

/**
 * XerAds widgets on the site's pages.
 *
 * The services behind `<x-xerads::widget>`, `<x-xerads::content>` and
 * `<x-xerads::scripts>` (registered by the core provider, so a template keeps
 * rendering with this module off), and a `web` middleware that adds the
 * loader to any page with a widget container, for templates that do not.
 */
final class WidgetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShortcodeParser::class);
        $this->app->singleton(Runtime::class);
        $this->app->singleton(DocumentCache::class);

        // Per request: what one page rendered says nothing about the next.
        $this->app->scoped(WidgetAssets::class);
        $this->app->scoped(WidgetExpander::class);
    }

    public function boot(): void
    {
        if ($this->app->make('config')->get('xerads.widgets.inject_loader', true)) {
            $this->appendToWebGroup($this->app->make(HttpKernel::class));
        }
    }

    /**
     * Through the HTTP kernel rather than the router: the kernel resolves its
     * middleware groups when it is built and copies them onto the router,
     * so a group changed only on the router could be overwritten. Laravel's
     * kernel has the method; the contract does not promise it.
     */
    private function appendToWebGroup(object $kernel): void
    {
        if (method_exists($kernel, 'appendMiddlewareToGroup')) {
            $kernel->appendMiddlewareToGroup('web', InjectWidgetLoader::class);
        }
    }
}
