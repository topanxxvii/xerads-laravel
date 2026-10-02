<?php

namespace XerAds\Laravel\Seo;

use Throwable;
use XerAds\Laravel\Seo\IndexNow\IndexNowQueue;

/**
 * What follows a change to what readers see: the sitemap and llms.txt are
 * rebuilt on their next request (ContentVersion), and the addresses that
 * changed go to IndexNow.
 */
final class ContentChanges
{
    public function __construct(
        private readonly ContentVersion $version,
        private readonly IndexNowQueue $indexNow,
    ) {}

    /** @param  string|null  ...$urls  the public addresses that changed, before and after */
    public function record(?string ...$urls): void
    {
        try {
            $this->version->bump();
            $this->indexNow->add(...array_values(array_unique(array_filter($urls, fn (?string $url) => $url !== null && $url !== ''))));
        } catch (Throwable) {
            // Search engines catch up on their next visit.
        }
    }
}
