<?php

namespace XerAds\Laravel\Seo\Sitemap;

use DateTimeInterface;

/**
 * One address a sitemap source offers, with what decides whether it is
 * listed: a page that is not to be indexed, or whose canonical is another
 * address, is left out (SitemapBuilder).
 *
 * `index` is the page's own say (its stored robots), which can only keep it
 * out; `kind` (article, category, tag or page) is what the settings' noindex
 * groups go by.
 */
final class SitemapUrl
{
    /** @param  list<string>  $images  addresses of the page's images */
    public function __construct(
        public readonly string $loc,
        public readonly ?DateTimeInterface $lastmod = null,
        public readonly array $images = [],
        public readonly bool $index = true,
        public readonly ?string $canonical = null,
        public readonly string $kind = 'page',
    ) {}
}
