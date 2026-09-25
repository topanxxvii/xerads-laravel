<?php

return [
    /*
     * The Secret Token from the XerAds CMS connection, byte for byte.
     *
     * Both the bearer token and the signature use it, because asking for two
     * secrets is how one of them ends up wrong.
     */
    'secret' => env('XERADS_CMS_SECRET', ''),

    /*
     * Where XerAds posts. Paste the full URL into the connection's Endpoint URL
     * field. Set to null to register no route at all — for a site that binds
     * its own controller and wants only the middleware and the receiver.
     */
    'route' => env('XERADS_CMS_ROUTE', '/api/xerads/articles'),

    /** Middleware the route runs behind, besides the signature check. */
    'middleware' => ['api'],

    /*
     * How far the request's timestamp may be from this server's clock, in
     * seconds. Generous enough for drift between two machines, short enough
     * that a captured request goes stale.
     */
    'timestamp_tolerance' => 300,

    /* ── Where articles land ─────────────────────────────────────────────── */

    /** Your post model. */
    'model' => env('XERADS_CMS_MODEL', '\App\Models\Post'),

    /*
     * Column names, because no two sites agree on them. A field mapped to null
     * is not written, so a site without that column needs no migration.
     */
    'fields' => [
        'title' => 'title',
        'seo_title' => null,
        'content' => 'content',
        'slug' => 'slug',
        'meta_description' => 'meta_description',
        'image_url' => null,
        'status' => 'status',
    ],

    /*
     * XerAds sends exactly two statuses. Translate them into whatever this
     * site's own scopes expect — words, booleans or integers.
     */
    'status_map' => [
        'draft' => 'draft',
        'publish' => 'published',
    ],

    /*
     * The named route that shows an article, used to tell XerAds where it can
     * be read. Leave empty and XerAds records no public link rather than
     * guessing a URL that 404s.
     */
    'public_route' => env('XERADS_CMS_PUBLIC_ROUTE', ''),
    'public_route_parameter' => 'slug',
];
