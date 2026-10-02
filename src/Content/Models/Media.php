<?php

namespace XerAds\Laravel\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use XerAds\Laravel\Support\Concerns\UsesXeradsTables;

/**
 * An image copied from XerAds onto this site's disk (`xerads_media`).
 *
 * Keyed by the hash of the URL it came from, so an image used by several
 * articles, or sent again with every edit of one, is downloaded once.
 *
 * @property int $id
 * @property string $source_url_hash
 * @property string $source_url
 * @property string $disk
 * @property string $path
 * @property string|null $mime
 * @property int|null $width
 * @property int|null $height
 * @property int|null $bytes
 * @property string|null $alt
 */
class Media extends Model
{
    use UsesXeradsTables;

    protected $guarded = [];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
    ];

    public static function hashOf(string $sourceUrl): string
    {
        return sha1($sourceUrl);
    }

    /** Where the copy is served from. */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    protected function xeradsTable(): string
    {
        return 'media';
    }
}
