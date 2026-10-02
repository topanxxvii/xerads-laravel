<?php

namespace XerAds\Laravel\Content\Receivers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use XerAds\Laravel\Content\ConfigurationReport;
use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\ArticleReference;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;
use XerAds\Laravel\Content\Exceptions\ReceiverMisconfigured;
use XerAds\Laravel\Seo\Models\SeoMeta;

/**
 * Paired deliveries, written into the site's own post model.
 *
 * Mapping, defaults, slugs and links follow `xerads.content.mapped`, like the
 * original endpoint's receiver. What paired deliveries add:
 *
 * - The row is found through the content map first, the row this article
 *   became last time, then by the id XerAds echoes (`remote_id`, for articles
 *   first sent through the original endpoint), and by slug only when
 *   `match_existing_by_slug` asks for it. A paired retry carries its delivery
 *   id and article id, so it never needs the slug to find its row.
 * - SEO data goes to `xerads_seo_meta`, so the post table needs no SEO
 *   columns.
 * - An unchanged revision updates the status and nothing else, leaving any
 *   edit made on the site in place.
 * - Once published, the slug stays (`content.slug.freeze_after_publish`): a
 *   retitled article keeps its address.
 */
final class EloquentMappedReceiver implements ContentReceiver
{
    /** Field keys in the order they are written; a later one wins a shared column. */
    private const FIELDS = [
        'title', 'seo_title', 'headline', 'content', 'slug', 'meta_description', 'image_url', 'status', 'keywords', 'excerpt',
    ];

    public function __construct(private readonly MappedModel $mapped) {}

    public function receive(ArticlePayload $article, DeliveryContext $context): Receipt
    {
        $model = $this->mapped->newModel();
        $fields = $this->mapped->fields();

        $record = $this->locate($model, $context, $article->remoteId, $fields, $article->slug);
        $creating = $record === null;
        $record ??= $model->newInstance();

        if ($context->statusOnly && ! $creating) {
            $values = isset($fields['status']) ? [$fields['status'] => $this->mapped->statusValue($article->status)] : [];
        } else {
            $values = $this->values($record, $article, $context, $fields, $creating);

            if ($creating) {
                // Defaults first, so a mapped value wins a shared column.
                $values = array_merge($this->mapped->defaults(), $values);
            }
        }

        $this->mapped->save($record, $values);

        if ($creating || ! $context->statusOnly) {
            $this->storeSeo($record, $article, $context);
        }

        $state = $article->isPublished() ? Receipt::PUBLISHED : Receipt::DRAFT;

        return new Receipt(
            id: $record->getKey(),
            state: $state,
            url: $state === Receipt::PUBLISHED ? $this->mapped->publicUrl($record, $fields, $article->slug) : null,
            created: $creating,
            model: $record,
        );
    }

    public function unpublish(ArticleReference $article, DeliveryContext $context): Receipt
    {
        return $this->takeOffline($article, $context, Receipt::UNPUBLISHED);
    }

    /**
     * Set the row to draft. A row the site already trashed is left in the
     * trash: it is already off the site, and saving it would restore it,
     * undoing the editor on a request meant to remove it.
     */
    private function takeOffline(ArticleReference $article, DeliveryContext $context, string $stateWhenTrashed): Receipt
    {
        $fields = $this->mapped->fields();
        $record = $this->locate($this->mapped->newModel(), $context, $article->remoteId, $fields);

        if ($record === null) {
            return Receipt::unknown();
        }

        if ($this->mapped->isTrashed($record)) {
            return new Receipt($record->getKey(), $stateWhenTrashed, model: $record);
        }

        if (! isset($fields['status'])) {
            throw new ReceiverMisconfigured(
                'No status column is mapped (xerads.content.mapped.fields.status), so this site cannot take an article offline. Map one, or set xerads.content.mapped.on_delete to delete.',
                'status',
            );
        }

        $this->mapped->save($record, [$fields['status'] => $this->mapped->statusValue(ArticlePayload::STATUS_DRAFT)], restoreTrashed: false);

        return new Receipt($record->getKey(), Receipt::UNPUBLISHED, model: $record);
    }

