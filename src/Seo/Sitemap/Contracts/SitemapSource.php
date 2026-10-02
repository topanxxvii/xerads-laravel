<?php

namespace XerAds\Laravel\Seo\Sitemap\Contracts;

use XerAds\Laravel\Seo\Sitemap\SitemapUrl;

/**
 * Addresses for the sitemap, listed at `/sitemaps/{name}-{page}.xml`.
 *
 * The package's own are the turnkey blog's articles, categories and tags,
 * XerAds' articles in a mapped model, and `xerads.sitemap.models`. Add one of
 * your own in `xerads.sitemap.sources`. Every address passes the same checks
 * (noindex, foreign canonical, the settings' path rules and exclusions)
 * before it is listed, so a source only says what exists.
 */
interface SitemapSource
{
    /** Lowercase letters only: it is part of the sitemap's address. */
    public function name(): string;

    /** @return iterable<SitemapUrl> */
    public function urls(): iterable;
}
