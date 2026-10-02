<?php

namespace XerAds\Laravel\Seo\Sitemap;

use DateTimeInterface;
use XMLWriter;

/**
 * Sitemap XML (sitemaps.org protocol 0.9, with the image extension), written
 * with XMLWriter, so every value is escaped and the document is well formed
 * however odd an address is.
 */
final class SitemapWriter
{
    public const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public const IMAGE_NS = 'http://www.google.com/schemas/sitemap-image/1.1';

    /** @param  list<array{loc: string, lastmod: DateTimeInterface|null}>  $sitemaps */
    public function index(array $sitemaps): string
    {
        $xml = $this->start();
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', self::NS);

        foreach ($sitemaps as $sitemap) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $sitemap['loc']);

            if ($sitemap['lastmod'] !== null) {
                $xml->writeElement('lastmod', $sitemap['lastmod']->format(DATE_ATOM));
            }

            $xml->endElement();
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /** @param  list<SitemapUrl>  $urls */
    public function urlset(array $urls, bool $images): string
    {
        $xml = $this->start();
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', self::NS);

        if ($images) {
            $xml->writeAttribute('xmlns:image', self::IMAGE_NS);
        }

        foreach ($urls as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url->loc);

            if ($url->lastmod !== null) {
                $xml->writeElement('lastmod', $url->lastmod->format(DATE_ATOM));
            }

            if ($images) {
                foreach (array_slice($url->images, 0, 1000) as $image) {
                    $xml->startElement('image:image');
                    $xml->writeElement('image:loc', $image);
                    $xml->endElement();
                }
            }

            $xml->endElement();
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    private function start(): XMLWriter
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(false);
        $xml->startDocument('1.0', 'UTF-8');

        return $xml;
    }
}
