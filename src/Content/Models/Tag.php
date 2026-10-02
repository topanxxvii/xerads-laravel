<?php

namespace XerAds\Laravel\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Routing\Router;
use XerAds\Laravel\Support\Concerns\UsesXeradsTables;

/**
 * A turnkey blog tag (`xerads_tags`), found by its slug.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 */
class Tag extends Model
{
    use UsesXeradsTables;

    protected $guarded = [];

    /** @return BelongsToMany<Article, $this> */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, (string) config('xerads.database.table_prefix', 'xerads_').'article_tag', 'tag_id', 'article_id');
    }

    public function url(): ?string
    {
        return app(Router::class)->has('xerads.blog.tag') ? route('xerads.blog.tag', ['slug' => $this->slug]) : null;
    }

    protected function xeradsTable(): string
    {
        return 'tags';
    }
}
