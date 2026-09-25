<?php

namespace XerAds\CmsBridge\Receivers;

use Illuminate\Database\Eloquent\Model;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;

/**
 * The ordinary case: write into an existing Eloquent model, from config alone.
 *
 * Every column is named in `config/xerads-cms.php` rather than assumed, because
 * the sites this installs into already have a posts table and none of them
 * agree on what the columns are called. A field mapped to null is simply not
 * written, so a site with no meta-description column does not need a migration
 * to accept articles.
 */
class EloquentArticleReceiver implements ArticleReceiver
{
    public function receive(IncomingArticle $article): array
    {
        $class = (string) config('xerads-cms.model');

        if ($class === '' || ! class_exists($class)) {
            throw new \RuntimeException(
                'xerads-cms.model is not set to an existing model class. Point it at your post model, or bind your own ArticleReceiver.'
            );
        }

        /** @var Model $model */
        $model = new $class;
        $map = (array) config('xerads-cms.fields', []);

        $values = array_filter([
            $map['title'] ?? null => $article->title,
            $map['seo_title'] ?? null => $article->seoTitle,
            $map['content'] ?? null => $article->content,
            $map['slug'] ?? null => $article->slug,
            $map['meta_description'] ?? null => $article->metaDescription,
            $map['image_url'] ?? null => $article->imageUrl,
            $map['status'] ?? null => $this->mapStatus($article->status),
        ], fn ($_, $column) => $column !== null && $column !== '', ARRAY_FILTER_USE_BOTH);

        /*
         * Keyed on the id THIS site issued, not on the slug.
         *
         * A slug can be edited on either side, and the moment it diverges a
         * slug-keyed upsert stops finding the row and starts creating
         * duplicates. The primary key does not drift.
         */
        $record = $article->remoteId !== null
            ? $model->newQuery()->find($article->remoteId)
            : null;

        if ($record === null && ($unique = (string) ($map['slug'] ?? '')) !== '') {
            // First sync of an article this site may already have imported by
            // hand. Matching on slug once, here, is what stops that becoming a
            // duplicate; afterwards the id takes over.
            $record = $model->newQuery()->where($unique, $article->slug)->first();
        }

        if ($record === null) {
            $record = $model->newInstance();
        }

        $record->forceFill($values)->save();

        return [
            'id' => $record->getKey(),
            'url' => $this->publicUrl($record, $article),
        ];
    }

    /**
     * Translate XerAds' two statuses into whatever this site calls them.
     *
     * 'draft' and 'publish' are the only two XerAds sends; a site using
     * booleans, integers or different words maps them in config rather than
     * receiving a string its own scopes do not recognise.
     */
    private function mapStatus(string $status): mixed
    {
        $map = (array) config('xerads-cms.status_map', []);

        return $map[$status] ?? $status;
    }

    /**
     * Where the article can now be read.
     *
     * A route name is the usual answer, since that is what a Laravel site
     * already has. Returning null is legitimate — XerAds records no permalink
     * and links nowhere, rather than guessing a URL that 404s.
     */
    private function publicUrl(Model $record, IncomingArticle $article): ?string
    {
        $route = (string) config('xerads-cms.public_route', '');

        if ($route === '' || ! app('router')->has($route)) {
            return null;
        }

        $parameter = (string) config('xerads-cms.public_route_parameter', 'slug');
        $value = $parameter === 'slug' ? $article->slug : $record->getKey();

        try {
            return route($route, [$parameter => $value]);
        } catch (\Throwable) {
            // A route that needs parameters this cannot supply is a config
            // mistake, not a reason to fail a publish that already happened.
            return null;
        }
    }
}
