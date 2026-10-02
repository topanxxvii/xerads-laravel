<?php

namespace XerAds\Laravel\Seo\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Response;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Seo\Sitemap\SitemapBuilder;

/**
 * `/robots.txt` from the settings: the rules (`robots.txt.rules`), the extra
 * directives (`robots.txt.extra`) and a `Sitemap:` line on the site's own
 * address. Outside production, `Disallow: /` and nothing else, so a staging
 * copy is never crawled.
 *
 * XerAds validates the rules when they are saved; they are checked again
 * here, line by line, so nothing but robots.txt directives is ever printed.
 */
final class RobotsTxtController
{
    private const USER_AGENT = '/^[A-Za-z0-9*._\-\/ ]{1,100}$/D';

    private const PATH = '/^\/(?![\/\\\\])[^\x00-\x20\x7F<>"\'`\\\\]*$/D';

    private const DIRECTIVE = '/^(User-agent|Allow|Disallow|Crawl-delay|Sitemap)\s*:[^\x00-\x08\x0B-\x1F\x7F<>]*$/i';

    public function __invoke(SettingsRepository $settings, SiteAddress $address, SitemapBuilder $sitemaps, Repository $config, Application $app): Response
    {
        if ($config->get('xerads.seo.noindex_non_production', true) && ! $app->isProduction()) {
            return $this->text("User-agent: *\nDisallow: /\n");
        }

        $groups = [];

        foreach ((array) $settings->get('robots.txt.rules', []) as $rule) {
            $agent = is_array($rule) && is_string($rule['user_agent'] ?? null) ? trim($rule['user_agent']) : '';

            if (preg_match(self::USER_AGENT, $agent) !== 1) {
                continue;
            }

            $lines = ['User-agent: '.$agent];

            foreach (['allow' => 'Allow', 'disallow' => 'Disallow'] as $key => $directive) {
                foreach ((array) ($rule[$key] ?? []) as $path) {
                    if (is_string($path) && preg_match(self::PATH, $path) === 1) {
                        $lines[] = $directive.': '.$path;
                    }
                }
            }

            $groups[] = implode("\n", $lines);
        }

        if ($groups === []) {
            $groups[] = "User-agent: *\nAllow: /";
        }

        $extra = [];

        foreach (preg_split('/\R/', (string) $settings->get('robots.txt.extra', '')) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && (preg_match(self::DIRECTIVE, $line) === 1 || (str_starts_with($line, '#') && preg_match('/[\x00-\x08\x0B-\x1F\x7F<>]/', $line) !== 1))) {
                $extra[] = $line;
            }
        }

        if ($extra !== []) {
            $groups[] = implode("\n", $extra);
        }

        // While the site is kept out of search engines the index has nothing
        // to list (it answers 404), so it is not offered.
        if ($sitemaps->enabled() && $settings->get('robots.index_site', true) !== false) {
            $groups[] = 'Sitemap: '.$address->url('/sitemap.xml');
        }

        return $this->text(implode("\n\n", $groups)."\n");
    }

    private function text(string $body): Response
    {
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
