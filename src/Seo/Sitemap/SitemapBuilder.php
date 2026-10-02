<?php

namespace XerAds\Laravel\Seo\Sitemap;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use XerAds\Laravel\Content\Receivers\MappedModel;
use XerAds\Laravel\Seo\ContentVersion;
use XerAds\Laravel\Seo\HeadManager;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Seo\Sitemap\Contracts\SitemapSource;
use XerAds\Laravel\Seo\Sitemap\Sources\ArticleSource;
use XerAds\Laravel\Seo\Sitemap\Sources\MappedArticleSource;
use XerAds\Laravel\Seo\Sitemap\Sources\ModelSource;
use XerAds\Laravel\Seo\Sitemap\Sources\TermSource;
use XerAds\Laravel\Support\Tables;

/**
 * Decides what the sitemaps list, and caches it.
 *
 * Every source's addresses pass the same checks: none at all while the
 * settings keep the site out of search engines (`index_site: false`), and
 * none whose head says noindex (the same decision HeadManager makes from
 * `robots.default`, the path rules, `pages[]`, the noindex groups and the
 * page's own robots), have a canonical elsewhere, sit under
 * `sitemap.exclude_paths`, are on another host, or could not be written as
 * XML (not UTF-8, or with control characters). Each source is then cut into
 * pages of `sitemap.max_urls`.
 *
 * Sources are read with the URL generator rooted at the site's own address,
 * so `url()` and `route()` in a model build addresses on the site whatever
 * Host the request came with; and a list built while answering a request
 * for another host is never cached.
 *
 * Cached per source under the content version and the settings' cache
 * generation, so an article stored or a setting pulled is listed on the next
 * request, and an unchanged site never rebuilds (`sitemap.cache_ttl` bounds
 * the wait for changes the site makes to its own models). Without a working
 * cache every request builds afresh.
 */
final class SitemapBuilder
{
    /** A sitemap's name is part of its address: /sitemaps/{name}-{page}.xml. */
    private const NAME = '/^[a-z]+$/D';

    public function __construct(
        private readonly Container $container,
        private readonly SettingsRepository $settings,
        private readonly SiteAddress $address,
        private readonly ContentVersion $version,
        private readonly Tables $tables,
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('xerads.sitemap.enabled', true)
            && $this->settings->get('sitemap.enabled', true) !== false;
    }

    public function includesImages(): bool
    {
        return $this->settings->get('sitemap.include.images', true) !== false;
    }

    /**
     * @return array<string, SitemapSource> by name
     *
     * @throws InvalidArgumentException for a source or model the config names wrongly
     */
    public function sources(): array
    {
        $sources = [];
        $include = fn (string $key, bool $default) => $this->settings->get('sitemap.include.'.$key, $default) !== false;
        $noindexed = fn (string $group) => $this->settings->get('robots.noindex.'.$group) === true;
        $turnkey = $this->config->get('xerads.content.mode') === 'turnkey' && $this->tables->exists('articles');

        if ($include('articles', true)) {
            if ($turnkey) {
                $sources[] = new ArticleSource($this->address);
            } elseif ($this->config->get('xerads.content.mode') === 'mapped' && $this->tables->exists('content_map')) {
                $sources[] = new MappedArticleSource($this->address);
            }
        }

        // A noindexed group has nothing to list: its pages all say noindex.
        if ($turnkey && $include('categories', true) && ! $noindexed('categories')) {
            $sources[] = new TermSource($this->address, 'categories');
        }

        if ($turnkey && $include('tags', false) && ! $noindexed('tags')) {
            $sources[] = new TermSource($this->address, 'tags');
        }

        foreach ((array) $this->config->get('xerads.sitemap.models', []) as $name => $class) {
            if (! is_string($name) || preg_match(self::NAME, $name) !== 1) {
                throw new InvalidArgumentException('xerads.sitemap.models: "'.$name.'" cannot name a sitemap. Use lowercase letters a-z only; it becomes /sitemaps/{name}-1.xml.');
            }

            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw new InvalidArgumentException("xerads.sitemap.models.{$name} must be the class name of an Eloquent model.");
            }

            $sources[] = new ModelSource($name, $class, $this->address, $this->container->make(MappedModel::class));
        }

