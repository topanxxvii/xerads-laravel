<?php

namespace XerAds\Laravel\Content\Receivers;

use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;
use XerAds\Laravel\Content\ConfigurationReport;
use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Contracts\ValidatesConfiguration;
use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\ArticleReference;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;
use XerAds\Laravel\Content\Exceptions\ReceiverMisconfigured;

/**
 * Paired deliveries for a site that wrote its own `ArticleReceiver` for the
 * original endpoint.
 *
 * That receiver keeps working after pairing: each upsert is handed to it in
 * the shape it was written for, with the processed body (sanitised, widgets
 * compiled or kept as placeholders per `content_format`) and the id it
 * returned last time. Unpublishing and deleting have no counterpart in that
 * interface, so they are refused with a message saying how to support them.
 */
final class LegacyReceiverAdapter implements ContentReceiver
{
    public function __construct(private readonly ArticleReceiver $legacy) {}

    public function receive(ArticlePayload $article, DeliveryContext $context): Receipt
    {
        $body = $context->content?->forFormat((string) config('xerads.content.mapped.content_format', 'html')) ?? $article->html;

        $result = $this->legacy->receive(new IncomingArticle(
            title: $article->title,
            seoTitle: $article->seoTitle(),
            content: $body,
            slug: $article->slug,
            metaDescription: $article->seoDescription(),
            keywords: $article->keywords(),
            imageUrl: $article->imageUrl(),
            status: $article->status,
            remoteId: $context->previous->remote_id ?? $article->remoteId,
            excerpt: $context->content !== null ? $context->content->excerpt : $article->excerpt,
        ));

        $state = $article->isPublished() ? Receipt::PUBLISHED : Receipt::DRAFT;
        $url = $result['url'] ?? null;

        return new Receipt(
            id: $result['id'] ?? null,
            state: $state,
            url: $state === Receipt::PUBLISHED && is_string($url) && $url !== '' ? $url : null,
            created: $context->previous === null && $article->remoteId === null,
        );
    }

    public function unpublish(ArticleReference $article, DeliveryContext $context): Receipt
    {
        throw $this->cannotRemove();
    }

    public function delete(ArticleReference $article, DeliveryContext $context): Receipt
    {
        throw $this->cannotRemove();
    }

    public function validateConfiguration(): ConfigurationReport
    {
        return $this->legacy instanceof ValidatesConfiguration
            ? $this->legacy->validateConfiguration()
            : new ConfigurationReport;
    }

    private function cannotRemove(): ReceiverMisconfigured
    {
        return new ReceiverMisconfigured(
            'This site\'s own ArticleReceiver ('.$this->legacy::class.') cannot take articles offline. Implement '
            .ContentReceiver::class.' to handle unpublishing and deleting.'
        );
    }
}
