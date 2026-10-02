<?php

namespace XerAds\Laravel\Seo\Sitemap\Sources;

use Illuminate\Database\Eloquent\Model;
use XerAds\Laravel\Content\Receivers\MappedModel;
use XerAds\Laravel\Seo\Contracts\ProvidesSeo;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Seo\Sitemap\Contracts\SitemapSource;
use XerAds\Laravel\Seo\Sitemap\SitemapUrl;

/**
 * A model of the site's own, from `xerads.sitemap.models` (name => class).
 *
 * Its rows are listed through `scopeXeradsSitemap()` when the model has one
 * (to leave out drafts, say), at the address its `xeradsSitemapUrl()`
 * returns; the model configured for mapped articles uses its public route
 * without one. A model with HasXeradsSeo has its SEO data checked like any
 * article: noindexed rows and rows with another canonical are left out.
 */
final class ModelSource implements SitemapSource
{
    /** @param  class-string<Model>  $class */
    public function __construct(
        private readonly string $name,
        private readonly string $class,
        private readonly SiteAddress $address,
        private readonly MappedModel $mapped,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function urls(): iterable
    {
        $model = new $this->class;
        $query = $model->newQuery();

        if (method_exists($model, 'scopeXeradsSitemap')) {
            // By name: the scope is the site's own, unknown to the package.
            $query->scopes(['xeradsSitemap']);
        }

        $isMapped = ltrim($this->class, '\\') === $this->mapped->modelClass();

        foreach ($query->lazyById(500) as $record) {
            $url = match (true) {
                method_exists($record, 'xeradsSitemapUrl') => $record->xeradsSitemapUrl(),
                $isMapped => $this->mapped->publicUrl($record, $this->mapped->fields()),
                default => null,
            };

            if (! is_string($url) || $url === '') {
                continue;
            }

            $meta = $record instanceof ProvidesSeo ? $record->xeradsSeo() : null;
            $image = $meta?->extra('image.url');
            $column = $record->getUpdatedAtColumn() ?? 'updated_at';
            $updated = array_key_exists($column, $record->getAttributes()) ? $record->getAttribute($column) : null;

            yield new SitemapUrl(
                loc: $this->address->url($url),
                lastmod: $updated instanceof \DateTimeInterface ? $updated : null,
                images: is_string($image) && $image !== '' ? [$image] : [],
                index: ($meta?->robots['index'] ?? true) !== false,
                canonical: is_string($meta?->canonical_url) && $meta->canonical_url !== '' ? $meta->canonical_url : null,
            );
        }
    }
}
