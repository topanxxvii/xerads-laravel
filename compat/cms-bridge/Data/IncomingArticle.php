<?php

namespace XerAds\CmsBridge\Data;

use Illuminate\Http\Request;

/**
 * One article as XerAds sends it to the legacy endpoint.
 *
 * A typed object rather than the raw array so a receiver reads `$article->slug`
 * and finds out at once when the contract moves, instead of `$payload['slug']`
 * quietly becoming null.
 *
 * Built defensively: every field is optional, and a value of the wrong type
 * becomes empty rather than a TypeError, because a receiver that crashes on
 * an odd payload fails a publish that a lenient one would have stored.
 */
class IncomingArticle
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISH = 'publish';

    /** @param string[] $keywords */
    public function __construct(
        public readonly string $title,
        public readonly string $seoTitle,
        public readonly string $content,
        public readonly string $slug,
        public readonly ?string $metaDescription,
        public readonly array $keywords,
        public readonly ?string $imageUrl,
        /**
         * 'draft' or 'publish', nothing else.
         *
         * Anything that is not exactly 'publish' — 'pending' and
         * 'private', a missing value, a typo — becomes 'draft'. Publishing by
         * accident is the failure worth designing out.
         */
        public readonly string $status,
        /**
         * The id THIS site returned last time, echoed back.
         *
         * Present so a re-sync updates the row it created before. A receiver
         * that ignores it publishes the same article twice, which is the most
         * common way an integration like this goes wrong.
         */
        public readonly ?string $remoteId,
        /** A short summary. The legacy payload has none; later contracts do. */
        public readonly ?string $excerpt = null,
    ) {}

    /**
     * Read the article from the request body, and only from the body.
     *
     * Not `$request->input()`: that merges in the query string, and picks the
     * body parser from the Content-Type header, and neither is covered by the
     * signature. A captured request replayed with `?cms_post_id=1&content=…`
     * or as text/plain would otherwise be a signed request that overwrites
     * any post with anything. A body that is not a JSON object reads as an
     * empty article.
     */
    public static function fromRequest(Request $request): self
    {
        return self::fromPayload(self::payloadFromRequest($request) ?? []);
    }

    /**
     * The signed body as an array, or null when it is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public static function payloadFromRequest(Request $request): ?array
    {
        $body = $request->getContent();

        // A JSON object, specifically: `[]` and `"text"` are valid JSON too.
        if (! str_starts_with(ltrim($body, " \t\n\r"), '{')) {
            return null;
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /** @param  array<mixed>  $payload */
    public static function fromPayload(array $payload): self
    {
        $title = self::string($payload['title'] ?? null) ?? '';
        $seoTitle = self::string($payload['seo_title'] ?? null);
        $keywords = $payload['keywords'] ?? null;
        $remoteId = self::string($payload['cms_post_id'] ?? null);

        return new self(
            title: $title,
            seoTitle: $seoTitle !== null && $seoTitle !== '' ? $seoTitle : $title,
            content: self::string($payload['content'] ?? null) ?? '',
            slug: self::string($payload['slug'] ?? null) ?? '',
            metaDescription: self::string($payload['meta_description'] ?? null),
            keywords: is_array($keywords)
                ? array_values(array_map('strval', array_filter($keywords, 'is_scalar')))
                : [],
            imageUrl: self::nonEmpty(self::string($payload['image_url'] ?? null)),
            status: self::normalizeStatus($payload['status'] ?? null),
            remoteId: self::nonEmpty($remoteId !== null ? trim($remoteId) : null),
            excerpt: self::string($payload['excerpt'] ?? null),
        );
    }

    /**
     * Did XerAds send this only to check the endpoint answers?
     *
     * Read from the signed body like everything else, so `?test=1` on a real
     * delivery cannot turn it into a no-op.
     */
    public static function isConnectionTest(Request $request): bool
    {
        return self::isConnectionTestPayload(self::payloadFromRequest($request) ?? []);
    }

    /** @param  array<mixed>  $payload */
    public static function isConnectionTestPayload(array $payload): bool
    {
        return filter_var($payload['test'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISH;
    }

    private static function normalizeStatus(mixed $status): string
    {
        return is_string($status) && strtolower(trim($status)) === self::STATUS_PUBLISH
            ? self::STATUS_PUBLISH
            : self::STATUS_DRAFT;
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
