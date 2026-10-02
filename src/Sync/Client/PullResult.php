<?php

namespace XerAds\Laravel\Sync\Client;

/**
 * A conditional pull: either a new document and its ETag, or "not modified"
 * (304), in which case the copy this site holds is still the current one.
 */
final class PullResult
{
    /** @param  array<string, mixed>|null  $document */
    private function __construct(
        public readonly bool $modified,
        public readonly ?array $document,
        public readonly ?string $etag,
    ) {}

    /** @param  array<string, mixed>  $document */
    public static function modified(array $document, ?string $etag): self
    {
        return new self(true, $document, $etag);
    }

    public static function notModified(?string $etag): self
    {
        return new self(false, null, $etag);
    }
}
