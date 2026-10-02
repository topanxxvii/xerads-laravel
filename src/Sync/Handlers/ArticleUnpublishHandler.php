<?php

namespace XerAds\Laravel\Sync\Handlers;

use XerAds\Laravel\Content\Data\ArticleReference;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;

/** `article.unpublish`: take the article offline, keeping it. */
final class ArticleUnpublishHandler extends ArticleRemovalHandler
{
    protected function remove(ArticleReference $article, DeliveryContext $context): Receipt
    {
        return $this->receiver->unpublish($article, $context);
    }

    protected function tombstoneState(): string
    {
        return Receipt::UNPUBLISHED;
    }
}
