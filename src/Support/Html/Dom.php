<?php

namespace XerAds\Laravel\Support\Html;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Masterminds\HTML5;

/**
 * An article body as a DOM, and back to HTML.
 *
 * An HTML5 parser rather than `DOMDocument::loadHTML()`, because libxml's
 * parser is HTML 4: it mangles `<figure>`, `<section>` and every other HTML5
 * element, guesses Latin-1 for a fragment without a meta tag (turning every
 * "é" into "Ã©"), and reports modern markup as errors. PHP 8.4's own HTML5
 * parser would do, but this package still supports PHP 8.2.
 *
 * The fragment is parsed into a wrapper element so the article's top-level
 * nodes have a parent to be moved, wrapped or replaced under, and only that
 * wrapper's children are serialised back. UTF-8 text stays UTF-8; only `&`,
 * `<`, `>`, a non-breaking space and `"` inside attributes are written as
 * entities.
 */
final class Dom
{
    private ?DOMXPath $xpath = null;

    private function __construct(
        private readonly HTML5 $html5,
        private readonly DOMDocument $document,
        private readonly DOMElement $root,
    ) {}

    public static function fragment(string $html): self
    {
        /*
         * No HTML namespace on elements, so XPath reads `//h2` instead of
         * needing a registered prefix on every query.
         */
        $html5 = new HTML5(['disable_html_ns' => true, 'encoding' => 'UTF-8']);

        $fragment = $html5->loadHTMLFragment($html);
        $document = $fragment->ownerDocument;

        if (! $document instanceof DOMDocument) {
            throw new \RuntimeException('The HTML parser returned a fragment without a document.');
        }

        $root = $document->createElement('div');
        $document->appendChild($root);

        if ($fragment->hasChildNodes()) {
            $root->appendChild($fragment);
        }

        return new self($html5, $document, $root);
    }

    public function document(): DOMDocument
    {
        return $this->document;
    }

    /** The wrapper whose children are the fragment's top-level nodes. */
    public function root(): DOMElement
    {
        return $this->root;
    }

    /** @return DOMNodeList<\DOMNode> */
    public function query(string $expression): DOMNodeList
    {
        $this->xpath ??= new DOMXPath($this->document);

        $nodes = $this->xpath->query($expression, $this->root);

        if ($nodes === false) {
            throw new \InvalidArgumentException('Invalid XPath expression: '.$expression);
        }

        return $nodes;
    }

    /** The fragment serialised back to HTML, without the wrapper. */
    public function html(): string
    {
        return $this->html5->saveHTML($this->root->childNodes);
    }

    /**
     * Give an element another tag name, keeping its attributes and children.
     * DOM has no rename, so this builds the new element and swaps it in.
     */
    public function rename(DOMElement $element, string $tag): DOMElement
    {
        $renamed = $this->document->createElement($tag);

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $renamed->setAttribute($attribute->name, $attribute->value);
        }

        while ($element->firstChild !== null) {
            $renamed->appendChild($element->firstChild);
        }

        $element->parentNode?->replaceChild($renamed, $element);

        return $renamed;
    }

    /** An element's text as a reader sees it: whitespace runs collapsed. */
    public static function text(\DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
    }
}
