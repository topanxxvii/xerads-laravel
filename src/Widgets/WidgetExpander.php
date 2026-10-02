<?php

namespace XerAds\Laravel\Widgets;

use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Contracts\Config\Repository;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Support\Html\Dom;

/**
 * Turns `[xerads_widget]` placeholders into widget containers.
 *
 * The container is exactly the one in widgets contract §3.1, the same markup
 * the dashboard's "script" embed code has:
 *
 *     <div data-xerads-widget="{id}" data-lang="{lang}" style="min-height:{H}px"></div>
 *
 * The loader finds it, checks the widget's document and mounts or removes it.
 * The height only reserves space; it comes from what XerAds sent with the
 * article, else the widget's public document, else `widgets.fallback_height`.
 *
 * Expansion is a DOM pass, so a placeholder inside `<pre>` or `<code>` (an
 * article explaining the syntax) stays text, and a paragraph that holds
 * nothing but a placeholder is replaced whole rather than left wrapping a
 * block element, which browsers would split into two empty paragraphs.
 */
final class WidgetExpander
{
    /** Where a placeholder is text, not markup. */
    private const VERBATIM = ['pre', 'code', 'script', 'style', 'textarea', 'kbd', 'samp'];

    public function __construct(
        private readonly ShortcodeParser $parser,
        private readonly DocumentCache $documents,
        private readonly WidgetAssets $assets,
        private readonly Repository $config,
        private readonly Features $features,
    ) {}

    /**
     * Are widgets on? With the widgets module off nothing loads widgets, so a
     * container would only be an empty gap of its reserved height: every
     * placeholder is removed instead, and no widget document is fetched.
     */
    public function enabled(): bool
    {
        return $this->features->has('widgets');
    }

    /** The §3.1 container, as HTML. */
    public function container(string $id, ?string $lang, int $height): string
    {
        return '<div data-xerads-widget="'.e($id).'"'
            .($lang !== null ? ' data-lang="'.e($lang).'"' : '')
            .' style="min-height:'.$height.'px"></div>';
    }

    /**
     * One widget, for `<x-xerads::widget>` and `@xeradsWidget`. An invalid id
     * renders nothing.
     *
     * @param  array<string, int>  $heights  known heights by widget id
     */
    public function render(string $id, ?string $lang = null, array $heights = []): string
    {
        if (! $this->enabled() || ! ShortcodeParser::validId($id)) {
            return '';
        }

        $this->assets->requireLoader();

        return $this->container($id, $this->language($lang), $this->height($id, $heights));
    }

    /**
     * Every placeholder in an HTML fragment, expanded.
     *
     * @param  array<string, int>  $heights  known heights by widget id
     */
    public function expand(string $html, ?string $defaultLang = null, array $heights = []): string
    {
        // Most bodies have no widget; leave their bytes exactly as they are.
        if (stripos($html, 'xerads_widget') === false) {
            return $html;
        }

        $dom = Dom::fragment($html);
        $this->compile($dom, $defaultLang, $heights);

        return $dom->html();
    }

    /**
     * Expand in place.
     *
     * @param  array<string, int>  $heights  known heights by widget id
     * @return int how many containers were placed
     */
    public function compile(Dom $dom, ?string $defaultLang = null, array $heights = []): int
    {
        $verbatim = implode(' or ', array_map(fn (string $tag) => 'ancestor::'.$tag, self::VERBATIM));
        $nodes = $dom->query(
            './/text()[contains(translate(., "XERADSWIDGET", "xeradswidget"), "xerads_widget")][not('.$verbatim.')]'
        );

        $placed = 0;

        foreach (iterator_to_array($nodes) as $node) {
            if ($node instanceof DOMText) {
                $placed += $this->compileText($node, $defaultLang, $heights);
            }
        }

        if ($placed > 0) {
            $this->assets->requireLoader();
        }

        return $placed;
    }

    /**
     * The height to reserve: known, else from the document, else the fallback.
     *
     * @param  array<string, int>  $heights
     */
    public function height(string $id, array $heights = []): int
    {
        $known = $heights[$id] ?? null;

        if (is_int($known) && $known > 0) {
            return $known;
        }

        return $this->documents->minHeight($id) ?? max(1, (int) $this->config->get('xerads.widgets.fallback_height', 1000));
    }

    /**
     * One text node: its placeholders become containers (or nothing, with
     * widgets off or an invalid id), its other text stays exactly as it was.
     *
     * @param  array<string, int>  $heights
     */
    private function compileText(DOMText $node, ?string $defaultLang, array $heights): int
    {
        $document = $node->ownerDocument;
        $parent = $node->parentNode;

        if ($document === null || $parent === null) {
            return 0;
        }

        $segments = $this->parser->segments($node->data);

        if ($segments === [$node->data]) {
            return 0;
        }

        $onlyPlaceholders = true;

        foreach ($segments as $segment) {
            if (is_string($segment) && trim($segment, " \t\n\r\0\x0B\u{A0}") !== '') {
                $onlyPlaceholders = false;
            }
        }

        // <p>[xerads_widget …]</p>: replace the paragraph, not just its text,
        // and the whitespace around the placeholder with it.
        $unwrap = $onlyPlaceholders && $parent instanceof DOMElement && strtolower($parent->nodeName) === 'p'
            && $parent->parentNode !== null && $this->onlyChild($parent, $node);
        $target = $unwrap ? $parent : $node;
        $fragment = $document->createDocumentFragment();
        $placed = 0;

        foreach ($segments as $segment) {
            if (is_string($segment)) {
                if (! $unwrap) {
                    $fragment->appendChild($document->createTextNode($segment));
                }

                continue;
            }

            if ($segment->id === null || ! $this->enabled()) {
                continue;
            }

            $element = $document->createElement('div');
            $element->setAttribute('data-xerads-widget', $segment->id);

            $lang = $segment->lang ?? $this->language($defaultLang);

            if ($lang !== null) {
                $element->setAttribute('data-lang', $lang);
            }

            $element->setAttribute('style', 'min-height:'.$this->height($segment->id, $heights).'px');
            $fragment->appendChild($element);
            $placed++;
        }

        $container = $target->parentNode;

        if ($container !== null) {
            $fragment->hasChildNodes()
                ? $container->replaceChild($fragment, $target)
                : $container->removeChild($target);
        }

        return $placed;
    }

    private function onlyChild(DOMElement $parent, DOMNode $child): bool
    {
        foreach ($parent->childNodes as $sibling) {
            if ($sibling === $child) {
                continue;
            }

            if (! $sibling instanceof DOMText || trim($sibling->data, " \t\n\r\0\x0B\u{A0}") !== '') {
                return false;
            }
        }

        return true;
    }

    /** The given language when valid, else the app's locale when it is a language code. */
    private function language(?string $lang): ?string
    {
        if (ShortcodeParser::validLang($lang)) {
            return $lang;
        }

        $locale = str_replace('_', '-', (string) $this->config->get('app.locale', ''));

        return ShortcodeParser::validLang($locale) ? $locale : null;
    }
}
