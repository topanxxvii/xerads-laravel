<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Content\Data\Receipt;
use XerAds\Laravel\Content\Exceptions\ReceiverMisconfigured;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Support\Version;

/**
 * The content map writes, the ordering check and the replies every article
 * event shares.
 *
 * The reply is shaped the way XerAds reads it: `id` and `url` at the top
 * level, `url` only while the article is published, and
 * the sequence and revision this site now holds, so XerAds can tell which
 * version of the article the site has.
 */
final class ArticleReplies
{
    /** The longest site id XerAds stores, and so the longest this site may answer with. */
    public const MAX_ID_LENGTH = 191;

    public function __construct(private readonly Repository $config) {}

    /**
     * What to answer without applying the event, if anything.
     *
     * - The very delivery that last wrote the entry, arriving again: its
     *   changes are stored, but its reply never reached XerAds (a listener
     *   failed after the commit, or the connection dropped). Answer it as a
     *   duplicate with what the site holds, so XerAds records the id and URL.
     * - An older event than the one applied: dropped as stale. Sequences
     *   count per XerAds site, so an entry from another site (the site was
     *   added again, under a new id) has no ordering to compare with, and an
     *   entry `xerads:simulate` wrote never blocks a real delivery.
     */
    public function precheck(?ContentMapEntry $previous, Envelope $envelope): ?WebhookReply
    {
        if ($previous === null) {
            return null;
        }

        if ($previous->last_delivery_id === $envelope->deliveryId) {
            return $this->current($previous, duplicate: true);
        }

        if ($previous->wasSimulated() || $previous->site_id !== $envelope->siteId) {
            return null;
        }

        return $envelope->sequence <= $previous->sequence ? $this->stale($previous) : null;
    }

    /**
     * Record where the article landed, after the receiver stored it.
     *
     * @throws ReceiverMisconfigured for an id XerAds could not store
     */
    public function record(string $xeradsId, Receipt $receipt, Envelope $envelope, ?string $revision, ?ContentMapEntry $previous): ContentMapEntry
    {
        $id = $receipt->id !== null ? (string) $receipt->id : null;

        if ($id !== null && strlen($id) > self::MAX_ID_LENGTH) {
            // Refused inside the transaction, so a post stored in the same
            // database is rolled back instead of duplicated on every retry.
            throw new ReceiverMisconfigured('The receiver answered with an id longer than '.self::MAX_ID_LENGTH.' characters, which XerAds cannot store. Return a shorter id.');
        }

        $entry = $previous ?? new ContentMapEntry(['xerads_id' => $xeradsId]);
        $model = $receipt->model;

        $entry->forceFill([
            'target' => (string) $this->config->get('xerads.content.mode', 'mapped'),
            'site_id' => $envelope->siteId,
            'model_type' => $model?->getMorphClass() ?? $entry->model_type,
            'model_id' => $model !== null ? (string) $model->getKey() : $entry->model_id,
            'remote_id' => $id ?? $entry->remote_id,
            'sequence' => $envelope->sequence,
            'revision' => $revision ?? $entry->revision,
            'state' => $receipt->state,
            'last_delivery_id' => $envelope->deliveryId,
            'last_url' => $receipt->url,
            'first_published_at' => $entry->first_published_at ?? ($receipt->isPublished() ? Carbon::now() : null),
        ])->save();

        return $entry;
    }

    public function reply(Receipt $receipt, int $sequence, ?string $revision, int $status = 200): WebhookReply
    {
        return WebhookReply::ok([
            'id' => $receipt->id !== null ? (string) $receipt->id : null,
            'url' => $receipt->isPublished() ? $receipt->url : null,
            'preview_url' => $receipt->previewUrl,
            'state' => $receipt->state,
            'sequence' => $sequence,
            'revision' => $revision,
            'created' => $receipt->created,
            'duplicate' => false,
            'plugin' => ['version' => Version::VERSION, 'contract' => Version::CONTRACT],
        ], $status);
    }

    /**
     * An event older than one already applied: nothing is changed, and the
     * reply says what the site holds instead.
     */
    public function stale(ContentMapEntry $entry): WebhookReply
    {
        return WebhookReply::ok(['stale' => true] + $this->current($entry)->body);
    }

    /** What the site holds for the article, in the reply shape. */
    private function current(ContentMapEntry $entry, bool $duplicate = false): WebhookReply
    {
        return WebhookReply::ok([
            'id' => $entry->remote_id,
            'url' => $entry->isPublished() ? $entry->last_url : null,
            'preview_url' => null,
            'state' => $entry->state,
            'sequence' => $entry->sequence,
            'revision' => $entry->revision,
            'created' => false,
            'duplicate' => $duplicate,
            'plugin' => ['version' => Version::VERSION, 'contract' => Version::CONTRACT],
        ]);
    }
}
