<?php

namespace XerAds\Laravel\Content\Pipeline;

use DOMElement;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use XerAds\Laravel\Content\Contracts\PipelineStep;

/**
 * An allowlist of article markup; everything else goes.
 *
 * XerAds strips scripts before sending, but this site serves the HTML, so it
 * cleans it again rather than trusting the sender: no scripts, no event
 * handlers, no `javascript:` links, no styles, no iframes. Widget placeholders
 * are plain text, so they pass untouched; the containers they become are
 * added after this step by CompileWidgets, from ids it has checked.
 *
 * So no `data-xerads-*` attribute passes here: an attribute that survived
 * sanitising would be a container nobody checked. That matters because this
 * step and the placeholder step may parse HTML differently (the sanitiser
 * uses PHP's own HTML5 parser where available), and markup one of them reads
 * as a comment the other may read as an element.
 *
 * ── Two things the sanitiser does not do by default ─────────────────────────
 * - It cuts input at 20,000 bytes, silently. A long article is far bigger, so
 *   the limit comes from `sanitizer.max_input_length`.
 * - It drops an element it does not know together with everything inside
 *   it. Layout wrappers (`<section>`, `<article>`, `<small>` …) would take
 *   their paragraphs with them, so those are unwrapped instead: the tag goes,
 *   the text stays.
 */
final class SanitizeHtml implements PipelineStep
{
    /** Element => allowed attributes. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'h5' => ['id'], 'h6' => ['id'],
        'ul' => [], 'ol' => [], 'li' => [],
        'a' => ['href', 'title', 'rel', 'target'],
        'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 'mark' => [], 's' => [], 'sub' => [], 'sup' => [],
        'blockquote' => [], 'code' => [], 'pre' => [],
        'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'figure' => [], 'figcaption' => [],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
    ];

    /** Wrappers whose content is kept when the tag itself is not. */
    private const UNWRAPPED = [
        'article', 'section', 'header', 'footer', 'main', 'aside', 'nav', 'hgroup', 'address',
        'small', 'big', 'abbr', 'cite', 'q', 'time', 'data', 'dfn', 'del', 'ins', 'kbd', 'samp', 'var',
        'bdi', 'bdo', 'font', 'center', 'label', 'details', 'summary', 'dl', 'dt', 'dd', 'picture',
        'colgroup', 'col', 'h1', 'body', 'html',
    ];

    public function __construct(private readonly Repository $config) {}

    public function process(ContentDocument $document): void
    {
        $html = $document->html();

        $document->setHtml((new HtmlSanitizer($this->sanitizerConfig()))->sanitize($html));

        $this->protectNewWindows($document);
    }

    private function sanitizerConfig(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https', 'http'])
            // Root-relative links name pages on this site; keep them.
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(max(-1, (int) $this->config->get('xerads.sanitizer.max_input_length', 2_000_000)));

        foreach (self::ALLOWED as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        // Kept as structure, with no attribute at all: no class, no style,
        // no event handler, no widget container that skipped CompileWidgets.
        $config = $config->allowElement('div')->allowElement('span');

        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }

        foreach ((array) $this->config->get('xerads.sanitizer.allow_elements', []) as $element => $attributes) {
            if (is_int($element) && is_string($attributes)) {
                $config = $config->allowElement($attributes);
            } elseif (is_string($element)) {
                $config = $config->allowElement($element, is_array($attributes) || $attributes === '*' ? $attributes : []);
            }
        }

        return $config;
    }

    /**
     * A link that opens a new browsing context gets `rel="noopener"`, so the
     * page it opens cannot navigate this one through `window.opener`.
     * Browsers imply that only for `_blank`; any other name but `_self`,
     * `_parent` and `_top` opens a new context too, and gets it explicitly.
     *
     * Always through the DOM, which also re-serialises the sanitiser's output:
     * it writes `=` and `"` in text as entities, and a placeholder stored as
     * `id&#61;&#34;w_…&#34;` is legible to nobody.
     */
    private function protectNewWindows(ContentDocument $document): void
    {
        foreach ($document->dom()->query('.//a[@target]') as $link) {
            if (! $link instanceof DOMElement || in_array(strtolower(trim($link->getAttribute('target'))), ['', '_self', '_parent', '_top'], true)) {
                continue;
            }

            $rel = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (! in_array('noopener', $rel, true)) {
                $rel[] = 'noopener';
            }

            $link->setAttribute('rel', implode(' ', $rel));
        }
    }
}
