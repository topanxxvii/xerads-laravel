<?php

namespace XerAds\Laravel\Sync\Handlers;

use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;
use XerAds\Laravel\Content\Events\ArticlePublished;
use XerAds\Laravel\Content\Events\ArticleReceived;
use XerAds\Laravel\Content\Events\ArticleUnpublished;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Content\Pipeline\ContentPipeline;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Sync\Announcer;
use XerAds\Laravel\Sync\ArticleLock;
use XerAds\Laravel\Sync\ArticleReplies;
use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\WebhookReply;

/**
 * `article.upsert`: store an article, or update the copy this site has.
 *
 * In order:
 * 1. Lock the article, so two deliveries of it never interleave.
 * 2. Drop the event if this site already applied a later one: deliveries can
 *    arrive out of order (a retry overtaken by the next edit), and the
 *    per-site `sequence` says which is newer regardless of clocks. A retry
 *    of the delivery that was applied last is answered as a duplicate.
 * 3. Same revision as stored: only the status changes (publish a draft, or
 *    take it back), and the stored body stays exactly as it is.
 * 4. Process the HTML, then store it and the content map entry in one
 *    transaction, so neither exists without the other.
 * 5. Only then tell listeners, so none acts on a row that was rolled back;
 *    a listener that fails is reported, and the delivery still succeeds.
 */
final class ArticleUpsertHandler implements EventHandler
{
    public function __construct(
        private readonly ContentReceiver $receiver,
        private readonly ContentPipeline $pipeline,
        private readonly ArticleLock $lock,
        private readonly ArticleReplies $replies,
        private readonly Tables $tables,
        private readonly Announcer $announcer,
    ) {}

    public function handle(Envelope $envelope): WebhookReply
    {
        $article = ArticlePayload::fromArray($envelope->data['article'] ?? null);

        return $this->lock->for($article->xeradsId, function () use ($envelope, $article): WebhookReply {
            $previous = ContentMapEntry::forArticle($article->xeradsId);
            $early = $this->replies->precheck($previous, $envelope);

            if ($early !== null) {
                return $early;
            }

            // Read before the entry is updated below.
            $wasPublished = $previous?->isPublished() === true;

            $context = new DeliveryContext(
                deliveryId: $envelope->deliveryId,
                event: $envelope->event,
                sequence: $envelope->sequence,
                previous: $previous,
                // Always processed, even for a status-only change: the row
                // may have been deleted on the site and need the full body.
                content: $this->pipeline->process($article),
                statusOnly: $previous !== null && $article->revision !== null && $previous->revision === $article->revision,
            );

            /** @var Receipt $receipt */
            $receipt = $this->tables->connection()->transaction(function () use ($article, $context, $envelope, $previous): Receipt {
                $receipt = $this->receiver->receive($article, $context);

                $this->replies->record($article->xeradsId, $receipt, $envelope, $article->revision, $previous);

                return $receipt;
            });

            $this->announce($article, $receipt, $wasPublished);

            return $this->replies->reply($receipt, $envelope->sequence, $article->revision, $receipt->created ? 201 : 200);
        });
    }

    private function announce(ArticlePayload $article, Receipt $receipt, bool $wasPublished): void
    {
        $this->announcer->announce(...array_filter([
            new ArticleReceived($article, $receipt),
            $receipt->isPublished() && ! $wasPublished ? new ArticlePublished($article, $receipt) : null,
            ! $receipt->isPublished() && $wasPublished ? new ArticleUnpublished($article->xeradsId, $receipt) : null,
        ]));
    }
}
