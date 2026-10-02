<?php

namespace XerAds\Laravel\Content\Pipeline;

use DOMElement;
use XerAds\Laravel\Content\Contracts\PipelineStep;
use XerAds\Laravel\Content\HeadingIds;
use XerAds\Laravel\Support\Html\Dom;

/**
 * Ids on every `<h2>` and `<h3>`, by the contract's rule (HeadingIds).
 *
 * XerAds sends them already, but the dashboard's editor drops ids when an
 * article is edited, so the site assigns them again by the same rule: a link
 * to a section keeps working whichever side named it. Ids of other elements
 * count as taken, so a heading never collides with an anchor already there.
 */
final class AssignHeadingIds implements PipelineStep
{
    public function process(ContentDocument $document): void
    {
        if (preg_match('/<h[23]\b/i', $document->html()) !== 1) {
            return;
        }

        $dom = $document->dom();
        $reserved = [];

        foreach ($dom->query('.//*[@id][not(self::h2 or self::h3)]') as $element) {
            if ($element instanceof DOMElement) {
                $reserved[] = $element->getAttribute('id');
            }
        }

        $ids = new HeadingIds($reserved);

        foreach ($dom->query('.//h2 | .//h3') as $heading) {
            if ($heading instanceof DOMElement) {
                $heading->setAttribute('id', $ids->assign(
                    $heading->hasAttribute('id') ? $heading->getAttribute('id') : null,
                    Dom::text($heading),
                ));
            }
        }
    }
}
