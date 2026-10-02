<?php

namespace XerAds\Laravel\Seo\Redirects\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use XerAds\Laravel\Seo\Redirects\RedirectMatcher;

/**
 * Applies the site's redirects (`xerads_redirects`) to requests the
 * application itself answered 404, so a redirect can never shadow a page
 * that exists. GET and HEAD only: a form posted to an old address must not
 * be turned into a GET.
 *
 * Global, pushed through the HTTP kernel; `xerads.redirects.enabled` and
 * `xerads.middleware.redirects` turn it off.
 */
final class HandleRedirects
{
    public function __construct(private readonly RedirectMatcher $matcher) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() !== 404) {
            return $response;
        }

        return $this->matcher->answer($request) ?? $response;
    }
}
