<?php

namespace XerAds\Laravel\Seo;

use DateTimeInterface;
use Throwable;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\Category;
use XerAds\Laravel\Content\Models\Tag;
use XerAds\Laravel\Seo\Contracts\ProvidesSeo;
use XerAds\Laravel\Seo\Models\SeoMeta;

/**
 * What a page's model says about its SEO, read once into one shape: a
 * turnkey article, a category or tag, any model with XerAds SEO data
 * (`ProvidesSeo`, such as a mapped post), or a plain array.
 *
 * The SEO data XerAds sent (`xerads_seo_meta`) is used as stored: the SEO
 * title, the description, the article's own canonical, robots and Open Graph
 * overrides. A turnkey article adds what only its own row knows: the image
 * copied to this site, its dates, category, tags and author.
 */
final class SeoSubject
{
    /**
     * @param  array{index?: bool, follow?: bool}|null  $robots
     * @param  array{url: string, width: int|null, height: int|null, alt: string|null}|null  $image
     * @param  array{name: string, url: string|null}|null  $author
     * @param  list<string>  $tags
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $canonical = null,
        public readonly ?array $robots = null,
        public readonly ?string $ogTitle = null,
        public readonly ?string $ogDescription = null,
        public readonly ?array $image = null,
        public readonly bool $isArticle = false,
        public readonly ?string $publishedAt = null,
        public readonly ?string $modifiedAt = null,
        public readonly ?string $language = null,
        public readonly ?array $author = null,
        public readonly ?string $section = null,
        public readonly array $tags = [],
        public readonly array $keywords = [],
        public readonly ?int $wordCount = null,
        public readonly ?string $schemaType = null,
        public readonly ?string $kind = null,
    ) {}

    public static function from(mixed $subject): ?self
    {
        return match (true) {
            $subject instanceof self => $subject,
            $subject instanceof Article => self::fromArticle($subject),
            $subject instanceof Category => new self(title: $subject->name, name: $subject->name, description: self::text($subject->description), kind: 'category'),
            $subject instanceof Tag => new self(title: $subject->name, name: $subject->name, description: self::text($subject->description), kind: 'tag'),
            $subject instanceof ProvidesSeo => self::fromMeta($subject->xeradsSeo()),
            is_array($subject) => self::fromArray($subject),
            default => null,
        };
    }

    private static function fromArticle(Article $article): self
    {
        $meta = $article->xeradsSeo();
        $image = null;

        if (is_string($article->featured_image_url) && $article->featured_image_url !== '') {
            $image = [
                'url' => $article->featured_image_url,
                'width' => $article->featured_image_width,
                'height' => $article->featured_image_height,
                'alt' => $article->featured_image_alt,
            ];
        }

        $author = is_array($article->author) && is_string($article->author['name'] ?? null) && trim($article->author['name']) !== ''
            ? ['name' => trim($article->author['name']), 'url' => self::text($article->author['url'] ?? null)]
            : null;

        return new self(
            title: self::text($meta?->title) ?? $article->title,
            name: $article->displayTitle(),
            description: self::text($meta?->description) ?? self::text($article->excerpt),
            canonical: self::text($meta?->canonical_url),
            robots: self::robots($meta?->robots),
            ogTitle: self::text($meta?->og['title'] ?? null),
            ogDescription: self::text($meta?->og['description'] ?? null),
            image: $image,
            isArticle: true,
            publishedAt: self::time($article->published_at),
            modifiedAt: self::time($article->content_updated_at ?? $article->updated_at),
            language: $article->language,
            author: $author,
            section: $article->primaryCategory?->name,
            tags: $article->relationLoaded('tags') ? array_values($article->tags->pluck('name')->filter()->map(fn ($name) => (string) $name)->all()) : [],
            keywords: self::strings($meta?->keywords),
            wordCount: $article->word_count > 0 ? $article->word_count : null,
            schemaType: self::text($meta?->schema['type'] ?? null),
            kind: 'article',
        );
    }

    private static function fromMeta(?SeoMeta $meta): ?self
    {
        if ($meta === null) {
            return null;
        }

        $image = $meta->extra('image');
        $image = is_array($image) && is_string($image['url'] ?? null) && $image['url'] !== '' ? [
            'url' => $image['url'],
            'width' => is_int($image['width'] ?? null) ? $image['width'] : null,
            'height' => is_int($image['height'] ?? null) ? $image['height'] : null,
            'alt' => self::text($image['alt'] ?? null),
        ] : null;

        $isArticle = is_string($meta->extra('xerads_id')) || is_string($meta->schema['type'] ?? null);
        $wordCount = $meta->extra('word_count');

        return new self(
            title: self::text($meta->title),
            name: self::text($meta->extra('headline')) ?? self::text($meta->title),
            description: self::text($meta->description) ?? self::text($meta->extra('excerpt')),
            canonical: self::text($meta->canonical_url),
            robots: self::robots($meta->robots),
            ogTitle: self::text($meta->og['title'] ?? null),
            ogDescription: self::text($meta->og['description'] ?? null),
            image: $image,
            isArticle: $isArticle,
            publishedAt: self::text($meta->extra('dates.published_at')),
            modifiedAt: self::text($meta->extra('dates.content_updated_at')),
            language: self::text($meta->extra('language')),
            keywords: self::strings($meta->keywords),
            wordCount: is_int($wordCount) && $wordCount > 0 ? $wordCount : null,
            schemaType: self::text($meta->schema['type'] ?? null),
            kind: $isArticle ? 'article' : null,
        );
    }

    /** @param  array<string, mixed>  $data */
    private static function fromArray(array $data): self
    {
        $image = $data['image'] ?? null;

        if (is_string($image)) {
            $image = ['url' => $image];
        }

        return new self(
            title: self::text($data['title'] ?? null),
            name: self::text($data['name'] ?? $data['title'] ?? null),
            description: self::text($data['description'] ?? null),
            canonical: self::text($data['canonical'] ?? null),
            robots: self::robots($data['robots'] ?? null),
            ogTitle: self::text($data['og_title'] ?? null),
            ogDescription: self::text($data['og_description'] ?? null),
            image: is_array($image) && is_string($image['url'] ?? null) ? [
                'url' => $image['url'],
                'width' => is_int($image['width'] ?? null) ? $image['width'] : null,
                'height' => is_int($image['height'] ?? null) ? $image['height'] : null,
                'alt' => self::text($image['alt'] ?? null),
            ] : null,
            isArticle: ($data['type'] ?? null) === 'article',
            publishedAt: self::text($data['published_at'] ?? null),
            modifiedAt: self::text($data['modified_at'] ?? null),
            language: self::text($data['language'] ?? null),
            kind: self::text($data['kind'] ?? null),
        );
    }

    /** @return array{index?: bool, follow?: bool}|null */
    private static function robots(mixed $robots): ?array
    {
        if (! is_array($robots)) {
            return null;
        }

        return array_filter([
            'index' => is_bool($robots['index'] ?? null) ? $robots['index'] : null,
            'follow' => is_bool($robots['follow'] ?? null) ? $robots['follow'] : null,
        ], fn ($value) => $value !== null) ?: null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter(array_map(fn ($value) => self::text($value), $values))) : [];
    }

    private static function time(mixed $value): ?string
    {
        try {
            return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
