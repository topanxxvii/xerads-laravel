<?php

namespace XerAds\Laravel\Content;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Support\Version;
use XerAds\Laravel\Widgets\WidgetAssets;
use XerAds\Laravel\Widgets\WidgetExpander;

/**
 * A turnkey article's body, ready to print: `body_source` with its widget
 * placeholders expanded by this release of the package.
 *
 * Cached by `xerads_id:revision:pluginVersion`, so a page view does not parse
 * the body again, a new revision is rendered fresh, and so is every article
 * after a package update that changes how widgets are written. Copying the
 * article's images to this site forgets the entry (the revision stays the
 * same, the addresses do not).
 */
final class ArticleBodies
{
    private const TTL_SECONDS = 7 * 86_400;

    public function __construct(
        private readonly WidgetExpander $expander,
        private readonly WidgetAssets $assets,
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    public function html(Article $article): string
    {
        $cached = $this->store()->get($this->key($article));

        if (is_string($cached)) {
            $html = $cached;
        } else {
            $source = $article->body_source !== '' ? $article->body_source : $article->body_html;
            $html = $this->expander->expand($source, $article->language, $article->widgetHeights());

            $this->store()->put($this->key($article), $html, self::TTL_SECONDS);
        }

        // A cached body skipped the expansion that asks for the loader.
        if (str_contains($html, 'data-xerads-widget')) {
            $this->assets->requireLoader();
        }

        return $html;
    }

    public function forget(Article $article): void
    {
        $this->store()->forget($this->key($article));
    }

    public function key(Article $article): string
    {
        return implode(':', [
            (string) $this->config->get('xerads.cache.prefix', 'xerads'),
            'article-html',
            $article->xerads_id ?? 'local-'.$article->getKey(),
            $article->revision ?? (string) $article->updated_at?->getTimestamp(),
            Version::VERSION,
            // With the widgets module off, placeholders render as nothing.
            $this->expander->enabled() ? 'widgets' : 'plain',
        ]);
    }

    private function store(): Cache
    {
        $store = $this->config->get('xerads.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
