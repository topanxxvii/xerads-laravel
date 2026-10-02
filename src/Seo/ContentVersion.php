<?php

namespace XerAds\Laravel\Seo;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * A number that changes whenever what the sitemap and llms.txt list may have
 * changed: an article stored, taken down or deleted, redirects synced. They
 * are cached under it, so a change is visible on the next request and an
 * unchanged site never rebuilds them.
 *
 * Changes the site makes to its own models without XerAds are not seen
 * here; `sitemap.cache_ttl` bounds how long those wait.
 */
final class ContentVersion
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    public function current(): int
    {
        try {
            $version = $this->store()->get($this->key());
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($version) ? (int) $version : 0;
    }

    public function bump(): void
    {
        try {
            if ($this->store()->increment($this->key()) === false) {
                $this->store()->forever($this->key(), 1);
            }
        } catch (Throwable) {
            // Uncached, every request builds afresh anyway.
        }
    }

    private function key(): string
    {
        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':content-version';
    }

    private function store(): Cache
    {
        $store = $this->config->get('xerads.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
