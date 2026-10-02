<?php

namespace XerAds\Laravel\Content\Data;

use XerAds\Laravel\Content\Models\ContentMapEntry;

/**
 * Everything a receiver knows about a delivery besides the article itself.
 */
final class DeliveryContext
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $event,
        public readonly int $sequence,
        /** Where this article landed last time, if it has before. */
        public readonly ?ContentMapEntry $previous = null,
        /** The pipeline's output; null for a status-only change or a removal. */
        public readonly ?ProcessedContent $content = null,
        /**
         * The revision is the one already stored: nothing a reader sees
         * changed, so only the status (publish or unpublish) is applied and
         * the stored body is left exactly as it is.
         */
        public readonly bool $statusOnly = false,
    ) {}
}
