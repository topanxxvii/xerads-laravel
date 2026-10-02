<?php

namespace XerAds\Laravel\Content\Events;

use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\Receipt;

/** An article became public on this site: new, or a draft published. */
final class ArticlePublished
{
    public function __construct(
        public readonly ArticlePayload $article,
        public readonly Receipt $receipt,
    ) {}
}
