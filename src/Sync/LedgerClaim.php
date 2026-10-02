<?php

namespace XerAds\Laravel\Sync;

/**
 * What the ledger said about a delivery id.
 *
 * - claimed:     first time seen (or a failed attempt being retried); process
 *                it, then report the outcome with this claim.
 * - replay:      already processed successfully; answer the stored response.
 * - in progress: another request holds it right now; tell the sender to retry.
 *
 * A claim carries a random token. Only the holder of the current token can
 * record the outcome, so a request whose lease ran out and was taken over can
 * never overwrite the result of the request that took it.
 */
final class LedgerClaim
{
    public const CLAIMED = 'claimed';

    public const REPLAY = 'replay';

    public const IN_PROGRESS = 'in_progress';

    /** @param  array<mixed>|null  $response */
    private function __construct(
        public readonly string $outcome,
        public readonly string $deliveryId,
        public readonly ?string $token = null,
        public readonly ?int $responseCode = null,
        public readonly ?array $response = null,
    ) {}

    public static function claimed(string $deliveryId, string $token): self
    {
        return new self(self::CLAIMED, $deliveryId, $token);
    }

    /** @param  array<mixed>  $response */
    public static function replay(string $deliveryId, int $responseCode, array $response): self
    {
        return new self(self::REPLAY, $deliveryId, null, $responseCode, $response);
    }

    public static function inProgress(string $deliveryId): self
    {
        return new self(self::IN_PROGRESS, $deliveryId);
    }

    public function isClaimed(): bool
    {
        return $this->outcome === self::CLAIMED;
    }

    public function isReplay(): bool
    {
        return $this->outcome === self::REPLAY;
    }

    public function isInProgress(): bool
    {
        return $this->outcome === self::IN_PROGRESS;
    }
}
