<?php

namespace XerAds\Laravel\Seo\NotFound\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use XerAds\Laravel\Seo\NotFound\NotFoundRecorder;

/**
 * Counts GET and HEAD requests that end in 404, after the response has been
 * sent (terminable middleware), so counting never slows a page down. A 404
 * a redirect turned into a 301 is not counted: the final response is.
 *
 * Global, pushed through the HTTP kernel; `xerads.monitor_404.enabled`,
 * `monitor_404.enabled` in the settings and `xerads.middleware.not_found`
 * turn it off.
 */
final class RecordNotFound
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() !== 404 || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }

        try {
            app(NotFoundRecorder::class)->record($request);
        } catch (Throwable) {
            // A count is informational; the response is long gone.
        }
    }
}
