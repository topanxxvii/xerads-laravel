<?php

namespace XerAds\Laravel\Seo\NotFound;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Support\UrlPath;

/**
 * Counts a request that answered 404 against its path.
 *
 * What is kept: the path (no query string), how often, when first and last,
 * and the host of the referring page. Never the visitor's IP address or
 * user agent. Not counted: what scanners probe for (`/wp-login.php`,
 * `/.env`…), static assets (a missing image is the theme's business, not a
 * redirect's), paths in `monitor_404.ignore`, and more than
 * `monitor_404.per_ip_per_minute` 404s a minute from one visitor (tracked
 * under a hash of their address, in the cache, for a minute). New paths stop
 * being added at `monitor_404.max_rows`; paths already listed keep counting.
 */
final class NotFoundRecorder
{
    /** What vulnerability scanners ask for on every site. */
    public const SCANNER_PATTERNS = [
        'wp-admin', 'wp-admin/*', 'wp-login.php', 'wp-content/*', 'wp-includes/*', 'xmlrpc.php', 'wp-json/*',
        '.env', '.env.*', '.git', '.git/*', '.svn/*', '.hg/*', '.DS_Store', '.well-known/*',
        'phpmyadmin', 'phpmyadmin/*', 'pma/*', 'cgi-bin/*', 'vendor/*', 'node_modules/*', 'storage/logs/*',
        'admin.php', 'config.php', 'setup.php', 'install.php', 'shell.php', 'eval-stdin.php', '*.php', '*.asp', '*.aspx', '*.jsp', '*.cgi', '*.sql', '*.bak', '*.zip', '*.tar', '*.gz',
    ];

    /** Extensions of files a page loads, not of pages. */
    public const ASSET_EXTENSIONS = [
        'js', 'mjs', 'css', 'map', 'json', 'xml', 'txt',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'avif', 'bmp', 'tif', 'tiff',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp3', 'mp4', 'webm', 'ogg', 'wav', 'mov',
    ];

    public function __construct(
        private readonly Tables $tables,
        private readonly SettingsRepository $settings,
        private readonly RateLimiter $limiter,
        private readonly Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('xerads.monitor_404.enabled', true)
            && $this->settings->get('monitor_404.enabled', true) !== false;
    }

    /** @return bool whether the 404 was counted */
    public function record(Request $request): bool
    {
        $path = self::path($request->getPathInfo());

        if ($path === null || $this->ignored($path) || ! $this->enabled() || ! $this->tables->exists('not_found')) {
            return false;
        }

        if (! $this->allowed($request)) {
            return false;
        }

        $hash = self::hashOf($path);
        $now = Carbon::now();

        if (! NotFoundEntry::query()->where('path_hash', $hash)->exists()) {
            if (NotFoundEntry::query()->count() >= max(1, (int) $this->config->get('xerads.monitor_404.max_rows', 10_000))) {
                return false;
            }

            NotFoundEntry::query()->insertOrIgnore([
                'path' => $path,
                'path_hash' => $hash,
                'hits' => 0,
                'reported_hits' => 0,
                'status' => NotFoundEntry::OPEN,
                'first_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // One statement, so two 404s at once both count, on any database.
        NotFoundEntry::query()->where('path_hash', $hash)->toBase()->increment('hits', 1, array_filter([
            'last_seen_at' => $now,
            'updated_at' => $now,
            'status' => NotFoundEntry::OPEN,
            'last_referrer_host' => $this->referrerHost($request),
        ], fn ($value) => $value !== null));

        return true;
    }

    /**
     * The path as counted: without query, trailing slash or fragment, as it
     * was requested (still percent-encoded); null when it is not worth
     * keeping. A path that is not UTF-8 once decoded is no page anyone
     * links to, and could not be sent to XerAds as JSON.
     */
    public static function path(string $requested): ?string
    {
        $path = '/'.trim(UrlPath::of($requested), '/');

        return strlen($path) <= 512
            && preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/', $path) !== 1
            && mb_check_encoding(rawurldecode($path), 'UTF-8')
            ? $path
            : null;
    }

    /** Encoded or not, one path is one row: `/caf%C3%A9` is `/café`. */
    public static function hashOf(string $path): string
    {
        return sha1(rawurldecode($path));
    }

    public function ignored(string $path): bool
    {
        $relative = ltrim(rawurldecode($path), '/');
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if ($extension !== '' && in_array($extension, self::ASSET_EXTENSIONS, true)) {
            return true;
        }

        $patterns = [...self::SCANNER_PATTERNS, ...array_filter((array) $this->config->get('xerads.monitor_404.ignore', []), 'is_string')];

        foreach ($patterns as $pattern) {
            if (Str::is(ltrim($pattern, '/'), $relative) || Str::is(ltrim($pattern, '/'), strtolower($relative))) {
                return true;
            }
        }

        return false;
    }

    /** Per visitor, keyed by a hash of their address that never leaves the cache. */
    private function allowed(Request $request): bool
    {
        $key = 'xerads-404:'.hash('sha256', (string) $request->ip().'|'.(string) $this->config->get('app.key'));
        $limit = max(1, (int) $this->config->get('xerads.monitor_404.per_ip_per_minute', 30));

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            return false;
        }

        $this->limiter->hit($key, 60);

        return true;
    }

    private function referrerHost(Request $request): ?string
    {
        $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);

        return is_string($host) && preg_match('/^[a-z0-9.-]{1,253}$/i', $host) === 1 ? strtolower($host) : null;
    }
}
