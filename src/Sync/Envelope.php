<?php

namespace XerAds\Laravel\Sync;

/**
 * Every contract 2 message, pushed or pulled (sites contract, "Envelope"):
 *
 *     {"contract":2,"event":"article.upsert","delivery_id":"01JB…",
 *      "occurred_at":"…Z","site_id":"site_…","sequence":1842,"data":{…}}
 *
 * Read from the signed body only; the push headers repeat some of it, and the
 * signature middleware checks that they agree.
 */
final class Envelope
{
    public const CONTRACT = 2;

    public const EVENTS = [
        'ping',
        'article.upsert',
        'article.unpublish',
        'article.delete',
        'settings.updated',
        'redirects.updated',
        'site.revoked',
    ];

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly int $contract,
        public readonly string $event,
        public readonly string $deliveryId,
        public readonly string $siteId,
        public readonly int $sequence,
        public readonly array $data,
        public readonly ?string $occurredAt = null,
    ) {}

    /** The envelope in a body, or null when the body is not one. */
    public static function fromBody(string $body): ?self
    {
        if (! str_starts_with(ltrim($body, " \t\n\r"), '{')) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $string = fn (mixed $value): string => is_string($value) ? $value : '';
        $data = $decoded['data'] ?? [];

        return new self(
            contract: is_int($decoded['contract'] ?? null) ? $decoded['contract'] : 0,
            event: $string($decoded['event'] ?? null),
            deliveryId: $string($decoded['delivery_id'] ?? null),
            siteId: $string($decoded['site_id'] ?? null),
            sequence: is_int($decoded['sequence'] ?? null) ? $decoded['sequence'] : 0,
            data: is_array($data) ? $data : [],
            occurredAt: is_string($decoded['occurred_at'] ?? null) ? $decoded['occurred_at'] : null,
        );
    }
}
