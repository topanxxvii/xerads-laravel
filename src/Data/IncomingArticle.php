<?php

namespace XerAds\CmsBridge\Data;

use Illuminate\Http\Request;

/**
 * One article as XerAds sends it.
 *
 * A typed object rather than the raw array so a receiver reads `$article->slug`
 * and finds out at once when the contract moves, instead of `$payload['slug']`
 * quietly becoming null.
 */
class IncomingArticle
{
    /** @param string[] $keywords */
    public function __construct(
        public readonly string $title,
        public readonly string $seoTitle,
        public readonly string $content,
        public readonly string $slug,
        public readonly ?string $metaDescription,
        public readonly array $keywords,
        public readonly ?string $imageUrl,
        public readonly string $status,
        /**
         * The id THIS site returned last time, echoed back.
         *
         * Present so a re-sync updates the row it created before. A receiver
         * that ignores it publishes the same article twice, which is the most
         * common way an integration like this goes wrong.
         */
        public readonly ?string $remoteId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $keywords = $request->input('keywords');

        return new self(
            title: (string) $request->input('title', ''),
            seoTitle: (string) ($request->input('seo_title') ?: $request->input('title', '')),
            content: (string) $request->input('content', ''),
            slug: (string) $request->input('slug', ''),
            metaDescription: $request->input('meta_description'),
            keywords: is_array($keywords) ? array_values(array_map('strval', $keywords)) : [],
            imageUrl: $request->input('image_url'),
            // 'draft' or 'publish'. Defaulted rather than required so an older
            // sender that omits it cannot publish something unintentionally.
            status: (string) $request->input('status', 'draft'),
            remoteId: $request->filled('cms_post_id') ? (string) $request->input('cms_post_id') : null,
        );
    }

    /** Did XerAds send this only to check the endpoint answers? */
    public static function isConnectionTest(Request $request): bool
    {
        return $request->boolean('test');
    }
}
