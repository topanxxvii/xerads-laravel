<?php

namespace XerAds\Laravel\Content\Contracts;

use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\ArticleReference;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;

/**
 * Where paired (contract 2) deliveries are stored.
 *
 * The package ships three: EloquentMappedReceiver writes into a model the
 * site already has, TurnkeyReceiver keeps a blog of its own, and
 * LegacyReceiverAdapter hands articles to an `ArticleReceiver` a site wrote
 * for the original endpoint. Bind your own in AppServiceProvider to store
 * articles anywhere else; the signature checks, ordering, duplicate handling
 * and replies stay the package's.
 *
 * Throw ReceiverMisconfigured when the site's setup, not the article, is the
 * problem: XerAds shows the message to the site owner instead of retrying.
 */
interface ContentReceiver extends ValidatesConfiguration
{
    public function receive(ArticlePayload $article, DeliveryContext $context): Receipt;

    /** Take the article off the site, keeping it. */
    public function unpublish(ArticleReference $article, DeliveryContext $context): Receipt;

    /** Remove the article; what removing means is the receiver's to decide. */
    public function delete(ArticleReference $article, DeliveryContext $context): Receipt;
}
