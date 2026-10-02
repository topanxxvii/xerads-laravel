<?php

namespace XerAds\Laravel\Content\Data;

use XerAds\Laravel\Content\Exceptions\InvalidPayload;

/**
 * The article an `article.unpublish` or `article.delete` names: just enough
 * to find it, since there is no content to apply.
 */
final class ArticleReference
{
    public function __construct(
        public readonly string $xeradsId,
        public readonly ?string $remoteId = null,
        public readonly ?string $slug = null,
        public readonly ?string $publicUrl = null,
    ) {}

    /** @throws InvalidPayload */
    public static function fromArray(mixed $article): self
    {
        if (! is_array($article)) {
            throw new InvalidPayload('The delivery names no article.');
        }

        $id = is_string($article['xerads_id'] ?? null) ? trim($article['xerads_id']) : '';

        if ($id === '' || preg_match('/^[0-9A-Za-z]{26}$/', $id) !== 1) {
            throw new InvalidPayload('The delivery names no valid xerads_id.');
        }

        $string = fn (mixed $value) => is_string($value) || is_int($value) ? ((string) $value !== '' ? (string) $value : null) : null;

        return new self($id, $string($article['remote_id'] ?? null), $string($article['slug'] ?? null), $string($article['public_url'] ?? null));
    }

    public static function forPayload(ArticlePayload $article): self
    {
        return new self($article->xeradsId, $article->remoteId, $article->slug);
    }
}
