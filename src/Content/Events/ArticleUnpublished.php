<?php

namespace XerAds\Laravel\Content\Events;

use XerAds\Laravel\Content\Data\Receipt;

/**
 * A public article went offline: unpublished in XerAds, sent back as a draft,
 * or deleted on a site that keeps deleted articles as drafts.
 */
final class ArticleUnpublished
{
    public function __construct(
        public readonly string $xeradsId,
        public readonly Receipt $receipt,
    ) {}
}
