<?php

namespace XerAds\Laravel\Content\Pipeline;

use DOMElement;
use XerAds\Laravel\Content\Contracts\PipelineStep;

/**
 * Every `<h1>` in the body becomes an `<h2>`.
 *
 * The page's template prints the article's one H1 (`headline ?? title`).
 * XerAds already moves the opening H1 out of the body, but an editor can add
 * one, and two H1s on a page is a ranking problem a site owner never sees.
 */
final class DemoteHeadings implements PipelineStep
{
    public function process(ContentDocument $document): void
    {
        if (stripos($document->html(), '<h1') === false) {
            return;
        }

        $dom = $document->dom();

        foreach (iterator_to_array($dom->query('.//h1')) as $heading) {
            if ($heading instanceof DOMElement) {
                $dom->rename($heading, 'h2');
            }
        }
    }
}
