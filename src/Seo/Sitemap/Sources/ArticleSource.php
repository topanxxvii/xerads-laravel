<?php

namespace XerAds\Laravel\Seo\Sitemap\Sources;

use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Seo\Sitemap\Contracts\SitemapSource;
use XerAds\Laravel\Seo\Sitemap\SitemapUrl;

/** The turnkey blog's published articles. */
final class ArticleSource implements SitemapSource
{
    public function __construct(private readonly SiteAddress $address) {}

    public function name(): string
    {
        return 'articles';
    }

    public function urls(): iterable
    {
        foreach (Article::query()->published()->with('seoMeta')->lazyById(500) as $article) {
            $meta = $article->xeradsSeo();

            yield new SitemapUrl(
                loc: $this->address->url(Article::pathFor($article->slug)),
                lastmod: $article->content_updated_at ?? $article->published_at,
                images: is_string($article->featured_image_url) && $article->featured_image_url !== '' ? [$article->featured_image_url] : [],
                index: ($meta?->robots['index'] ?? true) !== false,
                canonical: is_string($meta?->canonical_url) && $meta->canonical_url !== '' ? $meta->canonical_url : null,
                kind: 'article',
            );
        }
    }
}
