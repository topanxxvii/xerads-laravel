<?php

namespace XerAds\Laravel\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Support\Concerns\UsesXeradsTables;

/**
 * Which XerAds article became which row on this site (`xerads_content_map`).
 *
 * Kept by the package rather than as a column on the site's own table, so a
 * site's schema needs nothing new. It is also what makes ordering safe: the
 * highest `sequence` applied per article is here, and an older event that
 * arrives late is recognised and dropped.
 *
 * @property string $xerads_id
 * @property string $target
 * @property string|null $site_id
 * @property string|null $model_type
 * @property string|null $model_id
 * @property string|null $remote_id
 * @property int $sequence
 * @property string|null $revision
 * @property string $state
 * @property string|null $last_delivery_id
 * @property string|null $last_url
 * @property Carbon|null $first_published_at
 */
class ContentMapEntry extends Model
{
    use UsesXeradsTables;

    /**
     * Delivery ids `xerads:simulate` uses. Part of the signed request, so a
     * real delivery cannot pretend to be one.
     */
    public const SIMULATED_DELIVERY_PREFIX = 'simulated-';

    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'first_published_at' => 'datetime',
    ];

    public static function forArticle(string $xeradsId): ?self
    {
        return static::query()->where('xerads_id', $xeradsId)->first();
    }

    public function isPublished(): bool
    {
        return $this->state === 'published';
    }

    /** Has this article ever been public on this site, whatever it is now? */
    public function wasEverPublished(): bool
    {
        return $this->first_published_at !== null || $this->isPublished();
    }

    /** Was this entry last written by `xerads:simulate` rather than by XerAds? */
    public function wasSimulated(): bool
    {
        return str_starts_with((string) $this->last_delivery_id, self::SIMULATED_DELIVERY_PREFIX);
    }

    protected function xeradsTable(): string
    {
        return 'content_map';
    }
}
