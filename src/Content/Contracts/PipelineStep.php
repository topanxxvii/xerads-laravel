<?php

namespace XerAds\Laravel\Content\Contracts;

use XerAds\Laravel\Content\Pipeline\ContentDocument;

/**
 * One step of the content pipeline (`xerads.content.pipeline`).
 *
 * Steps run in the configured order on one shared document; each changes
 * the HTML or records something about it (table of contents, word count).
 * A step resolved from the container may take any dependency it needs.
 */
interface PipelineStep
{
    public function process(ContentDocument $document): void;
}
