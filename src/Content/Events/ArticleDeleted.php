<?php

namespace XerAds\Laravel\Content\Events;

use XerAds\Laravel\Content\Data\Receipt;

/** An article was deleted from this site (`content.mapped.on_delete = delete`). */
final class ArticleDeleted
{
    public function __construct(
        public readonly string $xeradsId,
        public readonly Receipt $receipt,
    ) {}
}
