<?php

namespace XerAds\Laravel\Content\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * Where an article landed on this site, as a receiver reports it.
 *
 * `id` and `url` go back to XerAds: the id is echoed as `remote_id` on the
 * next delivery, and the url becomes the article's public address there, so
 * it is set only when the article is actually published.
 */
final class Receipt
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    public const DELETED = 'deleted';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string|int|null $id,
        public readonly string $state,
        public readonly ?string $url = null,
        public readonly ?string $previewUrl = null,
        public readonly bool $created = false,
        /** The stored row, for the content map and for listeners. */
        public readonly ?Model $model = null,
    ) {}

    /** For a removal of an article this site never stored, or no longer has. */
    public static function unknown(): self
    {
        return new self(null, self::UNKNOWN);
    }

    public function isPublished(): bool
    {
        return $this->state === self::PUBLISHED;
    }
}
