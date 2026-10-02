<?php

namespace XerAds\Laravel\Support\Signature;

use SensitiveParameter;

/**
 * The contract 2 signing formulas, in one place.
 *
 * Push (XerAds → site) signs `v2.{ts}.{delivery_id}.{raw_body}`: the delivery
 * id is inside the signature, so a captured delivery cannot be replayed under
 * a fresh id to slip past the duplicate check.
 *
 * Pull (site → XerAds) signs `v2.{ts}.{nonce}.{METHOD}.{request_uri}.{sha256(body)}`:
 * a GET has no body to bind, so the method and the exact path and query are
 * what stop a signed request for one endpoint being replayed against another.
 *
 * Both start with the scheme label, so a v2 signature can never be mistaken
 * for a valid v1 one over the same bytes, and the header value carries it
 * (`v2=…`), which is how a receiver tells the schemes apart.
 */
final class V2Signer
{
    public const SCHEME = 'v2';

    public function push(
        #[SensitiveParameter] string $secret,
        int|string $timestamp,
        string $deliveryId,
        string $rawBody,
    ): string {
        return self::SCHEME.'='.hash_hmac(
            'sha256',
            self::SCHEME.'.'.$timestamp.'.'.$deliveryId.'.'.$rawBody,
            $secret,
        );
    }

    /**
     * @param  string  $requestUri  the path plus query string exactly as sent,
     *                              percent-encoding included
     */
    public function pull(
        #[SensitiveParameter] string $secret,
        int|string $timestamp,
        string $nonce,
        string $method,
        string $requestUri,
        string $rawBody,
    ): string {
        return self::SCHEME.'='.hash_hmac(
            'sha256',
            self::SCHEME.'.'.$timestamp.'.'.$nonce.'.'.strtoupper($method).'.'.$requestUri.'.'.hash('sha256', $rawBody),
            $secret,
        );
    }
}
