<?php

namespace XerAds\Laravel\Content\Pipeline;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use XerAds\Laravel\Content\Contracts\PipelineStep;
use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\ProcessedContent;

/**
 * Runs article HTML through the configured steps (`xerads.content.pipeline`).
 *
 * A list in config rather than a fixed sequence, so a site can add a step of
 * its own (rewrite internal links, add a class) without replacing the
 * receiver. No step talks to XerAds; all of it is pure HTML work, safe to
 * run again on a retry.
 */
final class ContentPipeline
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    public function process(ArticlePayload $article): ProcessedContent
    {
        $document = ContentDocument::forArticle($article);

        $this->run($document);

        return $document->toProcessedContent();
    }

    public function run(ContentDocument $document): void
    {
        foreach ($this->steps() as $step) {
            $step->process($document);
        }
    }

    /** @return list<PipelineStep> */
    public function steps(): array
    {
        $steps = [];

        foreach ((array) $this->config->get('xerads.content.pipeline', []) as $class) {
            $step = is_string($class) ? $this->container->make($class) : null;

            if (! $step instanceof PipelineStep) {
                throw new InvalidArgumentException('Every entry of xerads.content.pipeline must be a class implementing '.PipelineStep::class.'.');
            }

            $steps[] = $step;
        }

        return $steps;
    }
}
