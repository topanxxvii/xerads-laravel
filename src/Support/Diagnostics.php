<?php

namespace XerAds\Laravel\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Throwable;
use XerAds\Laravel\Content\ConfigurationReport;
use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Seo\Http\ServeSeoFiles;
use XerAds\Laravel\Seo\IndexNow\IndexNowKey;

/**
 * What can be checked about an installation without changing it.
 *
 * Shared by `ping` (so the XerAds dashboard can show a site's health) and
 * `xerads:doctor` (so the site owner can see it in a terminal), so the two
 * never disagree about what is wrong.
 */
final class Diagnostics
{
    /** Middleware that must not run on the webhook: it would refuse or alter XerAds' requests. */
    private const WEB_ONLY_MIDDLEWARE = ['web', 'VerifyCsrfToken', 'ValidateCsrfToken', 'StartSession', 'EncryptCookies'];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly Tables $tables,
        private readonly Features $features,
        private readonly Router $router,
    ) {}

    /**
     * The checks `ping` reports.
     *
     * @return array{receiver: list<string>, storage_link: bool, queue: string, app_url_https: bool, robots_static_file: bool, sitemap_static_file: bool}
     */
    public function pingChecks(): array
    {
        return [
            'receiver' => $this->receiverReport()->problems ?? [],
            'storage_link' => $this->storageLinked(),
            'queue' => $this->queueDriver(),
            'app_url_https' => $this->appUrlIsHttps(),
            'robots_static_file' => $this->staticFile('robots.txt'),
            'sitemap_static_file' => $this->staticFile('sitemap.xml'),
        ];
    }

    /** The receiver's own check, or null with content turned off. */
    public function receiverReport(): ?ConfigurationReport
    {
        if (! $this->features->has('articles')) {
            return null;
        }

        try {
            return $this->container->make(ContentReceiver::class)->validateConfiguration();
        } catch (Throwable $exception) {
            return new ConfigurationReport(['The receiver could not be built: '.$exception->getMessage()]);
        }
    }

    public function storageLinked(): bool
    {
        return file_exists(public_path('storage'));
    }

    /** The queue driver package jobs would run on. */
    public function queueDriver(): string
    {
        $connection = $this->config->get('xerads.queue.connection') ?: $this->config->get('queue.default', 'sync');
        $connection = is_string($connection) ? $connection : 'sync';
        $driver = $this->config->get("queue.connections.{$connection}.driver");

        return is_string($driver) ? $driver : $connection;
    }

    public function appUrlIsHttps(): bool
    {
        return str_starts_with(strtolower((string) $this->config->get('app.url', '')), 'https://');
    }

    /** A file in public/ that a web server serves before Laravel sees the request. */
    public function staticFile(string $name): bool
    {
        return is_file(public_path($name));
    }

    /**
     * The package's paths that a route of the site's with parameters (a
     * `/{slug}` page route, an SPA's catch-all) answers instead, so search
     * engines never see the package's robots.txt, sitemap or IndexNow key.
     * Never the case while ServeSeoFiles runs: it answers these paths first.
     *
     * @return array{robots: bool, sitemap: bool, indexnow_key: bool}
     */
    public function shadowedRoutes(): array
    {
        $served = ($this->config->get('xerads.middleware.global', true) !== false && $this->config->get('xerads.middleware.seo_files', true) !== false)
            || $this->kernelRuns($this->container->make(HttpKernel::class), ServeSeoFiles::class);

        if ($served) {
            return ['robots' => false, 'sitemap' => false, 'indexnow_key' => false];
        }

        try {
            $key = $this->container->make(IndexNowKey::class)->current();
        } catch (Throwable) {
            $key = null;
        }

        return [
            'robots' => $this->shadows('/robots.txt'),
            'sitemap' => $this->shadows('/sitemap.xml'),
            'indexnow_key' => $key !== null && $this->shadows('/'.$key.'.txt'),
        ];
    }

    /**
     * The route of the site's that answers the turnkey blog's paths before
     * the blog does, or null when none does (or the site is not turnkey).
     *
     * The blog's routes are registered after the site's own, so that a page
     * of the site's under the prefix (`/blog/feed`) stays the site's. A route
     * with parameters that also takes the blog's index or any article slug,
     * such as an SPA's `/{any}` registered with `Route::get()` rather than
     * `Route::fallback()`, hides the whole blog instead.
     */
    public function blogShadowedBy(): ?string
    {
        if ($this->config->get('xerads.content.mode') !== 'turnkey' || $this->config->get('xerads.modules.content', true) === false) {
            return null;
        }

        $routes = $this->router->getRoutes();

        foreach (['/'.Article::prefix(), Article::pathFor('xerads-'.Str::lower(Str::random(16)))] as $path) {
            try {
                $matched = $routes->match(Request::create($path));
            } catch (Throwable) {
                continue;
            }

            if (! str_starts_with((string) $matched->getName(), 'xerads.blog.') && $matched->parameterNames() !== []) {
                return $matched->uri();
            }
        }

        return null;
    }

    /** Laravel's kernel can say what global middleware it runs; the contract does not promise it. */
    private function kernelRuns(object $kernel, string $middleware): bool
    {
        return method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware($middleware);
    }

    private function shadows(string $path): bool
    {
        try {
            return ServeSeoFiles::shadowed($this->router, Request::create($path)) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function webhookRoute(): ?Route
    {
        return $this->router->getRoutes()->getByName('xerads.webhook');
    }

    /**
     * Middleware on the webhook route that belongs to browser sessions. The
     * CSRF check alone would answer every delivery with 419.
     *
     * @return list<string>
     */
    public function webhookWebMiddleware(): array
    {
        $route = $this->webhookRoute();

        if ($route === null) {
            return [];
        }

        $found = [];

        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            $name = is_string($middleware) ? $middleware : '';

            foreach (self::WEB_ONLY_MIDDLEWARE as $webOnly) {
                if ($name === $webOnly || str_ends_with(strtok($name, ':') ?: $name, '\\'.$webOnly)) {
                    $found[] = $name;
                }
            }
        }

        foreach ($route->middleware() as $middleware) {
            if ($middleware === 'web') {
                $found[] = 'web';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Package tables that do not exist yet.
     *
     * @return list<string>
     */
    public function missingTables(): array
    {
        $missing = [];

        $tables = ['state', 'deliveries', 'content_map', 'seo_meta', 'media', 'redirects', 'not_found'];

        if ($this->config->get('xerads.content.mode') === 'turnkey') {
            array_push($tables, 'articles', 'categories', 'tags', 'article_category', 'article_tag');
        }

        foreach ($tables as $table) {
            if (! $this->tables->exists($table)) {
                $missing[] = $this->tables->name($table);
            }
        }

        return $missing;
    }

    /**
     * Routes of the site's own under the package's prefix, which would
     * shadow or be shadowed by the package's.
     *
     * @return list<string>
     */
    public function routeConflicts(): array
    {
        $prefix = trim((string) $this->config->get('xerads.routes.prefix', 'xerads/v1'), '/');
        $conflicts = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($route->uri(), $prefix.'/') && ! str_starts_with($name, 'xerads.')) {
                $conflicts[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        return $conflicts;
    }
}
