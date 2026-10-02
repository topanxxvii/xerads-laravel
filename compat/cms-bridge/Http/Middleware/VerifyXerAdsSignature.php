<?php

namespace XerAds\CmsBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use XerAds\Laravel\Support\Signature\V1Verifier;

/**
 * Prove XerAds sent this request to the legacy endpoint, and that nobody
 * edited it on the way.
 *
 * The scheme itself lives in `V1Verifier`; this only turns its verdict into a
 * response. Refusals are JSON with a stable `error` code, so XerAds can tell a
 * clock problem from a wrong secret.
 *
 * The secret is read on every request rather than when the route was
 * registered: an install whose secret was removed after `route:cache` must
 * refuse, not fall open.
 */
class VerifyXerAdsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('xerads.legacy.secret');

        /*
         * An unset secret is a half-finished install. It answers 503 — the
         * endpoint exists but is not ready — rather than accepting unsigned
         * requests, which is the failure a permissive default produces.
         */
        $result = app(V1Verifier::class)->verifyRequest($request, is_string($secret) ? $secret : '');

        if (! $result->ok) {
            return response()->json($result->toResponseBody(), $result->status());
        }

        return $next($request);
    }
}
