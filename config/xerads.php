<?php

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
         * A published URL is a promise to readers and search engines. Once an
         * article has been public its slug stays, even when the title is
         * edited in XerAds.
         */
        'slug' => [
            'freeze_after_publish' => true,
        ],

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

            /** html, or markdown for a site whose body column holds Markdown. */
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
     * breaks the day someone presses "regenerate".
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
     * `overrides` wins over them, in the same shape, for values this site
     * wants pinned in code. Non-production environments are noindexed so a
     * staging copy never competes with the live site.
     */
    'seo' => [
        'remote' => true,
        'overrides' => [],
        'noindex_non_production' => true,
        'canonical_query_allowlist' => ['page'],
        'inertia' => [
            'share' => true,
        ],
    ],

    /** Extra structured-data pieces (class names) added to every page's graph. */
    'schema' => [
        'pieces' => [],
    ],

    /*
     * The sitemap is cached and rebuilt when content changes. A request that
     * fails while building answers 503 rather than a partial file, because a
     * partial sitemap tells search engines the missing URLs are gone.
     */
    'sitemap' => [
        'enabled' => true,
        'max_urls' => 5000,
        'cache_ttl' => 3600,
        'sources' => [],
        'models' => [],
    ],

    'robots_txt' => [
        'enabled' => true,
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
        'runtime_url' => env('XERADS_WIDGETS_URL'),
        'loader_url' => null,
        'fetch_documents' => true,
        'document_ttl' => 60,
        'fallback_height' => 1000,
        'inject_loader' => true,
    ],

    /* ── Plumbing ────────────────────────────────────────────────────────── */

    /*
     * How often settings and redirects are refreshed by the scheduler. A site
     * without cron still refreshes after a response once the copy is older
     * than `stale_after_minutes`.
     */
    'sync' => [
        'schedule' => '*/15 * * * *',
        'stale_after_minutes' => 15,
    ],

    /** Null uses the application's defaults. */
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
     * Redirects, the 404 monitor and robots headers run as global middleware.
     * Off turns all three off, for a site that wires them into its own stack.
     */
    'middleware' => [
        'global' => true,
    ],
];
