<?php

namespace XerAds\Laravel\Support\Signature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * The original custom-endpoint signature, exactly as XerAds still sends it.
 *
 *     X-XerAds-Timestamp: 1790000000
 *     X-XerAds-Signature: hash_hmac('sha256', timestamp . '.' . raw_body, secret)
 *
 * Bare lowercase hex with no scheme label, which is how it is told apart from
 * `v2=…`. Frozen: XerAds pins these bytes in its own test suite, and sites
 * with hand-written receivers depend on them, so nothing here may change what
 * is accepted.
 *
 * ── Why a bearer token is not enough on its own ─────────────────────────────
 * A token proves the caller knows a secret. It says nothing about the body,
 * and anything that logged one request can send it again. The signature
 * covers the exact bytes, so an altered payload fails; the timestamp bounds
 * how long a captured request stays useful.
 */
final class V1Verifier
{
    public function __construct(private readonly Repository $config) {}

    public function verifyRequest(Request $request, #[SensitiveParameter] string $secret): VerificationResult
    {
        /*
         * `getContent()` — the RAW body, not the parsed array re-encoded.
         *
         * XerAds signs the exact string it transmits. Re-encoding the parsed
         * payload here would produce a different string for any article
         * carrying a URL or a non-ASCII character, which is all of them, and
         * every verification would fail for a reason nobody could see.
         */
        return $this->verify(
            secret: $secret,
            timestamp: (string) $request->header('X-XerAds-Timestamp', ''),
            signature: (string) $request->header('X-XerAds-Signature', ''),
            rawBody: $request->getContent(),
        );
    }

    public function verify(
        #[SensitiveParameter] string $secret,
        string $timestamp,
        string $signature,
        string $rawBody,
    ): VerificationResult {
        if ($secret === '') {
            /*
             * Refuse rather than wave it through. An unset secret is a
             * half-finished install, and the failure a permissive default
             * produces is an open endpoint that accepts anything.
             */
            return VerificationResult::failed(
                VerificationError::NotConfigured,
                'XERADS_CMS_SECRET is not set. Set it before enabling this route.',
            );
        }

        if ($timestamp === '' || $signature === '') {
            return VerificationResult::failed(VerificationError::SignatureInvalid, 'Missing XerAds signature headers.');
        }

        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return VerificationResult::failed(
                VerificationError::SignatureInvalid,
                'X-XerAds-Timestamp must be a Unix timestamp in seconds.',
            );
        }

        $tolerance = (int) $this->config->get('xerads.legacy.timestamp_tolerance', 300);

        if (abs(Carbon::now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            // Bounds a replay. Generous enough for clock drift between two
            // servers, short enough that a captured request goes stale.
            return VerificationResult::failed(
                VerificationError::TimestampSkew,
                'XerAds signature timestamp is outside the accepted window.',
            );
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            return VerificationResult::failed(VerificationError::SignatureInvalid, 'XerAds signature does not match.');
        }

        return VerificationResult::passed();
    }
}
