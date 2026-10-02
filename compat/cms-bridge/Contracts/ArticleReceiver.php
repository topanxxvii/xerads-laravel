<?php

namespace XerAds\CmsBridge\Contracts;

use XerAds\CmsBridge\Data\IncomingArticle;

/**
 * What a site does with an article XerAds sends to the legacy endpoint.
 *
 * An interface rather than a fixed table, because this package installs into a
 * site that already has a Post model, its own columns, its own slug rules and
 * its own idea of what "published" means.
 *
 * `EloquentArticleReceiver` covers the ordinary case from config alone; a site
 * with anything unusual binds its own implementation and keeps the signature
 * verification, the replay protection, the test handling and the response
 * contract for free. Implement
 * `XerAds\Laravel\Content\Contracts\ValidatesConfiguration` as well and the
 * connection test in XerAds checks your receiver's setup too.
 */
interface ArticleReceiver
{
    /**
     * Store or update, and say where it landed.
     *
     * `id` comes back on the next sync as `cms_post_id`, so returning a stable
     * one is what makes a re-sync an edit. `url` is what XerAds links "View
     * live" at; return it only once the post is public. null is allowed for
     * either and simply gives that up.
     *
     * @return array{id: string|int|null, url: string|null}
     */
    public function receive(IncomingArticle $article): array;
}
