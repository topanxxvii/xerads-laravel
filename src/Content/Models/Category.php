<?php

namespace XerAds\Laravel\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Routing\Router;
use XerAds\Laravel\Support\Concerns\UsesXeradsTables;

/**
 * A turnkey blog category (`xerads_categories`), found by its slug.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 */
class Category extends Model
{
    use UsesXeradsTables;

    protected $guarded = [];

    /** @return BelongsToMany<Article, $this> */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, (string) config('xerads.database.table_prefix', 'xerads_').'article_category', 'category_id', 'article_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function url(): ?string
    {
        return app(Router::class)->has('xerads.blog.category') ? route('xerads.blog.category', ['slug' => $this->slug]) : null;
    }

    protected function xeradsTable(): string
    {
        return 'categories';
    }
}
