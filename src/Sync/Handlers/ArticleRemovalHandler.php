<?php

namespace XerAds\Laravel\Sync\Handlers;

use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Data\ArticleReference;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;
use XerAds\Laravel\Content\Events\ArticleDeleted;
use XerAds\Laravel\Content\Events\ArticleUnpublished;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Seo\ContentChanges;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Sync\Announcer;
use XerAds\Laravel\Sync\ArticleLock;
use XerAds\Laravel\Sync\ArticleReplies;
use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\WebhookReply;

/**
 * Taking an article off the site: `article.unpublish` and `article.delete`.
 *
 * Locked and ordered like an upsert. An article this site never stored (or
 * no longer has) answers 200 with state `unknown`: there is nothing to take
 * down, and a 404 would only make XerAds retry for a day. Its sequence is
 * still recorded, as a tombstone, so an older upsert still on its way cannot
 * bring back an article XerAds has since taken down.
 */
abstract class ArticleRemovalHandler implements EventHandler
{
    public function __construct(
        protected readonly ContentReceiver $receiver,
        private readonly ArticleLock $lock,
        private readonly ArticleReplies $replies,
        private readonly Tables $tables,
        private readonly Announcer $announcer,
        private readonly ContentChanges $changes,
    ) {}

    abstract protected function remove(ArticleReference $article, DeliveryContext $context): Receipt;

    /** The state a tombstone records when there was nothing to remove. */
    abstract protected function tombstoneState(): string;

    public function handle(Envelope $envelope): WebhookReply
    {
        $article = ArticleReference::fromArray($envelope->data['article'] ?? null);

        return $this->lock->for($article->xeradsId, function () use ($envelope, $article): WebhookReply {
            $previous = ContentMapEntry::forArticle($article->xeradsId);
            $early = $this->replies->precheck($previous, $envelope);

            if ($early !== null) {
                return $early;
            }

            // Read before the entry is updated below.
            $wasPublished = $previous?->isPublished() === true;
            $revision = $previous?->revision;
            $context = new DeliveryContext($envelope->deliveryId, $envelope->event, $envelope->sequence, $previous);

            /** @var Receipt $receipt */
            $receipt = $this->tables->connection()->transaction(function () use ($article, $context, $envelope, $previous): Receipt {
                $receipt = $previous === null && $article->remoteId === null
                    ? Receipt::unknown()
                    : $this->remove($article, $context);

                $this->replies->record(
                    $article->xeradsId,
                    $receipt->state === Receipt::UNKNOWN ? new Receipt(null, $this->tombstoneState()) : $receipt,
                    $envelope,
                    null,
                    $previous,
                );

                return $receipt;
            });

            if ($wasPublished) {
                $this->changes->record($previous->last_url);
            }

            if ($receipt->state === Receipt::DELETED) {
                $this->announcer->announce(new ArticleDeleted($article->xeradsId, $receipt));
            } elseif ($receipt->state === Receipt::UNPUBLISHED && $wasPublished) {
                $this->announcer->announce(new ArticleUnpublished($article->xeradsId, $receipt));
            }

            return $this->replies->reply($receipt, $envelope->sequence, $revision);
        });
    }
}
