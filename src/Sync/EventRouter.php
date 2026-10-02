<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Contracts\Container\Container;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Sync\Handlers\ArticleDeleteHandler;
use XerAds\Laravel\Sync\Handlers\ArticleUnpublishHandler;
use XerAds\Laravel\Sync\Handlers\ArticleUpsertHandler;
use XerAds\Laravel\Sync\Handlers\EventHandler;
use XerAds\Laravel\Sync\Handlers\PingHandler;
use XerAds\Laravel\Sync\Handlers\RedirectsUpdatedHandler;
use XerAds\Laravel\Sync\Handlers\SettingsUpdatedHandler;
use XerAds\Laravel\Sync\Handlers\SiteRevokedHandler;

/**
 * Which handler answers which event.
 *
 * The list of events this site accepts is also what `ping` reports, and
 * XerAds sends only events a site declared: a site with content turned off
 * is never sent articles in the first place, and refuses one if it is.
 */
final class EventRouter
{
    /** @var array<string, class-string<EventHandler>> */
    private const HANDLERS = [
        'ping' => PingHandler::class,
        'article.upsert' => ArticleUpsertHandler::class,
        'article.unpublish' => ArticleUnpublishHandler::class,
        'article.delete' => ArticleDeleteHandler::class,
        'settings.updated' => SettingsUpdatedHandler::class,
        'redirects.updated' => RedirectsUpdatedHandler::class,
        'site.revoked' => SiteRevokedHandler::class,
    ];

    private const ARTICLE_EVENTS = ['article.upsert', 'article.unpublish', 'article.delete'];

    public function __construct(
        private readonly Container $container,
        private readonly Features $features,
    ) {}

    /** @return list<string> the events this site accepts, in contract order */
    public function events(): array
    {
        $articles = $this->features->has('articles');

        return array_values(array_filter(
            Envelope::EVENTS,
            fn (string $event) => $articles || ! in_array($event, self::ARTICLE_EVENTS, true),
        ));
    }

    public function supports(string $event): bool
    {
        return in_array($event, $this->events(), true);
    }

    public function dispatch(Envelope $envelope): WebhookReply
    {
        if (! $this->supports($envelope->event)) {
            return WebhookReply::error(400, 'EVENT_UNSUPPORTED', "This site does not accept the event \"{$envelope->event}\".", [
                'supported' => $this->events(),
            ]);
        }

        /** @var EventHandler $handler */
        $handler = $this->container->make(self::HANDLERS[$envelope->event]);

        return $handler->handle($envelope);
    }
}
