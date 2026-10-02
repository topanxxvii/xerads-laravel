<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use XerAds\Laravel\Seo\Concerns\HasXeradsSeo;
use XerAds\Laravel\Seo\Contracts\ProvidesSeo;

/**
 * A site's own post model, the way the mapped mode meets it in the wild: a
 * NOT NULL author column XerAds knows nothing about, a unique slug, and its
 * own idea of what the status column holds.
 */
class Post extends Model implements ProvidesSeo
{
    use HasXeradsSeo;

    protected $table = 'posts';

    protected $guarded = [];
}
