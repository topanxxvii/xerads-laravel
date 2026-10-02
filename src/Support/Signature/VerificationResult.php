<?php

namespace XerAds\Laravel\Support\Signature;

use XerAds\Laravel\Support\Credentials;

/**
 * The outcome of checking a signed request.
 *
 * A value rather than an exception, because a refused request is an ordinary
 * outcome with a response of its own, and the caller decides what that
 * response looks like (JSON for XerAds, a log line for a command).
 */
final class VerificationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?VerificationError $error,
        public readonly string $message,
        public readonly ?Credentials $credentials,
    ) {}

    /** @param  Credentials|null  $credentials  the key that verified, for the v2 scheme */
    public static function passed(?Credentials $credentials = null): self
    {
        return new self(true, null, '', $credentials);
    }

    public static function failed(VerificationError $error, string $message): self
    {
        return new self(false, $error, $message, null);
    }

    public function status(): int
    {
        return $this->error?->status() ?? 200;
    }

    /**
     * The JSON body a refused request answers with.
     *
     * @return array{ok: bool, error: string|null, message: string}
     */
    public function toResponseBody(): array
    {
        return [
            'ok' => $this->ok,
            'error' => $this->error?->value,
            'message' => $this->message,
        ];
    }
}
