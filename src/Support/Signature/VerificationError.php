<?php

namespace XerAds\Laravel\Support\Signature;

/**
 * Why a signed request was refused, as the error code XerAds reads.
 *
 * XerAds reacts to each one differently: a bad signature stops retries and
 * flags the site, a clock skew is retried with a hint about the server's
 * clock, an unconfigured site is reported as not set up yet. So the codes are
 * part of the contract and never reworded.
 */
enum VerificationError: string
{
    case SignatureInvalid = 'SIGNATURE_INVALID';
    case TimestampSkew = 'TIMESTAMP_SKEW';
    case KeyUnknown = 'KEY_UNKNOWN';
    case SiteMismatch = 'SITE_MISMATCH';
    case NotConfigured = 'NOT_CONFIGURED';

    /** 503 for a site that is not set up yet, so it reads as an outage, not as an attack. */
    public function status(): int
    {
        return $this === self::NotConfigured ? 503 : 401;
    }
}
