<?php

namespace XerAds\Laravel\Content\Pipeline;

use XerAds\Laravel\Content\Contracts\PipelineStep;
use XerAds\Laravel\Support\Html\Dom;
use XerAds\Laravel\Widgets\WidgetExpander;

/**
 * The body with each placeholder replaced by its widget container, for sites
 * that store finished HTML (`content.mapped.content_format = html`).
 *
 * The placeholder body is kept as well: it is what the table of contents and
 * word count were taken from, and what a site storing placeholders gets.
 * Heights come from the delivery's widget list, so compiling an article does
 * not wait on the widget runtime.
 */
final class CompileWidgets implements PipelineStep
{
    public function __construct(private readonly WidgetExpander $expander) {}

    public function process(ContentDocument $document): void
    {
        $html = $document->html();

        if (stripos($html, 'xerads_widget') === false) {
            $document->compiledHtml = $html;

            return;
        }

        $compiled = Dom::fragment($html);
        $this->expander->compile($compiled, $document->language, $document->widgetHeights);

        $document->compiledHtml = $compiled->html();
    }
}
