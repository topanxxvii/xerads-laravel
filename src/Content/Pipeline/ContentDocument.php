<?php

namespace XerAds\Laravel\Content\Pipeline;

use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\ProcessedContent;
use XerAds\Laravel\Support\Html\Dom;

/**
 * The article body as it moves through the pipeline.
 *
 * Held as a string or as a DOM, whichever the last step used, and converted
 * only when the next step needs the other form: string steps (the sanitiser,
 * pattern replacements) and DOM steps (headings, widgets) can follow each
 * other without parsing the body once per step.
 */
final class ContentDocument
{
    private ?Dom $dom = null;

    /** @var list<array{id: string, text: string, level: int}> */
    public array $toc = [];

    public int $wordCount = 0;

    public int $readingTimeMinutes = 0;

    /** The body with widget containers in place of placeholders; set by CompileWidgets. */
    public ?string $compiledHtml = null;

    /** @param  array<string, int>  $widgetHeights  known widget heights by id */
    public function __construct(
        private string $html,
        public readonly ?string $language = null,
        public readonly array $widgetHeights = [],
        public ?string $excerpt = null,
    ) {}

    public static function forArticle(ArticlePayload $article): self
    {
        return new self($article->html, $article->language, $article->widgetHeights(), $article->excerpt);
    }

    public function html(): string
    {
        return $this->dom !== null ? $this->dom->html() : $this->html;
    }

    public function setHtml(string $html): void
    {
        $this->html = $html;
        $this->dom = null;
    }

    /** The body as a DOM; changes to it are what html() returns next. */
    public function dom(): Dom
    {
        return $this->dom ??= Dom::fragment($this->html);
    }

    public function toProcessedContent(): ProcessedContent
    {
        $source = $this->html();

        return new ProcessedContent(
            sourceHtml: $source,
            compiledHtml: $this->compiledHtml ?? $source,
            toc: $this->toc,
            wordCount: $this->wordCount,
            readingTimeMinutes: $this->readingTimeMinutes,
            excerpt: $this->excerpt,
        );
    }
}
