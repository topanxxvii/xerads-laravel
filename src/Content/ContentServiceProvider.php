<?php

namespace XerAds\Laravel\Content;

use Illuminate\Contracts\Foundation\Application;
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
 * Articles: the content pipeline and the receiver that stores them.
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
}
