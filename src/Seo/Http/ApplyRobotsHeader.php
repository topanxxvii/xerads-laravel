<?php

namespace XerAds\Laravel\Seo\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use XerAds\Laravel\Seo\HeadManager;
use XerAds\Laravel\Seo\RobotsDirectives;

/**
 * Says in an `X-Robots-Tag` header what the page's robots tag says, for the
 * responses search engines read without a `<head>`: images, PDFs, JSON, and
 * whole sites outside production.
 *
 * Only a restricting decision is sent (noindex or nofollow); "index, follow"
 * is what a missing header means already. A header the response already
 * carries is left alone. The decision is the head's own, made for the same
 * request, so the header and the tag never disagree.
 */
final class ApplyRobotsHeader
{
    public function __construct(private readonly HeadManager $head) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->headers->has('X-Robots-Tag')) {
            return $response;
        }

        try {
            $robots = $this->decide($request);
        } catch (Throwable) {
            // The header is a courtesy; the page is already rendered.
            return $response;
        }

        if ($robots !== null && $robots->isRestrictive()) {
            $response->headers->set('X-Robots-Tag', $robots->toString());
        }

        return $response;
    }

    /**
     * As cheap as the answer allows, since this runs on every response:
     * - a page that printed its head: that head's decision, already made;
     * - outside production: noindex, without reading any settings;
     * - a page that said something about itself, or the route did: decided;
     * - anything else: only when a path rule (or `index_site: false`) in the
     *   settings covers the path, which a cached copy answers with no
     *   database read.
     */
    private function decide(Request $request): ?RobotsDirectives
    {
        if ($this->head->isUsed()) {
            return $this->head->robotsDirectives();
        }

        if ($this->head->forcedOutsideProduction()) {
            return (new RobotsDirectives)->noindex();
        }

        if ($request->route()?->getAction(HeadManager::ROUTE_ROBOTS) !== null || $this->head->pathIsRuled()) {
            return $this->head->robotsDirectives();
        }

        return null;
    }
}
