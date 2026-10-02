<?php

namespace XerAds\Laravel\Widgets;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use XerAds\Laravel\Seo\SettingsRepository;

/**
 * Where the widget runtime lives.
 *
 * The loader is always referenced at its XerAds address and never copied
 * into the site: it derives its own base URL from its `src`, re-scans the page
 * when included again, and gains widget types and fixes without a package
 * release.
 */
final class Runtime
{
    public const DEFAULT_URL = 'https://widgets.xerads.id';

    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    /**
     * The runtime's address: `widgets.runtime_url` when the site set it, else
     * the one the dashboard settings name (`widgets.loader_url`, without
     * `/v1/loader.js`), else the default.
     */
    public function url(): string
    {
        $configured = $this->configured('runtime_url');

        if ($configured !== null) {
            return rtrim($configured, '/');
        }

        $fromSettings = $this->loaderFromSettings();

        if ($fromSettings !== null && preg_match('#^(.+)/v\d+/loader\.js$#', $fromSettings, $match) === 1) {
            return $match[1];
        }

        return self::DEFAULT_URL;
    }

    /**
     * The one answer to "which loader": `widgets.loader_url` from config,
     * else `{widgets.runtime_url}/v1/loader.js` from config, else the
     * settings' `widgets.loader_url`, else the default. Every reader (the
     * scripts component, the injecting middleware, the Inertia prop) asks
     * here, so they can never disagree.
     */
    public function loaderUrl(): string
    {
        $configured = $this->configured('loader_url');

        if ($configured !== null) {
            return $configured;
        }

        if ($this->configured('runtime_url') !== null) {
            return $this->url().'/v1/loader.js';
        }

        return $this->loaderFromSettings() ?? self::DEFAULT_URL.'/v1/loader.js';
    }

    private function configured(string $key): ?string
    {
        $value = $this->config->get('xerads.widgets.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** The settings' loader, when it is an https address. */
    private function loaderFromSettings(): ?string
    {
        if (! $this->container->bound(SettingsRepository::class)) {
            return null;
        }

        $url = $this->container->make(SettingsRepository::class)->string('widgets.loader_url');

        return $url !== null && preg_match('#^https://[^\s"\'<>]+$#i', $url) === 1 ? $url : null;
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
