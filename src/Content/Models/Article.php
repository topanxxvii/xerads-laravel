<?php

namespace XerAds\Laravel\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use XerAds\Laravel\Seo\Concerns\HasXeradsSeo;
use XerAds\Laravel\Seo\Contracts\ProvidesSeo;
use XerAds\Laravel\Support\Concerns\UsesXeradsTables;

/**
 * An article of the turnkey blog (`xerads_articles`).
 *
 * Public only while `status` is published and `published_at` has come: a
 * draft, an unpublished article and a deleted one all answer 404 (410 once
 * deleted, through the redirect table), whatever their slug.
 *
 * @property int $id
 * @property string|null $xerads_id
 * @property string|null $language
 * @property string $title
 * @property string|null $headline
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body_source
 * @property string $body_html
 * @property list<array{id: string, text: string, level: int}>|null $toc
 * @property string|null $featured_image_url
 * @property string|null $featured_image_alt
 * @property string|null $featured_image_caption
 * @property int|null $featured_image_width
 * @property int|null $featured_image_height
 * @property string|null $featured_image_mime
 * @property string|null $featured_image_path
 * @property array<string, mixed>|null $author
 * @property int|null $primary_category_id
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon|null $content_updated_at
 * @property int $word_count
 * @property int $reading_time
 * @property string|null $revision
 * @property list<array<string, mixed>>|null $widgets
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Article extends Model implements ProvidesSeo
{
    use HasXeradsSeo;
    use SoftDeletes;
    use UsesXeradsTables;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    protected $guarded = [];

    protected $casts = [
        'toc' => 'array',
        'author' => 'array',
        'widgets' => 'array',
        'published_at' => 'datetime',
        'content_updated_at' => 'datetime',
        'word_count' => 'integer',
        'reading_time' => 'integer',
        'featured_image_width' => 'integer',
        'featured_image_height' => 'integer',
    ];

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, self::table('article_category'), 'article_id', 'category_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, self::table('article_tag'), 'article_id', 'tag_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /**
     * Articles readers may see: published, and not scheduled for later.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', Carbon::now());
    }

    public function isPublic(): bool
    {
        return ! $this->trashed()
            && $this->status === self::PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lte(Carbon::now());
    }

    /** The page's one H1: the headline XerAds moved out of the body, else the title. */
    public function displayTitle(): string
    {
        return $this->headline !== null && $this->headline !== '' ? $this->headline : $this->title;
    }

    /** The article's public address, whether or not it is public right now. */
    public function url(): string
    {
        return app(Router::class)->has('xerads.blog.show')
            ? route('xerads.blog.show', ['slug' => $this->slug])
            : url(self::pathFor($this->slug));
    }

    /**
     * A link that shows the article whatever its status, for XerAds to open
     * a draft. Signed, and tied to the XerAds id rather than the row: it
     * never expires, stops working once the article is deleted, and does
     * not survive an APP_KEY rotation.
     */
    public function previewUrl(): ?string
    {
        if ($this->xerads_id === null || ! app(Router::class)->has('xerads.blog.preview')) {
            return null;
        }

        return URL::signedRoute('xerads.blog.preview', ['article' => $this->xerads_id]);
    }

    /** `/blog/{slug}`: the path a slug is served at, for redirect rows. */
    public static function pathFor(string $slug): string
    {
        return '/'.self::prefix().'/'.$slug;
    }

    /** The blog's URL prefix, never empty: an empty one would catch every path of the site. */
    public static function prefix(): string
    {
        $prefix = trim((string) config('xerads.content.turnkey.prefix', 'blog'), '/');

        return $prefix !== '' ? $prefix : 'blog';
    }

    /**
     * Widget heights XerAds sent with the article, by widget id.
     *
     * @return array<string, int>
     */
    public function widgetHeights(): array
    {
        $heights = [];

        foreach ($this->widgets ?? [] as $widget) {
            $height = $widget['min_height']['mobile'] ?? null;

            if (is_string($widget['id'] ?? null) && is_int($height) && $height > 0) {
                $heights[$widget['id']] = $height;
            }
        }

        return $heights;
    }

    protected function xeradsTable(): string
    {
        return 'articles';
    }

    private static function table(string $table): string
    {
        return (string) config('xerads.database.table_prefix', 'xerads_').$table;
    }
}
