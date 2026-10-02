<?php

namespace XerAds\Laravel\Content\Pipeline;

use XerAds\Laravel\Content\Contracts\PipelineStep;
use XerAds\Laravel\Widgets\ShortcodeParser;

/**
 * Word count, reading time and a fallback excerpt.
 *
 * Counted on what a reader reads: tags, entities and widget placeholders
 * removed, words split on any Unicode whitespace so Indonesian, accented and
 * non-Latin text count the same way. 225 words a minute, as XerAds counts.
 */
final class ComputeStats implements PipelineStep
{
    public const WORDS_PER_MINUTE = 225;

    private const EXCERPT_LENGTH = 160;

    public function process(ContentDocument $document): void
    {
        $text = $this->readableText($document->html());
        $words = $text === '' ? [] : (preg_split('/\s+/u', $text) ?: []);

        $document->wordCount = count($words);
        $document->readingTimeMinutes = max(1, (int) ceil($document->wordCount / self::WORDS_PER_MINUTE));

        if ($document->excerpt === null || trim($document->excerpt) === '') {
            $document->excerpt = $text !== '' ? $this->excerpt($text) : null;
        }
    }

    /** The opening words, cut at a word boundary. */
    private function excerpt(string $text): string
    {
        if (mb_strlen($text) <= self::EXCERPT_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::EXCERPT_LENGTH);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 0 ? mb_substr($cut, 0, $space) : $cut, ' ,;:.').'…';
    }

    private function readableText(string $html): string
    {
        // A space before every tag, so "</p><p>" does not glue two words.
        $text = strip_tags(str_replace('<', ' <', $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace(ShortcodeParser::PATTERN, ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
