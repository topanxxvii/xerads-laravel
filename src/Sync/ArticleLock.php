<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository;

/**
 * One delivery per article at a time.
 *
 * Two deliveries of the same article can be in flight at once (a retry and
 * the next edit, or two workers behind a load balancer). Without a lock both
 * read the same content map entry, both pass the ordering check, and the
 * older one can land last. With it, the second waits, then sees what the
 * first wrote. On a cache store without locks, deliveries run unlocked
 * rather than not at all.
 */
final class ArticleLock
{
    private const SECONDS = 60;

    private const WAIT_SECONDS = 10;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    /** @param  callable(): WebhookReply  $callback */
    public function for(string $xeradsId, callable $callback): WebhookReply
    {
        $name = $this->config->get('xerads.cache.store');
        $store = $this->cache->store(is_string($name) && $name !== '' ? $name : null)->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        $prefix = (string) $this->config->get('xerads.cache.prefix', 'xerads');

        try {
            return $store->lock($prefix.':article:'.$xeradsId, self::SECONDS)->block(self::WAIT_SECONDS, $callback);
        } catch (LockTimeoutException) {
            // XerAds retries a 409 in 30 seconds.
            return WebhookReply::error(409, 'DELIVERY_IN_PROGRESS', 'Another delivery for this article is being processed. Retry shortly.');
        }
    }
}
