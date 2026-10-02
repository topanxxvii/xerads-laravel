<?php

namespace XerAds\Laravel\Content\Pipeline;

use DOMElement;
use XerAds\Laravel\Content\Contracts\PipelineStep;
use XerAds\Laravel\Support\Html\Dom;

/**
 * The table of contents: every `<h2>` and `<h3>` with its id, in document
 * order, the same shape XerAds sends (`[{id, text, level}]`).
 *
 * Built from the stored body rather than taken from the delivery, so it
 * matches the headings the page actually has after sanitising.
 */
final class BuildToc implements PipelineStep
{
    public function process(ContentDocument $document): void
    {
        if (preg_match('/<h[23]\b/i', $document->html()) !== 1) {
            $document->toc = [];

            return;
        }

        $toc = [];

        foreach ($document->dom()->query('.//h2[@id] | .//h3[@id]') as $heading) {
            if ($heading instanceof DOMElement) {
                $toc[] = [
                    'id' => $heading->getAttribute('id'),
                    'text' => Dom::text($heading),
                    'level' => (int) substr(strtolower($heading->nodeName), 1),
                ];
            }
        }

        $document->toc = $toc;
    }
}
