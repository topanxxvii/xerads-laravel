<?php

namespace XerAds\Laravel\Sync\Client\Exceptions;

use RuntimeException;

/**
 * XerAds' site API refused a request, or could not be reached.
 *
 * Carries the API's own error code (`SITE_KEY_INVALID`, `PAIRING_CODE_INVALID`,
 * …) and message when it sent them, so a command can show the owner exactly
 * what XerAds said. Never carries the request: its headers hold a signature.
 */
class XeradsApiException extends RuntimeException
{
    /** @param  array<string, mixed>  $payload  the API's JSON reply, if any */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly array $payload = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
