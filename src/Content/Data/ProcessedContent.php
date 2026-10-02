<?php

namespace XerAds\Laravel\Content\Data;

/**
 * What the content pipeline made of an article's HTML.
 *
 * Two bodies: `sourceHtml` keeps `[xerads_widget]` placeholders, for sites
 * that expand them when the page renders; `compiledHtml` has them replaced by
 * widget containers already, for a template that prints the body as it is.
 */
final class ProcessedContent
{
    /** @param  list<array{id: string, text: string, level: int}>  $toc */
    public function __construct(
        public readonly string $sourceHtml,
        public readonly string $compiledHtml,
        public readonly array $toc,
        public readonly int $wordCount,
        public readonly int $readingTimeMinutes,
        public readonly ?string $excerpt,
    ) {}

    /** The body for the configured `content.mapped.content_format`. */
    public function forFormat(string $format): string
    {
        return $format === 'shortcode' ? $this->sourceHtml : $this->compiledHtml;
    }
}
