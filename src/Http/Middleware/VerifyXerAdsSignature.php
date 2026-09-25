<?php

namespace XerAds\CmsBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prove XerAds sent this request, and that nobody edited it on the way.
 *
 * ── Why a bearer token is not enough on its own ─────────────────────────────
 * A token proves the caller knows a secret. It says nothing about the body, and
 * anything that logged one request can send it again. The signature covers the
 * exact bytes, so an altered payload fails; the timestamp bounds how long a
 * captured request stays useful.
 */
class VerifyXerAdsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('xerads-cms.secret');

        if ($secret === '') {
            /*
             * Refuse rather than wave it through. An unset secret is a
             * half-finished install, and the failure a permissive default
             * produces is an open endpoint that accepts anything — discovered,
             * if ever, long after someone found it.
             */
            abort(500, 'XERADS_CMS_SECRET is not set. Set it before enabling this route.');
        }

        $timestamp = (string) $request->header('X-XerAds-Timestamp', '');
        $signature = (string) $request->header('X-XerAds-Signature', '');

        if ($timestamp === '' || $signature === '') {
            abort(401, 'Missing XerAds signature headers.');
        }

        $tolerance = (int) config('xerads-cms.timestamp_tolerance', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            // Bounds a replay. Generous enough for clock drift between two
            // servers, short enough that a captured request goes stale.
            abort(401, 'XerAds signature timestamp is outside the accepted window.');
        }

        /*
         * `getContent()` — the RAW body, not the parsed array re-encoded.
         *
         * XerAds signs the exact string it transmits. Re-encoding the parsed
         * payload here would produce a different string for any article
         * carrying a URL or a non-ASCII character, which is all of them, and
         * every verification would fail for a reason nobody could see.
         */
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'XerAds signature does not match.');
        }

        return $next($request);
    }
}
