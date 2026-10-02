<?php

namespace XerAds\Laravel\Seo\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use XerAds\Laravel\Seo\Models\SeoMeta;

/**
 * Add to a model whose rows XerAds writes (or that has SEO data of its own):
 *
 *     class Post extends Model implements ProvidesSeo
 *     {
 *         use HasXeradsSeo;
 *     }
 *
 *     $post->xeradsSeo()?->title;
 *
 * The package stores SEO data for mapped articles whether or not the model
 * uses this trait; the trait is only how the site reads it back.
 *
 * @phpstan-require-extends Model
 */
trait HasXeradsSeo
{
    /** @return MorphOne<SeoMeta, $this> */
    public function seoMeta(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /** Loaded once and kept, like any other relation. */
    public function xeradsSeo(): ?SeoMeta
    {
        if (! $this->relationLoaded('seoMeta')) {
            $this->setRelation('seoMeta', $this->seoMeta()->getResults());
        }

        $meta = $this->getRelation('seoMeta');

        return $meta instanceof SeoMeta ? $meta : null;
    }

    /**
     * One SEO value, or the default when there is none:
     * `$post->xeradsSeoValue('title', $post->title)`, `$post->xeradsSeoValue('headline')`.
     *
     * A column when the row has one, else a key of `extras`. The column is
     * read only if it is there: a model in strict mode throws on a missing
     * attribute, and `headline` or `toc` are not columns.
     */
    public function xeradsSeoValue(string $key, mixed $default = null): mixed
    {
        $meta = $this->xeradsSeo();

        if ($meta === null) {
            return $default;
        }

        if (array_key_exists($key, $meta->getAttributes()) && $meta->getAttribute($key) !== null) {
            return $meta->getAttribute($key);
        }

        return $meta->extra($key, $default);
    }
}
