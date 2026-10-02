<?php

namespace XerAds\Laravel\Seo;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Throwable;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Sync\RemoteState;

/**
 * The SEO settings this site renders with: the document edited in the XerAds
 * dashboard (`xerads.site_settings`, pulled by the Synchronizer), over the
 * package's defaults, under the site's own `xerads.seo.overrides`.
 *
 * Looked up once per request (a memo), then from the cache (no database read
 * at all on a hit), then from the last known good copy in `xerads_state`. Without a pairing, with
 * `xerads.seo.remote` off, or with a copy held for another site, the site
 * renders from its own config alone.
 *
 * Lists (`pages`, `robots.paths`, `organization.same_as`…) are replaced
 * whole by a higher layer, never merged item by item, as XerAds merges them.
 */
final class SettingsRepository
{
    /** How long a server keeps the copy before reading `xerads_state` again. */
    private const CACHE_SECONDS = 600;

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly RemoteState $state,
        private readonly CredentialsResolver $credentials,
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->memo ??= self::merge(
            self::merge($this->defaults(), $this->remote()),
            $this->overrides(),
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
    }

    /** A string setting, or null when it is empty or not a string. */
    public function string(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Drop the cached copy: after a new document was pulled, after a pairing,
     * or once the key in force belongs to another site.
     *
     * Moves the cache on to a new generation rather than deleting one entry:
     * a request that read the old document a moment before can still write
     * it, but under the old generation, which nothing reads any more.
     */
    public function forget(): void
    {
        $this->memo = null;

        try {
            $store = $this->store();

            if ($store->increment($this->generationKey()) === false) {
                $store->forever($this->generationKey(), 1);
            }
        } catch (Throwable) {
            // A cache that is down holds nothing to forget.
        }
    }

    /** Where the current generation's copy is cached. */
    public function cacheKey(): string
    {
        try {
            $generation = $this->store()->get($this->generationKey());
        } catch (Throwable) {
            $generation = null;
        }

        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':settings:'.(is_numeric($generation) ? (int) $generation : 0);
    }

    /**
     * What the site renders with before XerAds has sent anything: the shape
     * of XerAds' own defaults, filled from the application's config.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $name = trim((string) $this->config->get('app.name', 'Site'));
        $name = $name !== '' ? $name : 'Site';
        $language = strtolower(substr((string) $this->config->get('app.locale', 'id'), 0, 2)) === 'en' ? 'en' : 'id';

        return [
            'site' => ['name' => $name, 'tagline' => '', 'url' => (string) $this->config->get('app.url', ''), 'default_language' => $language, 'logo' => null],
            'titles' => [
                'separator' => '-',
                'article' => '{title} {sep} {site}',
                'home' => '{site} {sep} {tagline}',
                'category' => '{term} {sep} {site}',
                'tag' => '{term} {sep} {site}',
                'archive' => 'Blog {sep} {site}',
                'search' => '{query} {sep} {site}',
                'not_found' => '{site}',
            ],
            'meta' => ['default_description' => '', 'default_og_image' => null, 'twitter_site' => null],
            // No name of its own: the organisation is called what the site is.
            'organization' => ['type' => 'Organization', 'name' => null, 'legal_name' => null, 'logo' => null, 'same_as' => []],
            'website' => ['alternate_name' => null],
            'verification' => ['google' => null, 'bing' => null, 'yandex' => null, 'baidu' => null, 'pinterest' => null],
            'robots' => [
                'index_site' => true,
                'default' => ['max_snippet' => -1, 'max_image_preview' => 'large', 'max_video_preview' => -1],
                'noindex' => ['categories' => false, 'tags' => true, 'paginated' => false, 'search' => true],
                'paths' => [],
            ],
            'pages' => [],
            'breadcrumbs' => ['enabled' => true, 'home_label' => null],
            'articles' => ['schema_type' => 'BlogPosting', 'default_author' => null],
        ];
    }

    /**
     * Objects merge key by key; lists and scalars of the higher layer replace
     * the lower one's. A layer's null replaces too: XerAds' `logo: null` means
     * "no logo".
     *
     * @param  array<array-key, mixed>  $lower
     * @param  array<array-key, mixed>  $higher
     * @return array<array-key, mixed>
     */
    public static function merge(array $lower, array $higher): array
    {
        foreach ($higher as $key => $value) {
            $base = $lower[$key] ?? null;

            $lower[$key] = is_array($value) && is_array($base) && ! array_is_list($value) && ! array_is_list($base)
                ? self::merge($base, $value)
                : $value;
        }

        return $lower;
    }

    /**
     * The document from XerAds. A cache hit reads no database at all: the
     * entry carries the site it was read for, and anything that changes
     * which document applies (a pull, a pairing, another site's key) moves
     * the cache on to a new generation.
     *
     * @return array<string, mixed>
     */
    private function remote(): array
    {
        if (! $this->config->get('xerads.seo.remote', true)) {
            return [];
        }

        $key = $this->cacheKey();

        try {
            $cached = $this->store()->get($key);

            if (is_array($cached) && array_key_exists('site_id', $cached) && is_array($cached['data'] ?? null)) {
                return $cached['data'];
            }
        } catch (Throwable) {
            // Read from the database instead.
        }

        try {
            $siteId = $this->credentials->current()?->siteId;
        } catch (InvalidSiteKey) {
            $siteId = null;
        }

        // Only a copy held for the site whose key is in force (RemoteState::document()).
        $data = $siteId !== null ? ($this->state->document('settings')['data'] ?? null) : null;
        $data = is_array($data) ? $data : [];
        unset($data['schema'], $data['v'], $data['version']);

        try {
            $this->store()->put($key, ['site_id' => $siteId, 'data' => $data], self::CACHE_SECONDS);
        } catch (Throwable) {
            // Uncached, the next request reads the database again.
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function overrides(): array
    {
        $overrides = $this->config->get('xerads.seo.overrides', []);

        return is_array($overrides) ? $overrides : [];
    }

    private function generationKey(): string
    {
        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':settings:generation';
    }

    private function store(): Cache
    {
        $store = $this->config->get('xerads.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
