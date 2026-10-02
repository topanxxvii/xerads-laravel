<?php

namespace XerAds\Laravel\Sync;

use XerAds\Laravel\Support\Credentials;

/** What a pairing produced: the new key, and the one it replaced, if any. */
final class PairingResult
{
    /** @param  array<string, mixed>  $reply  XerAds' reply, secret included: never print it */
    public function __construct(
        public readonly Credentials $credentials,
        public readonly ?Credentials $previous,
        public readonly array $reply,
    ) {}
}
