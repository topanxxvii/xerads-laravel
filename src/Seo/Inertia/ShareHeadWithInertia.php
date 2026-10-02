<?php

namespace XerAds\Laravel\Seo\Inertia;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the `xerads` prop with Inertia for this request (see ShareHead).
 */
final class ShareHeadWithInertia
{
    public function handle(Request $request, Closure $next): Response
    {
        app(ShareHead::class)->register();

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
