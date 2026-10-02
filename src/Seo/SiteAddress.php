<?php

namespace XerAds\Laravel\Seo;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

/**
 * The site's own address, for everything the package writes out as a full
 * URL (robots.txt, sitemaps, llms.txt, IndexNow): `site.url` from the
 * settings, else `app.url`. Never the request's Host header, which a client
 * chooses.
 */
final class SiteAddress
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    /** Without a trailing slash. */
    public function base(): string
    {
        return rtrim($this->settings->string('site.url') ?? (string) $this->config->get('app.url', ''), '/');
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->base(), PHP_URL_HOST));
    }

    public function isHttps(): bool
    {
        return str_starts_with(strtolower($this->base()), 'https://');
    }

    /**
     * A path, or an address on one of the site's own hosts, as a full URL
     * under the base. The site's own hosts are the base's, `app.url`'s and
     * the current request's: `url()` and `route()` build addresses on the
     * host a client sent, which may be an alias of the site or a forged
     * one, and either way the page is this site's.
     */
    public function url(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            $host = strtolower((string) parse_url($pathOrUrl, PHP_URL_HOST));

            if (! in_array($host, $this->ownHosts(), true)) {
                return $pathOrUrl;
            }

            $path = (string) parse_url($pathOrUrl, PHP_URL_PATH);
            $query = parse_url($pathOrUrl, PHP_URL_QUERY);
            $pathOrUrl = ($path !== '' ? $path : '/').(is_string($query) && $query !== '' ? '?'.$query : '');
        }

        return $this->base().'/'.ltrim($pathOrUrl, '/');
    }

    /**
     * The hosts url() treats as this site's.
     *
     * @return list<string>
     */
    public function ownHosts(): array
    {
        return array_values(array_unique(array_filter([
            $this->host(),
            strtolower((string) parse_url((string) $this->config->get('app.url', ''), PHP_URL_HOST)),
            $this->requestHost(),
        ], fn (string $host) => $host !== '')));
    }

    /**
     * Whether the current request (if any) came for the site's own address
     * or `app.url`'s, not for some other name a client put in its Host
     * header. What is built while answering any other is not cached.
     */
    public function requestIsOnOwnHost(): bool
    {
        $host = $this->requestHost();

        return $host === '' || in_array($host, [$this->host(), strtolower((string) parse_url((string) $this->config->get('app.url', ''), PHP_URL_HOST))], true);
    }

    /** The current request's host; '' outside a request or for a host Symfony refuses. */
    public function requestHost(): string
    {
        try {
            $request = $this->container->bound('request') ? $this->container->make('request') : null;

            return $request instanceof Request ? strtolower($request->getHost()) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /** The path of an address, for matching against path rules. */
    public static function pathOf(string $url): string
    {
        return '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
