<?php

namespace XerAds\Laravel\Content\Events;

use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\Receipt;

/**
 * An article delivery was stored, whatever its status. Fired after the
 * database transaction committed, so a listener (a cache flush, a search
 * index) never sees a row that is then rolled back.
 */
final class ArticleReceived
{
    public function __construct(
        public readonly ArticlePayload $article,
        public readonly Receipt $receipt,
    ) {}
}
