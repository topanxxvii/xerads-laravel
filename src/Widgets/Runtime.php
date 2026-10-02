<?php

namespace XerAds\Laravel\Widgets;

use Illuminate\Contracts\Config\Repository;

/**
 * Where the widget runtime lives.
 *
 * The loader is always referenced at its XerAds address and never copied
 * into the site: it derives its own base URL from its `src`, re-scans the page
 * when included again, and gains widget types and fixes without a package
 * release (widgets contract §3.2).
 */
final class Runtime
{
    public const DEFAULT_URL = 'https://widgets.xerads.id';

    public function __construct(private readonly Repository $config) {}

    /**
     * `widgets.runtime_url`, else the default address. (The address from the
     * dashboard settings slots in between once settings sync exists.)
     */
    public function url(): string
    {
        $url = $this->config->get('xerads.widgets.runtime_url');

        return rtrim(is_string($url) && trim($url) !== '' ? trim($url) : self::DEFAULT_URL, '/');
    }

    /** `widgets.loader_url`, else `{runtime}/v1/loader.js`. */
    public function loaderUrl(): string
    {
        $url = $this->config->get('xerads.widgets.loader_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : $this->url().'/v1/loader.js';
    }

    public function documentUrl(string $id): string
    {
        return $this->url().'/w/'.rawurlencode($id).'.json';
    }

    /** The loader's script tag, async, with the page's CSP nonce when it has one. */
    public function loaderTag(?string $nonce = null): string
    {
        return '<script src="'.e($this->loaderUrl()).'" async'.($nonce !== null && $nonce !== '' ? ' nonce="'.e($nonce).'"' : '').'></script>';
    }

    /** Does this HTML already include a widget loader, from here or pasted? */
    public static function hasLoader(string $html): bool
    {
        return preg_match('#<script\b[^>]*\bsrc\s*=\s*["\']?[^"\'>]*/v\d+/loader\.js#i', $html) === 1;
    }
}
