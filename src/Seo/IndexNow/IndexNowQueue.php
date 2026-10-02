<?php

namespace XerAds\Laravel\Seo\IndexNow;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;
use XerAds\Laravel\Seo\IndexNow\Jobs\SubmitIndexNow;

/**
 * Collects the addresses that changed (an article published, updated, taken
 * down or deleted) and submits them together, once per
 * `indexnow.debounce_seconds`: a bulk edit is one submission, not hundreds.
 *
 * Only where submissions happen at all (`indexnow.environments`, production
 * by default), so a staging copy never announces its own addresses.
 */
final class IndexNowQueue
{
    /** More than any burst; the oldest are dropped past it. */
    private const MAX_PENDING = 10_000;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
        private readonly Application $app,
        private readonly IndexNowKey $key,
    ) {}

    public function submits(): bool
    {
        $environments = (array) $this->config->get('xerads.indexnow.environments', ['production']);

        return ($this->config->get('xerads.modules.seo', true) !== false)
            && $this->key->enabled()
            && in_array($this->app->environment(), $environments, true);
    }

    public function add(string ...$urls): void
    {
        $urls = array_values(array_filter($urls, fn (string $url) => preg_match('#^https?://#i', $url) === 1));

        if ($urls === [] || ! $this->submits()) {
            return;
        }

        try {
            $this->locked(function () use ($urls) {
                $pending = $this->store()->get($this->pendingKey());
                $pending = array_values(array_unique([...(is_array($pending) ? $pending : []), ...$urls]));

                $this->store()->put($this->pendingKey(), array_slice($pending, -self::MAX_PENDING), 86_400);
            });

            $debounce = max(1, (int) $this->config->get('xerads.indexnow.debounce_seconds', 60));

            // One submission per window, however many changes arrive in it.
            if ($this->store()->add($this->scheduledKey(), true, $debounce)) {
                SubmitIndexNow::dispatchFor($this->config, $debounce);
            }
        } catch (Throwable) {
            // A missed submission costs a slower recrawl, nothing more.
        }
    }

    /**
     * Everything waiting, removed from the queue.
     *
     * @return list<string>
     */
    public function take(): array
    {
        return $this->locked(function (): array {
            $pending = $this->store()->get($this->pendingKey());
            $this->store()->forget($this->pendingKey());
            $this->store()->forget($this->scheduledKey());

            return is_array($pending) ? array_values(array_filter($pending, 'is_string')) : [];
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store->lock($this->pendingKey().':lock', 10)->block(5, $callback);
    }

    private function pendingKey(): string
    {
        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':indexnow:pending';
    }

    private function scheduledKey(): string
    {
        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':indexnow:scheduled';
    }

    private function store(): Cache
    {
        $store = $this->config->get('xerads.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
