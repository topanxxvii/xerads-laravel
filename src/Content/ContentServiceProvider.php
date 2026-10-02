<?php

namespace XerAds\Laravel\Content;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Receivers\EloquentArticleReceiver;
use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Pipeline\ContentPipeline;
use XerAds\Laravel\Content\Receivers\EloquentMappedReceiver;
use XerAds\Laravel\Content\Receivers\LegacyReceiverAdapter;
use XerAds\Laravel\Content\Receivers\MappedModel;
use XerAds\Laravel\Content\Receivers\TurnkeyReceiver;

/**
 * Articles: the content pipeline, the receiver that stores them and, in
 * turnkey mode, the blog that shows them.
 *
 * Which receiver depends on `content.mode` and on what the site bound
 * itself. A site that bound its own `ArticleReceiver` for the original
 * endpoint keeps it: paired deliveries are adapted to it rather than
 * written somewhere it never looks. A site that binds a `ContentReceiver`
 * in its AppServiceProvider replaces all of this.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MappedModel::class);
        $this->app->singleton(ContentPipeline::class);

        $this->app->bind(ContentReceiver::class, function (Application $app): ContentReceiver {
            if ($app->make('config')->get('xerads.content.mode') === 'turnkey') {
                return $app->make(TurnkeyReceiver::class);
            }

            $legacy = $app->make(ArticleReceiver::class);

            return $legacy::class === EloquentArticleReceiver::class
                ? $app->make(EloquentMappedReceiver::class)
                : new LegacyReceiverAdapter($legacy);
        });
    }

    /**
     * The turnkey blog's pages, in turnkey mode only: a site storing articles
     * in its own model keeps `/blog` for itself.
     *
     * Registered once the application has booted, after the site's own
     * routes: the first route that matches wins, and `/blog/{slug}` matches
     * every path under the prefix. A site's own `/blog/feed` or `/blog/search`
     * must stay the site's. `route:cache` keeps the same order.
     */
    public function boot(): void
    {
        if ($this->app->make('config')->get('xerads.content.mode') !== 'turnkey') {
            return;
        }

        $this->app->booted(function (): void {
            if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
                return;
            }

            $this->loadRoutesFrom(__DIR__.'/../../routes/blog.php');

            /** @var Router $router */
            $router = $this->app->make('router');
            $router->getRoutes()->refreshNameLookups();
            $router->getRoutes()->refreshActionLookups();
        });
    }
}
