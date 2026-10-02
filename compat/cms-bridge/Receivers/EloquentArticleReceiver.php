<?php

namespace XerAds\CmsBridge\Receivers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;
use XerAds\Laravel\Content\ConfigurationReport;
use XerAds\Laravel\Content\Receivers\MappedModel;

/**
 * The ordinary case: write into an existing Eloquent model, from config alone.
 *
 * Every column is named in `xerads.content.mapped` rather than assumed,
 * because the sites this installs into already have a posts table and none of
 * them agree on what the columns are called. A field mapped to null is simply
 * not written, so a site with no meta-description column does not need a
 * migration to accept articles.
 *
 * On the first delivery of an article it adopts an existing row with the same
 * slug (`xerads.legacy.match_existing_by_slug`, on by default). That is how a
 * XerAds retry after a timeout — which carries no id yet, and a fresh
 * timestamp — updates the post the first attempt created instead of adding a
 * second one. XerAds gives every legacy slug a random suffix, so a match with
 * an unrelated hand-written post is not a realistic risk; a site that still
 * wants it off sets the key to false, and taken slugs then get a `-2` suffix.
 *
 * What it will not do, on purpose:
 * - erase a post's image because a delivery arrived without one;
 * - leave a post in the trash when XerAds sends it again: a re-send is an
 *   explicit request to publish, so a soft-deleted row is restored;
 * - report a public URL for a post that is not published, or one built from
 *   a slug other than the one actually saved.
 *
 * Its helpers are private, so a subclass written against the original
 * receiver keeps its own method names. `validateConfiguration()` runs on
 * "Test connection" for this class itself; a subclass opts in by
 * implementing ValidatesConfiguration.
 */
class EloquentArticleReceiver implements ArticleReceiver
{
    /** Field keys in the order they are written; a later one wins a shared column. */
    private const FIELDS = [
        'title', 'seo_title', 'content', 'slug', 'meta_description', 'image_url', 'status', 'keywords', 'excerpt',
    ];

    /**
     * Not a constructor dependency, so a subclass with its own constructor
     * written against the original receiver keeps working.
     */
    private ?MappedModel $xeradsMappedModel = null;

    public function receive(IncomingArticle $article): array
    {
        $mapped = $this->mappedModel();
        $model = $mapped->newModel();
        $fields = $mapped->fields();

        $record = $this->findExisting($model, $article, $fields);
        $creating = $record === null;
        $record ??= $model->newInstance();

        $values = $this->values($record, $article, $fields);

        if ($creating) {
            /*
             * Defaults first, so a mapped value wins a shared column. They
             * exist for NOT NULL columns XerAds knows nothing about — an
             * author id, a category — which would otherwise fail the insert.
             */
            $values = array_merge($mapped->defaults(), $values);
        }

        // In a transaction, so a failed insert leaves nothing half written
        // and, on PostgreSQL, does not poison a transaction the caller holds.
        $mapped->save($record, $values);

        return [
            'id' => $record->getKey(),
            // Only once it is public. XerAds keeps the link it already had
            // rather than recording one that 404s.
            'url' => $article->isPublished() ? $mapped->publicUrl($record, $fields, $article->slug) : null,
        ];
    }

    /**
     * Can an article be stored right now, without storing one?
     *
     * Checks what fails on the first real article: the model class, its
     * table, and every mapped column. Things that would only surprise — a
     * NOT NULL column nothing fills, a public route that does not exist — are
     * warnings, because the model may fill the column itself in an event this
     * cannot see.
     */
    public function validateConfiguration(): ConfigurationReport
    {
        return $this->mappedModel()->validate();
    }

    /**
     * The row this article updates, if any.
     *
     * Keyed on the id THIS site issued, not on the slug. A slug can be edited
     * on either side, and the moment it diverges a slug-keyed upsert stops
     * finding the row and starts creating duplicates. The primary key does
     * not drift.
     *
     * @param  array<string, string>  $fields
     */
    private function findExisting(Model $model, IncomingArticle $article, array $fields): ?Model
    {
        $mapped = $this->mappedModel();
        $record = $mapped->find($model, $article->remoteId);

        if ($record === null && isset($fields['slug']) && (bool) config('xerads.legacy.match_existing_by_slug', true)) {
            // A first delivery, or a retry of one that timed out after this
            // site had stored it: the id has not reached XerAds yet, so the
            // slug is the only link back to the row.
            $record = $mapped->findBySlug($model, $fields['slug'], $article->slug);
        }

        return $record;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function values(Model $record, IncomingArticle $article, array $fields): array
    {
        $mapped = $this->mappedModel();

        $incoming = [
            'title' => $article->title,
            'seo_title' => $article->seoTitle,
            'content' => $article->content,
            'slug' => $article->slug !== '' ? $article->slug : Str::slug($article->title),
            'meta_description' => $article->metaDescription,
            'image_url' => $article->imageUrl,
            'status' => $mapped->statusValue($article->status),
            'keywords' => $article->keywords,
            'excerpt' => $article->excerpt,
        ];

        $values = [];

        foreach (self::FIELDS as $field) {
            $column = $fields[$field] ?? null;

            if ($column === null) {
                continue;
            }

            $value = $incoming[$field];

            // A delivery without an image (or, from this endpoint, without an
            // excerpt) is not a request to delete the one the post has.
            if (($field === 'image_url' || $field === 'excerpt') && $value === null) {
                continue;
            }

            $values[$column] = match ($field) {
                'keywords' => $mapped->keywordsValue($record, $column, $article->keywords),
                'slug' => $mapped->availableSlug($record, $column, (string) $value),
                default => $value,
            };
        }

        return $values;
    }

    private function mappedModel(): MappedModel
    {
        return $this->xeradsMappedModel ??= app(MappedModel::class);
    }
}
