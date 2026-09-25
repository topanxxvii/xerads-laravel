<?php

namespace XerAds\CmsBridge;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Http\Controllers\ArticleWebhookController;
use XerAds\CmsBridge\Http\Middleware\VerifyXerAdsSignature;
use XerAds\CmsBridge\Receivers\EloquentArticleReceiver;

/**
 * Wire the package into the host application.
 *
 * The whole install is `composer require` plus one env variable: the route
 * registers itself, so there is no step a person can forget and then spend an
 * afternoon on. A site that wants the route somewhere else moves it in config;
 * a site that wants its own controller sets the route to null and keeps the
 * middleware and the receiver.
 */
class CmsBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/xerads-cms.php', 'xerads-cms');

        /*
         * Bound, not final. `EloquentArticleReceiver` covers a site whose
         * articles are rows in one model; anything else — a page builder, a
         * static generator, a queue — binds its own implementation in
         * AppServiceProvider and inherits the signature check, the test
         * handling and the response contract unchanged.
         */
        $this->app->bind(ArticleReceiver::class, EloquentArticleReceiver::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/xerads-cms.php' => config_path('xerads-cms.php'),
        ], 'xerads-cms-config');

        $path = config('xerads-cms.route');

        if (! is_string($path) || trim($path) === '') {
            return;
        }

        Route::middleware(array_merge(
            (array) config('xerads-cms.middleware', ['api']),
            [VerifyXerAdsSignature::class],
        ))->post($path, ArticleWebhookController::class)->name('xerads.articles.receive');
    }
}
