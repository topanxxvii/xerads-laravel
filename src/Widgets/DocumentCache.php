<?php

namespace XerAds\Laravel\Widgets;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Throwable;
use XerAds\Laravel\Support\UrlGuard;

/**
 * A widget's height, read from its public document (`{runtime}/w/{id}.json`).
 *
 * Only `layout.min_height.mobile` is read, to reserve the right amount of
 * space before the runtime loads so the page does not jump. Nothing else in
 * the document is looked at, and in particular not whether the widget is
 * active: the runtime decides visibility in the browser (widgets contract
 * §2.3), with fresher data than any copy here.
 *
 * Cached so a page view never waits on it twice: fresh for
 * `widgets.document_ttl` seconds, then served stale for up to a day while a
 * later request refetches. The fetch has a hard 1.5-second budget and goes
 * through UrlGuard, so a misconfigured runtime address cannot point this at
 * an internal service.
 */
final class DocumentCache
{
    private const STALE_SECONDS = 86_400;

    private const TIMEOUT_SECONDS = 1.5;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly UrlGuard $guard,
        private readonly CacheFactory $cache,
        private readonly HttpFactory $http,
        private readonly Repository $config,
    ) {}

    /** The mobile minimum height in pixels, or null when unknown. */
    public function minHeight(string $id): ?int
    {
        if (! $this->config->get('xerads.widgets.fetch_documents', true) || ! ShortcodeParser::validId($id)) {
            return null;
        }

        $store = $this->store();
        $key = $this->key($id);
        $entry = $store->get($key);
        $cached = is_array($entry) && array_key_exists('height', $entry) ? $entry : null;
        $ttl = max(1, (int) $this->config->get('xerads.widgets.document_ttl', 60));

        if ($cached !== null && Carbon::now()->getTimestamp() - (int) ($cached['fetched_at'] ?? 0) < $ttl) {
            return is_int($cached['height']) ? $cached['height'] : null;
        }

        // A failed fetch is not retried on every page view for the next
        // `ttl` seconds; the stale value (or the fallback) serves meanwhile.
        if ($store->get($key.':failed') !== null) {
            return $cached !== null && is_int($cached['height']) ? $cached['height'] : null;
        }

        $fetched = $this->fetch($id);

        if ($fetched === false) {
            $store->put($key.':failed', true, $ttl);

            return $cached !== null && is_int($cached['height']) ? $cached['height'] : null;
        }

        $store->put($key, ['height' => $fetched, 'fetched_at' => Carbon::now()->getTimestamp()], self::STALE_SECONDS);

        return $fetched;
    }

    /**
     * The height from the document; null for a document without one (or no
     * document: a 404 is an answer, cached like any other); false when the
     * runtime could not be asked.
     */
    private function fetch(string $id): int|false|null
    {
        $url = $this->runtime->documentUrl($id);
        $vetted = $this->guard->vet($url, requireHttps: true, verifyDns: (bool) $this->config->get('xerads.http.verify_public_dns', true));

        if ($vetted['reason'] !== null) {
            return false;
        }

        try {
            $request = $this->http->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->acceptJson();

            // Connect to the address that was checked, not whatever the name
            // resolves to a moment later.
            $request = $request->withOptions($this->guard->pinned($url, $vetted['addresses']));

            $response = $request->get($url);
        } catch (Throwable) {
            return false;
        }

        if ($response->status() === 404 || $response->status() === 410) {
            return null;
        }

        if (! $response->successful()) {
            return false;
        }

        $height = data_get($response->json(), 'layout.min_height.mobile');

        return is_int($height) && $height > 0 && $height <= 10_000 ? $height : null;
    }

    private function key(string $id): string
    {
        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':widget-document:'.$id;
    }

    private function store(): Cache
    {
        $store = $this->config->get('xerads.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
