<?php

namespace XerAds\Laravel\Sync\Handlers;

use XerAds\Laravel\Content\Data\ArticleReference;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;

/**
 * `article.delete`: remove the article. In mapped mode, what that means is
 * `content.mapped.on_delete`: unpublish (the default) or delete the row.
 */
final class ArticleDeleteHandler extends ArticleRemovalHandler
{
    protected function remove(ArticleReference $article, DeliveryContext $context): Receipt
    {
        return $this->receiver->delete($article, $context);
    }

    protected function tombstoneState(): string
    {
        return Receipt::DELETED;
    }
}
