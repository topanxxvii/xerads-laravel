<?php

namespace XerAds\Laravel\Support;

/**
 * The path part of a path or an address, without its query or fragment.
 *
 * `parse_url()` reads a bare path with a colon in it (`/blog/promo:2025`) as
 * a host and a port, and gives up: a path is cut at `?` or `#` instead. Only
 * an address with a scheme (`https://…`) goes through `parse_url()`.
 */
final class UrlPath
{
    public static function of(string $pathOrUrl): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $pathOrUrl) === 1) {
            $path = parse_url($pathOrUrl, PHP_URL_PATH);

            return is_string($path) ? $path : '';
        }

        return (string) preg_replace('/[?#].*$/s', '', $pathOrUrl);
    }
}
