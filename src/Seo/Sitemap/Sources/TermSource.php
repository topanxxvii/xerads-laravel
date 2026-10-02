<?php

namespace XerAds\Laravel\Seo\Sitemap\Sources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\Category;
use XerAds\Laravel\Content\Models\Tag;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Seo\Sitemap\Contracts\SitemapSource;
use XerAds\Laravel\Seo\Sitemap\SitemapUrl;

/**
 * The turnkey blog's categories or tags that have a published article (the
 * others answer 404), dated by their newest article.
 */
final class TermSource implements SitemapSource
{
    /** @param  'categories'|'tags'  $kind */
    public function __construct(
        private readonly SiteAddress $address,
        private readonly string $kind,
    ) {}

    public function name(): string
    {
        return $this->kind;
    }

    public function urls(): iterable
    {
        $published = fn (Builder $query) => $query->scopes(['published']);
        $query = $this->kind === 'categories' ? Category::query() : Tag::query();
        $segment = $this->kind === 'categories' ? 'category' : 'tag';

        $terms = $query->whereHas('articles', $published)
            ->withMax(['articles' => $published], 'content_updated_at')
            ->orderBy('id')
            ->get();

        foreach ($terms as $term) {
            $lastmod = $term->getAttribute('articles_max_content_updated_at');

            yield new SitemapUrl(
                loc: $this->address->url('/'.Article::prefix().'/'.$segment.'/'.$term->slug),
                lastmod: is_string($lastmod) && $lastmod !== '' ? Carbon::parse($lastmod) : null,
                kind: $this->kind === 'categories' ? 'category' : 'tag',
            );
        }
    }
}
