<?php

namespace XerAds\Laravel\Support\Signature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\InvalidSiteKey;

/**
 * Prove a contract 2 delivery came from XerAds and was not edited on the way.
 *
 * Checks run cheapest and least revealing first: is this site paired, is the
 * header well formed, is the timestamp fresh, is it addressed to this site,
 * is the key one this site holds, and only then the HMAC. Every comparison of
 * secret material is constant-time.
 *
 * The signature covers the raw body bytes. Re-encoding the parsed payload
 * would produce a different string for any article carrying a URL or a
 * non-ASCII character, which is all of them.
 */
final class V2Verifier
{
    private const SIGNATURE_PATTERN = '/^v2=[0-9a-f]{64}$/';

    public function __construct(
        private readonly CredentialsResolver $credentials,
        private readonly V2Signer $signer,
        private readonly Repository $config,
    ) {}

    /** Verify a push delivery from its `X-XerAds-*` headers and raw body. */
    public function verifyPushRequest(Request $request): VerificationResult
    {
        return $this->verifyPush(
            signature: (string) $request->header('X-XerAds-Signature', ''),
            timestamp: (string) $request->header('X-XerAds-Timestamp', ''),
            deliveryId: (string) $request->header('X-XerAds-Delivery', ''),
            rawBody: $request->getContent(),
            keyId: $request->headers->has('X-XerAds-Key-Id') ? (string) $request->header('X-XerAds-Key-Id') : null,
            siteId: $request->headers->has('X-XerAds-Site') ? (string) $request->header('X-XerAds-Site') : null,
        );
    }

    /**
     * @param  string|null  $keyId  the key the sender says it used; without it
     *                              the current and then the previous key are tried
     * @param  string|null  $siteId  the site the sender addressed; checked when present
     */
    public function verifyPush(
        string $signature,
        int|string $timestamp,
        string $deliveryId,
        string $rawBody,
        ?string $keyId = null,
        ?string $siteId = null,
    ): VerificationResult {
        try {
            $current = $this->credentials->current();
        } catch (InvalidSiteKey $exception) {
            return VerificationResult::failed(VerificationError::NotConfigured, $exception->getMessage());
        }

        if ($current === null) {
            return VerificationResult::failed(
                VerificationError::NotConfigured,
                'This site is not paired with XerAds yet, so it holds no key to verify the delivery with.',
            );
        }

        if (preg_match(self::SIGNATURE_PATTERN, $signature) !== 1) {
            return VerificationResult::failed(
                VerificationError::SignatureInvalid,
                'X-XerAds-Signature must be "v2=" followed by 64 lowercase hex characters.',
            );
        }

        if ($deliveryId === '') {
            return VerificationResult::failed(VerificationError::SignatureInvalid, 'X-XerAds-Delivery is missing.');
        }

        $skew = $this->timestampProblem($timestamp);

        if ($skew !== null) {
            return $skew;
        }

        if ($siteId !== null && ! hash_equals($current->siteId, $siteId)) {
            return VerificationResult::failed(
                VerificationError::SiteMismatch,
                'This delivery is addressed to a different site than the one this installation is paired with.',
            );
        }

        $candidates = $this->candidates($keyId);

        if ($candidates === []) {
            return VerificationResult::failed(
                VerificationError::KeyUnknown,
                'This site holds no key with that key id. It may have been rotated out; pair the site again if this persists.',
            );
        }

        foreach ($candidates as $credentials) {
            $expected = $this->signer->push($credentials->secret(), (string) $timestamp, $deliveryId, $rawBody);

            if (hash_equals($expected, $signature)) {
                return VerificationResult::passed($credentials);
            }
        }

        return VerificationResult::failed(
            VerificationError::SignatureInvalid,
            'The signature does not match the body. The request was altered, or signed with a different key.',
        );
    }

    /** @return list<Credentials> */
    private function candidates(?string $keyId): array
    {
        if ($keyId !== null && $keyId !== '') {
            $credentials = $this->credentials->byKeyId($keyId);

            return $credentials !== null ? [$credentials] : [];
        }

        return array_values(array_filter([$this->credentials->current(), $this->credentials->previous()]));
    }

    private function timestampProblem(int|string $timestamp): ?VerificationResult
    {
        $timestamp = (string) $timestamp;

        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return VerificationResult::failed(
                VerificationError::SignatureInvalid,
                'X-XerAds-Timestamp must be a Unix timestamp in seconds.',
            );
        }

        $tolerance = (int) $this->config->get('xerads.webhook.timestamp_tolerance', 300);
        $now = Carbon::now()->getTimestamp();

        if (abs($now - (int) $timestamp) > $tolerance) {
            return VerificationResult::failed(
                VerificationError::TimestampSkew,
                'The request timestamp is more than '.$tolerance.' seconds from this server\'s clock ('.$now.'). Check the server time.',
            );
        }

        return null;
    }
}
