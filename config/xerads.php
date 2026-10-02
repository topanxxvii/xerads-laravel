<?php

use XerAds\Laravel\Content\Pipeline\AssignHeadingIds;
use XerAds\Laravel\Content\Pipeline\BuildToc;
use XerAds\Laravel\Content\Pipeline\CompileWidgets;
use XerAds\Laravel\Content\Pipeline\ComputeStats;
use XerAds\Laravel\Content\Pipeline\DemoteHeadings;
use XerAds\Laravel\Content\Pipeline\NormalizeWidgetPlaceholders;
use XerAds\Laravel\Content\Pipeline\SanitizeHtml;

return [
    /*
     * Which parts of the package run.
     *
     * Each module registers its own routes, middleware and listeners, so a site
     * that only wants articles does not pay for a sitemap it already has. A
     * module turned off here is not booted at all, rather than booted and
     * told to do nothing.
     */
    'modules' => [
        'content' => true,
        'seo' => true,
        'widgets' => true,
        'sync' => true,
    ],

    /*
     * How this site proves itself to XerAds, and XerAds to it.
     *
     * `php artisan xerads:pair` stores the key encrypted in the database, which
     * every server behind a load balancer shares and which survives
     * `config:cache` and long-running workers. Setting XERADS_SITE_KEY here
     * overrides that stored key, for hosts that keep every secret in the
     * environment. XERADS_SITE_KEY_PREVIOUS keeps the retiring key valid
     * during a rotation so deliveries already in flight still verify.
     *
     * `resolver` names a class extending CredentialsResolver, for sites that
     * keep secrets in a vault instead.
     */
    'credentials' => [
        'key' => env('XERADS_SITE_KEY'),
        'previous' => env('XERADS_SITE_KEY_PREVIOUS'),
        'resolver' => null,
    ],

    /*
     * The XerAds API this site pulls settings and redirects from. Short
     * timeouts, because these calls run after a response has been sent and
     * should never hold a worker for long.
     */
    'api' => [
        'url' => env('XERADS_API_URL', 'https://api.xerads.id'),
        'timeout' => 10,
        'connect_timeout' => 5,

        /*
         * Signed requests go only to a public https address, checked before
         * every request. A XerAds running on this machine for development
         * needs this on; it is ignored in production.
         */
        'allow_private_hosts' => env('XERADS_API_ALLOW_PRIVATE_HOSTS', false),
    ],

    /*
     * Where the package's own endpoints live: the webhook and the public
     * status route. Versioned in the path so a future contract can live next
     * to this one instead of replacing it under a running site.
     */
    'routes' => [
        'prefix' => 'xerads/v1',
        'domain' => null,
        'status' => true,
    ],

    /*
     * The original custom-endpoint receiver, kept for sites that installed it
     * before pairing existed.
     *
     * The route is registered only while a secret is set, so a site that
     * never used it exposes nothing. Setting any XERADS_CMS_* variable, or
     * keeping a published config/xerads-cms.php, selects the mapped content
     * mode below; remove those to choose another mode.
     */
    'legacy' => [
        'secret' => env('XERADS_CMS_SECRET', ''),
        'route' => env('XERADS_CMS_ROUTE', '/api/xerads/articles'),
        'middleware' => ['api'],

        /*
         * How far the request's timestamp may be from this server's clock, in
         * seconds. Generous enough for drift between two machines, short
         * enough that a captured request goes stale.
         */
        'timestamp_tolerance' => 300,

        /*
         * On the first delivery of an article, adopt an existing post with the
         * same slug — what the original receiver always did. It is how a
         * XerAds retry after a timeout, which arrives without the post id and
         * with a fresh signature, updates the post the first attempt created
         * instead of adding a duplicate. Legacy slugs carry a random suffix
         * from XerAds, so an unrelated post practically never shares one.
         */
        'match_existing_by_slug' => true,
    ],

    /*
     * Signed deliveries from XerAds. The tolerance bounds how long a captured
     * request stays replayable; the retention is how long delivery ids are
     * remembered, which must comfortably exceed XerAds' retry schedule so a
     * late retry is recognised as a duplicate rather than applied twice.
     */
    'webhook' => [
        'timestamp_tolerance' => 300,
        'delivery_retention_days' => 7,

        /*
         * The largest delivery accepted, in bytes. XerAds caps what it sends at
         * 2 MB; anything bigger is not from XerAds and is refused before its
         * signature is even computed.
         */
        'max_body_bytes' => 2 * 1024 * 1024,
    ],

    /* ── Where articles land ─────────────────────────────────────────────── */

    'content' => [
        /*
         * turnkey: the package owns the blog — tables, routes and views.
         * mapped:  articles are written into a model this site already has.
         * off:     no articles; SEO and widgets only.
         */
        'mode' => env('XERADS_CONTENT_MODE', 'mapped'),

        /*
         * What article HTML goes through before it is stored, in order. Each
         * step is a small class; add your own (implementing PipelineStep) or
         * drop one, but keep SanitizeHtml: this site is the one serving the
         * HTML, whatever XerAds already cleaned.
         */
        'pipeline' => [
            NormalizeWidgetPlaceholders::class,
            DemoteHeadings::class,
            SanitizeHtml::class,
            AssignHeadingIds::class,
            BuildToc::class,
            ComputeStats::class,
            CompileWidgets::class,
        ],

        /*
         * A published URL is a promise to readers and search engines. Once an
         * article has been public its slug stays, even when the title is
         * edited in XerAds. Turned off, a turnkey article follows XerAds' new
         * slug and its old address answers 301 to the new one.
         */
        'slug' => [
            'freeze_after_publish' => true,
        ],

        /*
         * The blog the package serves in turnkey mode: /blog, /blog/{slug},
         * /blog/category/{slug} and /blog/tag/{slug}, under `prefix`.
         *
         * Pages extend `layout` and fill its `section` (and `title`), so the
         * blog can wear the site's own layout; the default is a complete page
         * of its own. Publish the views with
         * `php artisan vendor:publish --tag=xerads-views` to change the markup.
         */
        'turnkey' => [
            'prefix' => env('XERADS_BLOG_PREFIX', 'blog'),
            'middleware' => ['web'],
            'layout' => 'xerads::layouts.blog',
            'section' => 'content',
            'per_page' => 12,

            /*
             * What a deleted article answers. 410 tells search engines the
             * page is gone on purpose, so it leaves the index faster than a
             * 404 would.
             */
            'deleted' => 410,
        ],

        'mapped' => [
            /** Your post model. Leave it unquoted in .env. */
            'model' => env('XERADS_CONTENT_MODEL', env('XERADS_CMS_MODEL', 'App\\Models\\Post')),

            /*
             * Column names, because no two sites agree on them. A field mapped
             * to null is not written, so a site without that column needs no
             * migration.
             */
            'fields' => [
                'title' => 'title',
                'seo_title' => null,
                'content' => 'content',
                'slug' => 'slug',
                'meta_description' => 'meta_description',
                'image_url' => null,
                'status' => 'status',
                'keywords' => null,
                'excerpt' => null,
                'headline' => null,
            ],

            /*
             * Written only when a post is created, for columns XerAds has no
             * value for but the table requires: an author id on a NOT NULL
             * `user_id`, a category, a site id. Plain values only, so the file
             * can still be cached.
             */
            'defaults' => [],

            /*
             * XerAds sends exactly two statuses. Translate them into whatever
             * this site's own scopes expect — words, booleans or integers.
             */
            'status_map' => [
                'draft' => 'draft',
                'publish' => 'published',
            ],

            /*
             * The named route that shows an article, used to tell XerAds where
             * it can be read. Leave empty and XerAds records no public link
             * rather than guessing a URL that 404s.
             *
             * The parameter value comes from `public_route_column`, or from the
             * saved slug column when the parameter is `slug`, or from the
             * primary key otherwise.
             */
            'public_route' => env('XERADS_CONTENT_ROUTE', env('XERADS_CMS_PUBLIC_ROUTE', '')),
            'public_route_parameter' => 'slug',
            'public_route_column' => null,

            /*
             * Adopt an existing row with the same slug on the first paired
             * delivery. (The legacy endpoint has its own switch, above.)
             *
             * Off by default: paired deliveries carry a stable delivery id and
             * article id, so a retry never needs the slug to find its row, and
             * clean slugs make a match with an unrelated post a real risk. Turn
             * it on once, while importing articles that were copied over by
             * hand, then turn it off again.
             */
            'match_existing_by_slug' => false,

            /** unpublish (keep the row, mapped draft status) or delete. */
            'on_delete' => 'unpublish',

            /*
             * html:      the body column receives finished HTML, widget
             *            containers included, for a template that prints
             *            `{!! $post->body !!}`.
             * shortcode: the body keeps `[xerads_widget …]` placeholders, for
             *            a template that prints `<x-xerads::content :html="$post->body" />`,
             *            which expands them when the page renders.
             */
            'content_format' => 'html',
        ],
    ],

    /*
     * Article HTML is sanitised before it is stored, whatever XerAds already
     * did, because this site is the one serving it. The input cap is far above
     * any real article so a long post is never silently truncated.
     */
    'sanitizer' => [
        'max_input_length' => 2_000_000,
        'allow_elements' => [],
    ],

    /*
     * Copy article images onto this site's own disk. XerAds replaces an image
     * file when it is regenerated, so a page that hot-links the original
     * breaks the day someone presses "regenerate". Copies run after the
     * webhook has answered, on the queue (`queue` below); pages show XerAds'
     * address until they are done.
     * Only JPEG, PNG, WebP, GIF and AVIF files up to `max_bytes` are copied,
     * to `{disk}:{path}/{year}/{month}/`.
     */
    'media' => [
        'mirror' => true,
        'disk' => env('XERADS_MEDIA_DISK', 'public'),
        'path' => 'xerads',
        'max_bytes' => 10 * 1024 * 1024,
    ],

    /* ── Search ──────────────────────────────────────────────────────────── */

    /*
     * Head tags. `remote` uses the settings edited in the XerAds dashboard;
     * `overrides` wins over them, in the same shape (for example
     * `['site' => ['name' => 'Toko'], 'titles' => ['separator' => '|']]`),
     * for values this site wants pinned in code. Non-production environments
     * are noindexed so a staging copy never competes with the live site.
     *
     * `search_url` is the site's own search page with `{search_term_string}`
     * where the query goes (https://shop.test/search?q={search_term_string});
     * with it, the structured data tells search engines the site has search.
     */
    'seo' => [
        'remote' => true,
        'overrides' => [],
        'noindex_non_production' => true,
        'canonical_query_allowlist' => ['page'],
        'search_url' => null,
        'inertia' => [
            'share' => true,
        ],
    ],

    /*
     * Extra structured-data pieces added to every page's graph: invokable
     * class names, called with the Graph and the resolved Head.
     */
    'schema' => [
        'pieces' => [],
    ],

    /*
     * /sitemap.xml (an index) and /sitemaps/{source}-{page}.xml. What the
     * settings say wins where both have a say (`sitemap.enabled`, `max_urls`,
     * `include`, `exclude_paths`). Cached and rebuilt when content changes;
     * changes to the site's own models show after `cache_ttl` seconds. A
     * request that fails while building answers 503 rather than a partial
     * file, because a partial sitemap tells search engines the missing URLs
     * are gone.
     *
     * `models`: the site's own models to list, name => class, for example
     * `['products' => App\Models\Product::class]`; each lists the rows its
     * `scopeXeradsSitemap()` keeps, at `xeradsSitemapUrl()`. `sources`:
     * classes implementing XerAds\Laravel\Seo\Sitemap\Contracts\SitemapSource.
     * A name is lowercase letters a-z only and unique (`articles`,
     * `categories` and `tags` are the package's): it becomes
     * /sitemaps/{name}-1.xml. Any other name stops the sitemaps with an
     * error, rather than leaving the model out unnoticed.
     */
    'sitemap' => [
        'enabled' => true,
        'max_urls' => 5000,
        'cache_ttl' => 3600,
        'sources' => [],
        'models' => [],
    ],

    /* /robots.txt from the settings; `Disallow: /` outside production. */
    'robots_txt' => [
        'enabled' => true,
    ],

    /*
     * /llms.txt: the site and its latest articles in Markdown for language
     * models. Null follows `llms_txt.enabled` in the settings (off by default).
     */
    'llms_txt' => [
        'enabled' => null,
    ],

    /*
     * Tell search engines about new and changed URLs the moment they change.
     * Production only, so a staging site never submits its own addresses.
     */
    'indexnow' => [
        'enabled' => true,
        'environments' => ['production'],
        'endpoint' => 'https://api.indexnow.org/indexnow',
        'debounce_seconds' => 60,
    ],

    /*
     * Redirects managed in XerAds. Looked up only when the site itself answers
     * 404, so a redirect can never shadow a page that exists.
     */
    'redirects' => [
        'enabled' => true,
        'mode' => 'on_404',
    ],

    /*
     * Count the paths that 404 so they can be redirected from the dashboard.
     * Rate-limited per visitor and capped in size, because scanners request
     * thousands of paths that do not exist.
     */
    'monitor_404' => [
        'enabled' => true,
        'ignore' => [],
        'per_ip_per_minute' => 30,
        'max_rows' => 10_000,
        'retention_days' => 30,
    ],

    /* ── Widgets ─────────────────────────────────────────────────────────── */

    /*
     * The widget runtime is always loaded from XerAds, never copied into this
     * site, so new widget types and fixes arrive without a package update.
     * Null uses the address from the dashboard settings.
     */
    'widgets' => [
        // Null means https://widgets.xerads.id.
        'runtime_url' => env('XERADS_WIDGETS_URL'),
        // Null means {runtime_url}/v1/loader.js.
        'loader_url' => null,
        'fetch_documents' => true,
        'document_ttl' => 60,
        'fallback_height' => 1000,
        'inject_loader' => true,

        /*
         * Paths (and route names) the loader is never added to, matched like
         * `$request->is()`. Back-office pages show stored bodies as text in
         * forms and previews, and have no use for a third-party script.
         */
        'inject_except' => ['admin', 'admin/*'],
    ],

    /* ── Plumbing ────────────────────────────────────────────────────────── */

    /*
     * Keeping this site's copy of its settings and redirects current.
     *
     * With `scheduler` on, `xerads:sync` runs on `schedule` (a refresh, once
     * the copy is stale) and a heartbeat on `heartbeat_schedule`, through the
     * site's own scheduler. Left null, they run every 15 minutes and hourly,
     * at minutes picked from the site id, so that sites do not all call
     * XerAds in the same minute; a cron expression replaces that.
     *
     * A site without cron still refreshes after a response once the copy is
     * older than `stale_after_minutes` (`after_response`). That stays off
     * while the site's own test suite runs, unless `after_response_in_tests`.
     * After a failure the next attempt waits `backoff` seconds, further for
     * each failure in a row.
     */
    'sync' => [
        'scheduler' => true,
        'schedule' => null,
        'heartbeat_schedule' => null,
        'after_response' => true,
        'after_response_in_tests' => false,
        'stale_after_minutes' => 15,
        'backoff' => [60, 300, 900, 3600, 21600],
    ],

    /*
     * Outbound requests (widget documents, article images, IndexNow and the
     * XerAds API) refuse private and reserved addresses. The DNS half of that
     * check can be turned off where lookups are not possible, such as an
     * offline test run; the address rules still apply.
     */
    'http' => [
        'verify_public_dns' => env('XERADS_VERIFY_PUBLIC_DNS', true),
    ],

    /*
     * Where the package's jobs (copying article images, IndexNow
     * submissions) are queued. Null uses the application's defaults. With the
     * `sync` driver there is no worker, and they run after the response
     * instead.
     */
    'queue' => [
        'connection' => null,
        'queue' => null,
    ],

    'cache' => [
        'store' => null,
        'prefix' => 'xerads',
    ],

    /*
     * Where the package's tables live. `morph_key_type` must match the primary
     * keys of the models that carry SEO data: int, uuid, ulid or string. It is
     * read when the migration runs, so set it before migrating.
     */
    'database' => [
        'connection' => null,
        'table_prefix' => 'xerads_',
        'morph_key_type' => 'int',
    ],

    /*
     * Redirects, the package's SEO files, the 404 monitor and the
     * X-Robots-Tag header run as global middleware. `global` off turns them
     * all off, for a site that wires them into its own stack; each can be
     * turned off on its own too.
     */
    'middleware' => [
        'global' => true,
        'redirects' => true,
        // robots.txt, the sitemaps, llms.txt and the IndexNow key, served
        // past a catch-all route of the site's (`/{slug}`, an SPA's `/{any}`).
        'seo_files' => true,
        'not_found' => true,
        'robots_header' => true,
    ],

    /* `xerads:prune` daily (old 404 paths, delivery records, expired cache). */
    'prune' => [
        'scheduled' => true,
    ],
];
