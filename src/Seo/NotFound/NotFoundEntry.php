<?php

namespace XerAds\Laravel\Seo\NotFound;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Support\Concerns\UsesXeradsTables;

/**
 * A path that answered 404, and how often (`xerads_not_found`).
 *
 * @property int $id
 * @property string $path
 * @property string $path_hash
 * @property int $hits
 * @property int $reported_hits
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property string|null $last_referrer_host
 * @property string $status
 * @property Carbon|null $reported_at
 */
class NotFoundEntry extends Model
{
    use UsesXeradsTables;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $guarded = [];

    protected $casts = [
        'hits' => 'integer',
        'reported_hits' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'reported_at' => 'datetime',
    ];

    protected function xeradsTable(): string
    {
        return 'not_found';
    }
}