        foreach ((array) $this->config->get('xerads.sitemap.sources', []) as $class) {
            $source = is_string($class) ? $this->container->make($class) : $class;

            if (! $source instanceof SitemapSource) {
                throw new InvalidArgumentException('Every entry of xerads.sitemap.sources must be a class implementing '.SitemapSource::class.'.');
            }

            if (preg_match(self::NAME, $source->name()) !== 1) {
                throw new InvalidArgumentException('The sitemap source '.$source::class.' is named "'.$source->name().'". Use lowercase letters a-z only; it becomes /sitemaps/{name}-1.xml.');
            }

            $sources[] = $source;
        }

        $byName = [];

        foreach ($sources as $source) {
            if (isset($byName[$source->name()])) {
                throw new InvalidArgumentException('Two sitemaps are named "'.$source->name().'" ('.$byName[$source->name()]::class.' and '.$source::class.'). Give the one in xerads.sitemap.models or .sources another name.');
            }

            $byName[$source->name()] = $source;
        }

        return $byName;
    }

    /**
     * The sitemaps the index lists: one per page of each source with addresses.
     *
     * @return list<array{source: string, page: int, lastmod: DateTimeInterface|null}>
     */
    public function pages(): array
    {
        $pages = [];

        foreach (array_keys($this->sources()) as $name) {
            foreach (array_chunk($this->listed($name), $this->perPage()) as $index => $chunk) {
                $lastmod = null;

                foreach ($chunk as $url) {
                    if ($url->lastmod !== null && ($lastmod === null || $url->lastmod > $lastmod)) {
                        $lastmod = $url->lastmod;
                    }
                }

                $pages[] = ['source' => $name, 'page' => $index + 1, 'lastmod' => $lastmod];
            }
        }

        return $pages;
    }

    /**
     * One page of a source, or null when there is no such page.
     *
     * @return list<SitemapUrl>|null
     */
    public function page(string $source, int $page): ?array
    {
        if ($page < 1 || ! array_key_exists($source, $this->sources())) {
            return null;
        }

        $chunk = array_slice($this->listed($source), ($page - 1) * $this->perPage(), $this->perPage());

        return $chunk === [] ? null : $chunk;
    }

    public function perPage(): int
    {
        $max = $this->settings->get('sitemap.max_urls') ?? $this->config->get('xerads.sitemap.max_urls', 5000);

        return max(1, min(50_000, (int) $max));
    }

    /**
     * Every address a source lists, after the checks, cached.
     *
     * @return list<SitemapUrl>
     */
    private function listed(string $name): array
    {
        $key = implode(':', [(string) $this->config->get('xerads.cache.prefix', 'xerads'), 'sitemap', $this->settings->cacheKey(), $this->version->current(), $name]);
        $cacheable = $this->address->requestIsOnOwnHost();

        try {
            $cached = $this->store()->get($key);
        } catch (Throwable) {
            $cached = null;
            $cacheable = false;
        }

        // Plain arrays in the cache: a cache that refuses to unserialise
        // objects must not turn a sitemap into an empty one.
        if (is_array($cached)) {
            return array_values(array_map(fn (array $url) => new SitemapUrl(
                loc: (string) $url['loc'],
                lastmod: is_string($url['lastmod'] ?? null) ? new DateTimeImmutable($url['lastmod']) : null,
                images: array_values(array_filter((array) ($url['images'] ?? []), 'is_string')),
            ), array_filter($cached, 'is_array')));
        }

        $listed = $this->build($name);

        if ($cacheable) {
            try {
                $this->store()->put($key, array_map(fn (SitemapUrl $url) => [
                    'loc' => $url->loc,
                    'lastmod' => $url->lastmod?->format(DATE_ATOM),
                    'images' => $url->images,
                ], $listed), max(60, (int) $this->config->get('xerads.sitemap.cache_ttl', 3600)));
            } catch (Throwable) {
                // Uncached, the next request builds it again.
            }
        }

        return $listed;
    }

    /** @return list<SitemapUrl> */
    private function build(string $name): array
    {
        $source = $this->sources()[$name] ?? null;

        if ($source === null || $this->settings->get('robots.index_site', true) === false) {
            return [];
        }

        return $this->onSiteRoot(function () use ($source, $name): array {
            $head = $this->container->make(HeadManager::class);
            $listed = [];
            $unwritable = [];

            foreach ($source->urls() as $url) {
                if (! self::writable($url->loc)) {
                    $unwritable[] = $url->loc;

                    continue;
                }

                if ($this->lists($url, $head)) {
                    $listed[] = new SitemapUrl(
                        loc: $url->loc,
                        lastmod: $url->lastmod,
                        images: $this->images($url->images),
                        index: $url->index,
                        canonical: $url->canonical,
                        kind: $url->kind,
                    );
                }
            }

            if ($unwritable !== []) {
                Log::warning('XerAds left '.count($unwritable)." address(es) out of the {$name} sitemap: they are not valid UTF-8 or contain control characters.", [
                    'example' => mb_substr(mb_scrub($unwritable[0], 'UTF-8'), 0, 200),
                ]);
            }

            return $listed;
        });
    }

    private function lists(SitemapUrl $url, HeadManager $head): bool
    {
        if (strtolower((string) parse_url($url->loc, PHP_URL_HOST)) !== $this->address->host()) {
            return false;
        }

        if ($url->canonical !== null && rtrim($this->address->url($url->canonical), '/') !== rtrim($url->loc, '/')) {
            return false;
        }

        $path = SiteAddress::pathOf($url->loc);

        foreach ((array) $this->settings->get('sitemap.exclude_paths', []) as $excluded) {
            if (is_string($excluded) && HeadManager::patternCovers($excluded, $path)) {
                return false;
            }
        }

        // The page's own robots may only keep it out, never lift a noindex.
        return $head->indexes($path, $url->kind, $url->index ? null : ['index' => false]);
    }

    /**
     * Image addresses as the sitemap needs them: absolute, on the site's own
     * address where they are the site's (a disk's `/storage/…` path, or an
     * address on `app.url`), and writable as XML.
     *
     * @param  list<string>  $images
     * @return list<string>
     */
    private function images(array $images): array
    {
        $absolute = [];

        foreach ($images as $image) {
            $image = trim($image);

            if (str_starts_with($image, '//')) {
                $image = 'https:'.$image;
            } elseif (str_starts_with($image, '/') || preg_match('#^https?://#i', $image) === 1) {
                $image = $this->address->url($image);
            } else {
                continue;
            }

            if (self::writable($image)) {
                $absolute[] = $image;
            }
        }

        return array_values(array_unique($absolute));
    }

    /**
     * An absolute http(s) address XML can carry: UTF-8, without control
     * characters or spaces (which XMLWriter would write as they are, making
     * the whole file unreadable).
     */
    private static function writable(string $url): bool
    {
        return preg_match('#^https?://[^/?\#]#i', $url) === 1
            && mb_check_encoding($url, 'UTF-8')
            && preg_match('/[\x00-\x20\x7F]/', $url) !== 1;
    }

    /**
     * Run with the URL generator answering for the site's own address, then
     * put back the request it had.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function onSiteRoot(callable $callback): mixed
    {
        $base = $this->address->base();

        if (preg_match('#^https?://#i', $base) !== 1) {
            return $callback();
        }

        $generator = $this->container->make(UrlGenerator::class);

        $original = $generator->getRequest();
        $generator->setRequest(Request::create($base.'/'));

        try {
            return $callback();
        } finally {
            $generator->setRequest($original);
        }
    }

    private function store(): Cache
    {
        $store = $this->config->get('xerads.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
