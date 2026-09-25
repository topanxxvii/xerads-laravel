<?php

namespace XerAds\CmsBridge\Contracts;

use XerAds\CmsBridge\Data\IncomingArticle;

/**
 * What a site does with an article XerAds sends.
 *
 * An interface rather than a fixed table, because this package installs into a
 * site that already has a Post model, its own columns, its own slug rules and
 * its own idea of what "published" means. Shipping a migration would mean
 * either a second articles table nobody wanted, or a schema argument with
 * every client.
 *
 * `EloquentArticleReceiver` covers the ordinary case from config alone; a site
 * with anything unusual binds its own implementation and keeps the signature
 * verification, the test handling and the response contract for free.
 */
interface ArticleReceiver
{
    /**
     * Store or update, and say where it landed.
     *
     * @return array{id: string|int, url: string|null}  `id` comes back on the
     *         next sync as `cms_post_id`, so returning a stable one is what
     *         makes a re-sync an edit. `url` is what XerAds links "View live"
     *         at and submits to Google Indexing; null is allowed and simply
     *         gives those up.
     */
    public function receive(IncomingArticle $article): array;
}
