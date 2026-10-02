<?php

namespace XerAds\Laravel\Content\Data;

use XerAds\Laravel\Content\Exceptions\InvalidPayload;

/**
 * `data.article` of an `article.upsert`, typed (sites contract, "Article").
 *
 * Every field but the article id is optional, and a value of the wrong type
 * becomes null or empty rather than a TypeError: a field XerAds adds, renames
 * by mistake or leaves out must cost a missing value, never the whole
 * publish. Only a missing id refuses the delivery, because without it there
 * is no way to find the article again.
 */
final class ArticlePayload
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISH = 'publish';

    /**
     * Twenty-six letters and digits. Looser than strict Crockford base32 on
     * purpose: the id is opaque here, only compared and stored.
     */
    private const ID_PATTERN = '/^[0-9A-Za-z]{26}$/';

    /**
     * @param  list<array{id: string, text: string, level: int}>  $toc
     * @param  array{title: string|null, description: string|null, focus_keyword: string|null, keywords: list<string>, canonical_url: string|null, robots: array<string, mixed>, og: array<string, mixed>, twitter: array<string, mixed>, schema_type: string|null, score: int|null}  $seo
     * @param  array{url: string, alt: string|null, caption: string|null, width: int|null, height: int|null, mime: string|null}|null  $image
     * @param  list<array<string, mixed>>  $media
     * @param  array<string, mixed>  $taxonomy
     * @param  array<string, mixed>|null  $author
     * @param  list<array{id: string, lang: string|null, known: bool, min_height: array{mobile: int|null, desktop: int|null}|null}>  $widgets
     * @param  array{created_at: string|null, content_updated_at: string|null, published_at: string|null}  $dates
     */
    public function __construct(
        public readonly string $xeradsId,
        public readonly ?string $remoteId,
        public readonly ?string $revision,
        public readonly string $status,
        public readonly ?string $language,
        public readonly string $title,
        public readonly ?string $headline,
        public readonly string $slug,
        public readonly ?string $excerpt,
        public readonly string $html,
        public readonly ?int $wordCount,
        public readonly ?int $readingTimeMinutes,
        public readonly array $toc,
        public readonly array $seo,
        public readonly ?array $image,
        public readonly array $media,
        public readonly array $taxonomy,
        public readonly ?array $author,
        public readonly array $widgets,
        public readonly array $dates,
    ) {}

    /** @throws InvalidPayload */
    public static function fromArray(mixed $article): self
    {
        if (! is_array($article)) {
            throw new InvalidPayload('The delivery has no article.');
        }

        $xeradsId = self::string($article['xerads_id'] ?? null);

        if ($xeradsId === null || preg_match(self::ID_PATTERN, $xeradsId) !== 1) {
            throw new InvalidPayload('The article has no valid xerads_id.');
        }

        $content = is_array($article['content'] ?? null) ? $article['content'] : [];
        $seo = is_array($article['seo'] ?? null) ? $article['seo'] : [];
        $dates = is_array($article['dates'] ?? null) ? $article['dates'] : [];
        $title = self::string($article['title'] ?? null) ?? '';

        return new self(
            xeradsId: $xeradsId,
            remoteId: self::nonEmpty($article['remote_id'] ?? null),
            revision: self::nonEmpty($article['revision'] ?? null),
            status: ($article['status'] ?? null) === self::STATUS_PUBLISH ? self::STATUS_PUBLISH : self::STATUS_DRAFT,
            language: self::nonEmpty($article['language'] ?? null),
            title: $title,
            headline: self::nonEmpty($article['headline'] ?? null),
            slug: self::string($article['slug'] ?? null) ?? '',
            excerpt: self::nonEmpty($article['excerpt'] ?? null),
            html: self::string($content['html'] ?? null) ?? '',
            wordCount: self::integer($content['word_count'] ?? null),
            readingTimeMinutes: self::integer($content['reading_time_minutes'] ?? null),
            toc: self::toc($article['toc'] ?? null),
            seo: [
                'title' => self::nonEmpty($seo['title'] ?? null),
                'description' => self::nonEmpty($seo['description'] ?? null),
                'focus_keyword' => self::nonEmpty($seo['focus_keyword'] ?? null),
                'keywords' => self::strings($seo['keywords'] ?? null),
                'canonical_url' => self::nonEmpty($seo['canonical_url'] ?? null),
                'robots' => self::map($seo['robots'] ?? null),
                'og' => self::map($seo['og'] ?? null),
                'twitter' => self::map($seo['twitter'] ?? null),
                'schema_type' => self::nonEmpty($seo['schema_type'] ?? null),
                'score' => self::integer($seo['score'] ?? null),
            ],
            image: self::image($article['image'] ?? null),
            media: array_values(array_filter(is_array($article['media'] ?? null) ? $article['media'] : [], 'is_array')),
            taxonomy: self::map($article['taxonomy'] ?? null),
            author: is_array($article['author'] ?? null) ? $article['author'] : null,
            widgets: self::widgets($article['widgets'] ?? null),
            dates: [
                'created_at' => self::nonEmpty($dates['created_at'] ?? null),
                'content_updated_at' => self::nonEmpty($dates['content_updated_at'] ?? null),
                'published_at' => self::nonEmpty($dates['published_at'] ?? null),
            ],
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISH;
    }

    /** The title for search results: the SEO title, else the title. */
    public function seoTitle(): string
    {
        return $this->seo['title'] ?? $this->title;
    }

    /** The meta description: the SEO description, else the excerpt. */
    public function seoDescription(): ?string
    {
        return $this->seo['description'] ?? $this->excerpt;
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return $this->seo['keywords'];
    }

    public function imageUrl(): ?string
    {
        return $this->image['url'] ?? null;
    }

    /**
     * Widget heights XerAds already knows, by widget id: the mobile
     * `min_height` of each placed widget. Reserving that much space before the
     * runtime loads keeps the page from jumping when the widget appears.
     *
     * @return array<string, int>
     */
    public function widgetHeights(): array
    {
        $heights = [];

        foreach ($this->widgets as $widget) {
            $height = $widget['min_height']['mobile'] ?? null;

            if (is_int($height) && $height > 0) {
                $heights[$widget['id']] = $height;
            }
        }

        return $heights;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        $value = self::string($value);

        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) ? $value : [];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            $item = self::nonEmpty($item);

            if ($item !== null) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /** @return list<array{id: string, text: string, level: int}> */
    private static function toc(mixed $value): array
    {
        $toc = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            $id = is_array($entry) ? self::nonEmpty($entry['id'] ?? null) : null;

            if ($id !== null) {
                $toc[] = ['id' => $id, 'text' => self::string($entry['text'] ?? null) ?? '', 'level' => self::integer($entry['level'] ?? null) ?? 2];
            }
        }

        return $toc;
    }

    /** @return array{url: string, alt: string|null, caption: string|null, width: int|null, height: int|null, mime: string|null}|null */
    private static function image(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $url = self::nonEmpty($value['url'] ?? null);

        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'alt' => self::nonEmpty($value['alt'] ?? null),
            'caption' => self::nonEmpty($value['caption'] ?? null),
            'width' => self::integer($value['width'] ?? null),
            'height' => self::integer($value['height'] ?? null),
            'mime' => self::nonEmpty($value['mime'] ?? null),
        ];
    }

    /** @return list<array{id: string, lang: string|null, known: bool, min_height: array{mobile: int|null, desktop: int|null}|null}> */
    private static function widgets(mixed $value): array
    {
        $widgets = [];

        foreach (is_array($value) ? $value : [] as $widget) {
            if (! is_array($widget)) {
                continue;
            }

            $id = self::nonEmpty($widget['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $minHeight = is_array($widget['min_height'] ?? null) ? $widget['min_height'] : null;

            $widgets[] = [
                'id' => $id,
                'lang' => self::nonEmpty($widget['lang'] ?? null),
                'known' => ($widget['known'] ?? false) === true,
                'min_height' => $minHeight !== null ? [
                    'mobile' => self::integer($minHeight['mobile'] ?? null),
                    'desktop' => self::integer($minHeight['desktop'] ?? null),
                ] : null,
            ];
        }

        return $widgets;
    }
}
