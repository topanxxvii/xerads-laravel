<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Http\JsonResponse;

/**
 * What the site answers a delivery with: a status and a JSON body.
 *
 * A value rather than a response object, so the ledger can store exactly
 * what was answered and give a repeat of the delivery the same answer.
 * Refusals use the contract's shape, `{ok: false, error, message}`, which
 * XerAds reads to decide between retrying, failing and alerting the owner.
 */
final class WebhookReply
{
    /** @param  array<string, mixed>  $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {}

    /** @param  array<string, mixed>  $body */
    public static function ok(array $body = [], int $status = 200): self
    {
        return new self($status, ['ok' => true] + $body);
    }

    /** @param  array<string, mixed>  $extra */
    public static function error(int $status, string $error, string $message, array $extra = []): self
    {
        return new self($status, ['ok' => false, 'error' => $error, 'message' => $message] + $extra);
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse($this->body, $this->status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