    /**
     * `content.mapped.on_delete`: `unpublish` (the default) keeps the row as
     * a draft, so nothing written on the site is lost; `delete` deletes it,
     * through the model, so its own soft-delete and events apply.
     */
    public function delete(ArticleReference $article, DeliveryContext $context): Receipt
    {
        if (config('xerads.content.mapped.on_delete', 'unpublish') !== 'delete') {
            return $this->takeOffline($article, $context, Receipt::DELETED);
        }

        $record = $this->locate($this->mapped->newModel(), $context, $article->remoteId, $this->mapped->fields());

        if ($record === null) {
            return Receipt::unknown();
        }

        $id = $record->getKey();

        if ($this->mapped->isTrashed($record)) {
            return new Receipt($id, Receipt::DELETED, model: $record);
        }

        try {
            $record->getConnection()->transaction(function () use ($record) {
                // A soft-deleted row may come back; keep its SEO data for then.
                if (! method_exists($record, 'trashed')) {
                    $this->seoQuery($record)->delete();
                }

                $record->delete();
            });
        } catch (QueryException $exception) {
            throw $this->mapped->translate($exception, $record);
        }

        return new Receipt($id, Receipt::DELETED, model: $record);
    }

    public function validateConfiguration(): ConfigurationReport
    {
        $report = $this->mapped->validate();

        if ($report->isValid() && ! isset($this->mapped->fields()['status'])) {
            return new ConfigurationReport($report->problems, [
                ...$report->warnings,
                'No status column is mapped, so XerAds cannot take an article offline again once it is published.',
            ]);
        }

        return $report;
    }

