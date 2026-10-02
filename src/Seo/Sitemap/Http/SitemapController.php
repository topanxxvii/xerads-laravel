<?php

namespace XerAds\Laravel\Seo\Sitemap\Http;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Seo\Sitemap\SitemapBuilder;
use XerAds\Laravel\Seo\Sitemap\SitemapWriter;

/**
 * `/sitemap.xml` (the index) and `/sitemaps/{source}-{page}.xml`.
 *
 * Whole or not at all: anything that goes wrong while building answers 503
 * with `Retry-After: 60`, never a partial sitemap, because a sitemap missing
 * addresses tells search engines those pages are gone. An index with nothing
 * to list answers 404: the protocol has no empty index.
 */
final class SitemapController
{
    public function index(SitemapBuilder $builder, SitemapWriter $writer, SiteAddress $address): Response
    {
        return $this->respond($builder, function () use ($builder, $writer, $address) {
            $pages = $builder->pages();

            return $pages === [] ? null : $writer->index(array_map(fn (array $page) => [
                'loc' => $address->url('/sitemaps/'.$page['source'].'-'.$page['page'].'.xml'),
                'lastmod' => $page['lastmod'],
            ], $pages));
        });
    }

    public function page(SitemapBuilder $builder, SitemapWriter $writer, string $source, string $page): Response
    {
        return $this->respond($builder, function () use ($builder, $writer, $source, $page) {
            $urls = $builder->page($source, (int) $page);

            return $urls === null ? null : $writer->urlset($urls, $builder->includesImages());
        });
    }

    /** @param  callable(): (string|null)  $build */
    private function respond(SitemapBuilder $builder, callable $build): Response
    {
        try {
            if (! $builder->enabled()) {
                abort(404);
            }

            $xml = $build();
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            Log::error('XerAds could not build the sitemap; it answered 503 rather than a partial one.', ['error' => mb_substr($exception->getMessage(), 0, 300)]);

            return new Response('The sitemap is being rebuilt. Try again in a minute.', 503, [
                'Retry-After' => '60',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Robots-Tag' => 'noindex',
            ]);
        }

        if ($xml === null) {
            abort(404);
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
