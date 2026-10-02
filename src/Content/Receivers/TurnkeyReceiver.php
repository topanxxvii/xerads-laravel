<?php

namespace XerAds\Laravel\Content\Receivers;

use XerAds\Laravel\Content\ConfigurationReport;
use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\ArticleReference;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;
use XerAds\Laravel\Content\Exceptions\ReceiverMisconfigured;

/**
 * The package's own blog: tables, routes and views.
 *
 * Not built yet. Until it is, a site set to turnkey mode answers every
 * article with a clear 422 that XerAds shows the owner, rather than failing
 * in a way that looks like an outage and gets retried for a day.
 */
final class TurnkeyReceiver implements ContentReceiver
{
    private const MESSAGE = 'Turnkey mode arrives in a later release of xerads/laravel. Set XERADS_CONTENT_MODE=mapped to store articles in your own model until then.';

    public function receive(ArticlePayload $article, DeliveryContext $context): Receipt
    {
        throw new ReceiverMisconfigured(self::MESSAGE);
    }

    public function unpublish(ArticleReference $article, DeliveryContext $context): Receipt
    {
        throw new ReceiverMisconfigured(self::MESSAGE);
    }

    public function delete(ArticleReference $article, DeliveryContext $context): Receipt
    {
        throw new ReceiverMisconfigured(self::MESSAGE);
    }

    public function validateConfiguration(): ConfigurationReport
    {
        return new ConfigurationReport([self::MESSAGE]);
    }
}
