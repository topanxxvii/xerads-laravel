<?php

namespace XerAds\Laravel\Seo\Sitemap\Sources;

use Illuminate\Support\Carbon;
use Throwable;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Seo\Models\SeoMeta;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Seo\Sitemap\Contracts\SitemapSource;
use XerAds\Laravel\Seo\Sitemap\SitemapUrl;

/**
 * XerAds' articles in a mapped model: those published, at the address the
 * site reported to XerAds, with the SEO data XerAds sent with them.
 */
final class MappedArticleSource implements SitemapSource
{
    public function __construct(private readonly SiteAddress $address) {}

    public function name(): string
    {
        return 'articles';
    }

    public function urls(): iterable
    {
        $entries = ContentMapEntry::query()
            ->where('target', 'mapped')
            ->where('state', 'published')
            ->whereNotNull('last_url')
            ->orderBy('id');

        foreach ($entries->lazyById(500) as $entry) {
            $meta = $entry->model_type !== null && $entry->model_id !== null
                ? SeoMeta::query()->where('seoable_type', $entry->model_type)->where('seoable_id', $entry->model_id)->first()
                : null;
            $image = $meta?->extra('image.url');
            $modified = $meta?->extra('dates.content_updated_at');

            yield new SitemapUrl(
                loc: $this->address->url((string) $entry->last_url),
                lastmod: $this->time($modified) ?? $entry->updated_at,
                images: is_string($image) && $image !== '' ? [$image] : [],
                index: ($meta?->robots['index'] ?? true) !== false,
                canonical: is_string($meta?->canonical_url) && $meta->canonical_url !== '' ? $meta->canonical_url : null,
                kind: 'article',
            );
        }
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
