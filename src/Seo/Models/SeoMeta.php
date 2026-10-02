<?php

namespace XerAds\Laravel\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use XerAds\Laravel\Support\Concerns\UsesXeradsTables;

/**
 * SEO data for any model on the site (`xerads_seo_meta`): what XerAds sent
 * with an article, or what the site set itself.
 *
 * A row per model instead of columns on the model's table, so the site's own
 * schema stays untouched. Fields listed in `locked_fields` were set on the
 * site and are never overwritten by a later delivery.
 *
 * The head (`<x-xerads::head>`, `@xeradsHead`, `Xerads::head()->for($model)`)
 * reads it through ProvidesSeo, for the page's title, description, canonical,
 * robots and share tags.
 *
 * @property string $seoable_type
 * @property int|string $seoable_id
 * @property string|null $title
 * @property string|null $description
 * @property string|null $focus_keyword
 * @property list<string>|null $keywords
 * @property string|null $canonical_url
 * @property array<string, mixed>|null $robots
 * @property array<string, mixed>|null $og
 * @property array<string, mixed>|null $twitter
 * @property array<string, mixed>|null $schema
 * @property array<string, mixed>|null $breadcrumbs
 * @property array<string, mixed>|null $extras
 * @property string $source
 * @property list<string>|null $locked_fields
 */
class SeoMeta extends Model
{
    use UsesXeradsTables;

    protected $guarded = [];

    protected $casts = [
        'keywords' => 'array',
        'robots' => 'array',
        'og' => 'array',
        'twitter' => 'array',
        'schema' => 'array',
        'breadcrumbs' => 'array',
        'extras' => 'array',
        'locked_fields' => 'array',
    ];

    /** @return MorphTo<Model, $this> */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isLocked(string $field): bool
    {
        return in_array($field, $this->locked_fields ?? [], true);
    }

    /**
     * A value from `extras`, which holds what has no column of its own:
     * headline, table of contents, word count, widget heights, score.
     */
    public function extra(string $key, mixed $default = null): mixed
    {
        return data_get($this->extras ?? [], $key, $default);
    }

    /**
     * Widget heights recorded with the article, by widget id, for expanding
     * `[xerads_widget]` placeholders at render time without a request.
     *
     * @return array<string, int>
     */
    public function widgetHeights(): array
    {
        $heights = [];

        foreach ((array) $this->extra('widgets', []) as $widget) {
            $height = is_array($widget) ? ($widget['min_height']['mobile'] ?? null) : null;

            if (is_array($widget) && is_string($widget['id'] ?? null) && is_int($height) && $height > 0) {
                $heights[$widget['id']] = $height;
            }
        }

        return $heights;
    }

    protected function xeradsTable(): string
    {
        return 'seo_meta';
    }
}