    /**
     * The row this article became, if it still exists.
     *
     * @param  array<string, string>  $fields
     */
    private function locate(Model $model, DeliveryContext $context, ?string $remoteId, array $fields, ?string $slug = null): ?Model
    {
        $previous = $context->previous;

        if ($previous !== null && $previous->model_id !== null && $previous->model_type === $model->getMorphClass()) {
            $record = $this->mapped->find($model, $previous->model_id);

            if ($record !== null) {
                return $record;
            }
        }

        /*
         * The id XerAds echoes is this site's id in the model it was issued
         * for. It is only used where that is this model: with no map entry (an
         * article first sent through the original endpoint), an entry that
         * never named a model (a tombstone), or one for this model. After the
         * site switches its content model, id 42 of the new table is some
         * other row, and the article is treated as new instead.
         */
        if ($previous === null || $previous->model_type === null || $previous->model_type === $model->getMorphClass()) {
            $record = $this->mapped->find($model, $remoteId ?? $previous?->remote_id);

            if ($record !== null) {
                return $record;
            }
        }

        if ($slug !== null && isset($fields['slug']) && (bool) config('xerads.content.mapped.match_existing_by_slug', false)) {
            return $this->mapped->findBySlug($model, $fields['slug'], $slug);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function values(Model $record, ArticlePayload $article, DeliveryContext $context, array $fields, bool $creating): array
    {
        $content = $context->content;
        $body = $content?->forFormat((string) config('xerads.content.mapped.content_format', 'html')) ?? $article->html;

        $incoming = [
            'title' => $article->title,
            'seo_title' => $article->seoTitle(),
            'headline' => $article->headline ?? $article->title,
            'content' => $body,
            'slug' => $article->slug !== '' ? $article->slug : Str::slug($article->title),
            'meta_description' => $article->seoDescription(),
            'image_url' => $article->imageUrl(),
            'status' => $this->mapped->statusValue($article->status),
            'keywords' => $article->keywords(),
            'excerpt' => $content !== null ? $content->excerpt : $article->excerpt,
        ];

        $values = [];

        foreach (self::FIELDS as $field) {
            $column = $fields[$field] ?? null;

            if ($column === null) {
                continue;
            }

            $value = $incoming[$field];

            // A delivery without an image or an excerpt is not a request to
            // delete the one the post has.
            if (($field === 'image_url' || $field === 'excerpt') && $value === null) {
                continue;
            }

            if ($field === 'slug' && ! $creating && $this->slugIsFrozen($record, $column, $article, $context, $fields)) {
                continue;
            }

            $values[$column] = match ($field) {
                'keywords' => $this->mapped->keywordsValue($record, $column, $article->keywords()),
                'slug' => $this->mapped->availableSlug($record, $column, (string) $value),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * Has this post ever been public under its current slug? Then the slug is
     * a promise to readers and search engines, and a retitled article keeps
     * it, through any unpublish, draft or republish in between.
     *
     * Any one sign is enough: the content map recorded a publish, XerAds
     * says the article was published on this site, or the row is published
     * right now (a post adopted from the original endpoint, which the map has
     * no history for).
     *
     * @param  array<string, string>  $fields
     */
    private function slugIsFrozen(Model $record, string $column, ArticlePayload $article, DeliveryContext $context, array $fields): bool
    {
        $current = $record->getAttribute($column);

        if (! config('xerads.content.slug.freeze_after_publish', true) || ! is_string($current) || $current === '') {
            return false;
        }

        if ($context->previous?->wasEverPublished() === true || $article->dates['published_at'] !== null) {
            return true;
        }

        $status = isset($fields['status']) ? $record->getAttribute($fields['status']) : null;
        $published = $this->mapped->statusValue(ArticlePayload::STATUS_PUBLISH);

        return is_scalar($status) && is_scalar($published) && (string) $status === (string) $published;
    }

    private function storeSeo(Model $record, ArticlePayload $article, DeliveryContext $context): void
    {
        $content = $context->content;

        $values = [
            'title' => $article->seoTitle(),
            'description' => $article->seoDescription(),
            'focus_keyword' => $article->seo['focus_keyword'],
            'keywords' => $article->keywords(),
            'canonical_url' => $article->seo['canonical_url'],
            'robots' => $article->seo['robots'] !== [] ? $article->seo['robots'] : null,
            'og' => $article->seo['og'] !== [] ? $article->seo['og'] : null,
            'twitter' => $article->seo['twitter'] !== [] ? $article->seo['twitter'] : null,
            'schema' => ['type' => $article->seo['schema_type']],
            'extras' => [
                'xerads_id' => $article->xeradsId,
                'language' => $article->language,
                'headline' => $article->headline,
                'excerpt' => $content !== null ? $content->excerpt : $article->excerpt,
                'toc' => $content !== null ? $content->toc : $article->toc,
                'word_count' => $content !== null ? $content->wordCount : $article->wordCount,
                'reading_time_minutes' => $content !== null ? $content->readingTimeMinutes : $article->readingTimeMinutes,
                'image' => $article->image,
                'widgets' => $article->widgets,
                'score' => $article->seo['score'],
                'dates' => $article->dates,
            ],
        ];

        try {
            /** @var SeoMeta $meta */
            $meta = $this->seoQuery($record)->firstOrNew([]);

            foreach (array_keys($values) as $field) {
                if ($meta->exists && $meta->isLocked($field)) {
                    unset($values[$field]);
                }
            }

            $meta->forceFill($values + [
                'seoable_type' => $record->getMorphClass(),
                'seoable_id' => $record->getKey(),
                'source' => 'xerads',
            ])->save();
        } catch (QueryException $exception) {
            // Only a key of the wrong type is the site's setup; anything else
            // (a race, a lost connection) is retried like any failure.
            if (MappedModel::isTypeMismatch($exception->getMessage())) {
                throw new ReceiverMisconfigured(
                    'SEO data for '.$record::class.' could not be stored: its primary key is not the type xerads_seo_meta expects. Set xerads.database.morph_key_type (int, uuid, ulid or string) to match, before migrating.',
                    'seoable_id',
                    $exception,
                );
            }

            throw $this->mapped->translate($exception, $record);
        }
    }

    /** @return Builder<SeoMeta> */
    private function seoQuery(Model $record): Builder
    {
        return SeoMeta::query()
            ->where('seoable_type', $record->getMorphClass())
            ->where('seoable_id', $record->getKey());
    }
}
