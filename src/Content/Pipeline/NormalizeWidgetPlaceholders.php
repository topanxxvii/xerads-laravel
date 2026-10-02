<?php

namespace XerAds\Laravel\Content\Pipeline;

use DOMElement;
use Illuminate\Support\Facades\Log;
use XerAds\Laravel\Content\Contracts\PipelineStep;
use XerAds\Laravel\Widgets\ShortcodeParser;

/**
 * Every way a widget can be placed in an article, turned into the one
 * placeholder this package stores: `[xerads_widget id="…" lang="…"]`.
 *
 * Authors paste whatever embed code the dashboard handed them: a
 * `data-xerads-widget` container, the embed iframe, a block comment, even the
 * Blade component meant for templates. Storing one form means one way to
 * render it, and the placeholder is plain text, so the sanitiser keeps it
 * without allowing iframes or inline styles. Loader script tags are removed;
 * the page includes the loader once, wherever widgets appear.
 *
 * Runtime paths are matched by `/v<digits>/`, not `/v1/`, so a future runtime
 * version is still recognised.
 */
final class NormalizeWidgetPlaceholders implements PipelineStep
{
    /**
     * The block's JSON is short and holds no markup; bounding it keeps a
     * comment with no end from reaching across the rest of the article.
     */
    private const BLOCK_COMMENT = '/<!--\s*wp:xerads\/widget\s+(\{[^<>]{0,2000}?\})\s*\/?-->/';

    private const BLOCK_CLOSING = '/<!--\s*\/wp:xerads\/widget\s*-->/';

    /** The Blade component, pasted as markup: an HTML parser would not know the tag. */
    private const BLADE_COMPONENT = '/<x-xerads::widget\b([^>]*?)\/?>(?:\s*<\/x-xerads::widget>)?/i';

    private const FRAME_PATH = '#/v\d+/frame\.html$#';

    private const LOADER_PATH = '#/v\d+/loader\.js$#';

    public function __construct(private readonly ShortcodeParser $parser) {}

    public function process(ContentDocument $document): void
    {
        $html = $document->html();

        if (preg_match('/xerads|frame\.html|loader\.js/i', $html) !== 1) {
            return;
        }

        $html = $this->rewrite($html, fn () => preg_replace_callback(self::BLOCK_COMMENT, function (array $match): string {
            $data = json_decode($match[1], true);

            return is_array($data) ? $this->placeholder($data['id'] ?? null, $data['lang'] ?? null) : '';
        }, $html));

        $html = $this->rewrite($html, fn () => preg_replace_callback(self::BLADE_COMPONENT, function (array $match): string {
            $attributes = $this->parser->attributes($match[1]);

            return $this->placeholder($attributes['id'] ?? null, $attributes['lang'] ?? null);
        }, $html));

        $document->setHtml($this->rewrite($html, fn () => preg_replace(self::BLOCK_CLOSING, '', $html)));

        $dom = $document->dom();

        foreach (iterator_to_array($dom->query('.//*[@data-xerads-widget]')) as $element) {
            if ($element instanceof DOMElement) {
                $this->replace($element, $this->placeholder(
                    $element->getAttribute('data-xerads-widget'),
                    $element->hasAttribute('data-lang') ? $element->getAttribute('data-lang') : null,
                ));
            }
        }

        foreach (iterator_to_array($dom->query('.//iframe[@src]')) as $element) {
            if (! $element instanceof DOMElement || preg_match(self::FRAME_PATH, (string) parse_url($element->getAttribute('src'), PHP_URL_PATH)) !== 1) {
                continue;
            }

            parse_str((string) parse_url($element->getAttribute('src'), PHP_URL_QUERY), $query);

            $this->replace($element, $this->placeholder($query['w'] ?? null, $query['lang'] ?? null));
        }

        foreach (iterator_to_array($dom->query('.//script[@src]')) as $element) {
            if ($element instanceof DOMElement && preg_match(self::LOADER_PATH, (string) parse_url($element->getAttribute('src'), PHP_URL_PATH)) === 1) {
                $element->parentNode?->removeChild($element);
            }
        }
    }

    /**
     * The rewritten body, or the body unchanged when the pattern engine gave
     * up (it returns null, which as a string is an empty article). A missed
     * placeholder costs one widget; an empty body would be stored and
     * published.
     *
     * @param  callable(): (string|null)  $rewrite
     */
    private function rewrite(string $html, callable $rewrite): string
    {
        $rewritten = $rewrite();

        if (! is_string($rewritten)) {
            Log::warning('XerAds could not look for widget placeholders in an article; it is stored without that step.', [
                'error' => preg_last_error_msg(),
            ]);

            return $html;
        }

        return $rewritten;
    }

    /**
     * The placeholder text, or nothing for an id the runtime could not load:
     * keeping a broken id would only put junk text on the page.
     */
    private function placeholder(mixed $id, mixed $lang): string
    {
        $id = is_string($id) ? trim($id) : null;
        $lang = is_string($lang) ? trim($lang) : null;

        if (! ShortcodeParser::validId($id)) {
            return '';
        }

        return ShortcodeParser::text((string) $id, ShortcodeParser::validLang($lang) ? $lang : null);
    }

    private function replace(DOMElement $element, string $placeholder): void
    {
        $parent = $element->parentNode;
        $document = $element->ownerDocument;

        if ($parent === null || $document === null) {
            return;
        }

        $placeholder === ''
            ? $parent->removeChild($element)
            : $parent->replaceChild($document->createTextNode($placeholder), $element);
    }
}
