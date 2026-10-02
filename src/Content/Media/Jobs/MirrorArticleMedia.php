<?php

namespace XerAds\Laravel\Content\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;
use XerAds\Laravel\Content\ArticleBodies;
use XerAds\Laravel\Content\Media\MediaMirror;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Content\Models\Media;
use XerAds\Laravel\Content\Receivers\MappedModel;
use XerAds\Laravel\Seo\SeoMetaWriter;

/**
 * Copies an article's images to this site, then points the stored article at
 * the copies: the featured image and every `<img src>` in the body, in either
 * mode (the mapped image and content columns, `content.mapped.fields`).
 *
 * Queued on `xerads.queue.connection` and `xerads.queue.queue`, so a bulk
 * sync never ties up web workers with downloads. Where the queue is `sync`
 * (no worker), it runs after the webhook's response instead, so XerAds never
 * waits on it. Until it has run, pages show XerAds' addresses, which still
 * work.
 *
 * Works from the article as it is stored when the job runs, not as it was
 * delivered, and writes only the addresses it copied, under a row lock: an
 * edit that arrives meanwhile is neither lost nor overwritten. An image that
 * cannot be copied is logged and keeps its original address. The job never
 * throws: a failed copy is not worth retrying ahead of the next delivery.
 */
final class MirrorArticleMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A copy that failed is logged and left; the next delivery tries again. */
    public int $tries = 1;

    public function __construct(public readonly string $xeradsId) {}

    /**
     * Queue it, or run it after the response where there is no worker.
     */
    public static function dispatchFor(string $xeradsId, Repository $config): void
    {
        $pending = self::dispatch($xeradsId);
        $connection = $config->get('xerads.queue.connection');
        $queue = $config->get('xerads.queue.queue');

        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $pending->onQueue($queue);
        }

        $connection = is_string($connection) && $connection !== '' ? $connection : $config->get('queue.default');
        $driver = is_string($connection) ? $config->get("queue.connections.{$connection}.driver", $connection) : 'sync';

        if ($driver === 'sync') {
            $pending->afterResponse();
        } else {
            // Not before the article it reads is committed.
            $pending->afterCommit();
        }
    }

    public function handle(MediaMirror $mirror, MappedModel $mapped, ArticleBodies $bodies, SeoMetaWriter $seo): void
    {
        try {
            $this->mirror($mirror, $mapped, $bodies, $seo);
        } catch (Throwable $exception) {
            Log::warning('XerAds could not copy an article\'s images to this site; the page keeps showing the original addresses.', [
                'xerads_id' => $this->xeradsId,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }

    private function mirror(MediaMirror $mirror, MappedModel $mapped, ArticleBodies $bodies, SeoMetaWriter $seo): void
    {
        if (! $mirror->enabled()) {
            return;
        }

        $entry = ContentMapEntry::forArticle($this->xeradsId);
        $record = $entry !== null ? $this->record($entry) : null;

        if ($record === null) {
            return;
        }

        [$imageColumn, $bodyColumns] = $this->columns($record, $mapped);
        $copies = $this->copy($mirror, $record, $imageColumn, $bodyColumns);

        if ($copies === []) {
            return;
        }

        $replacements = array_map(fn (Media $media) => $media->url(), $copies);

        $record->getConnection()->transaction(function () use ($record, $mirror, $imageColumn, $bodyColumns, $replacements, $copies) {
            $fresh = $record->newQueryWithoutScopes()->lockForUpdate()->find($record->getKey());

            if (! $fresh instanceof Model) {
                return;
            }

            $image = $imageColumn !== null ? $fresh->getAttribute($imageColumn) : null;

            if (is_string($image) && isset($replacements[$image])) {
                $fresh->setAttribute($imageColumn, $replacements[$image]);

                if ($fresh instanceof Article) {
                    $fresh->featured_image_path = $copies[$image]->path;
                }
            }

            foreach ($bodyColumns as $column) {
                $body = $fresh->getAttribute($column);

                if (is_string($body)) {
                    $fresh->setAttribute($column, $mirror->rewrite($body, $replacements));
                }
            }

            if ($fresh->isDirty()) {
                $fresh->save();
            }
        });

        if ($record instanceof Article) {
            $bodies->forget($record);
        }

        $this->pointSeoImageAtCopy($record, $seo, $replacements);
    }

    /**
     * The row the article became: a turnkey article, or the site's own
     * model for a mapped one.
     */
    private function record(ContentMapEntry $entry): ?Model
    {
        if ($entry->model_type === null || $entry->model_id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($entry->model_type) ?? $entry->model_type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;
        $record = $model->newQueryWithoutScopes()->find($entry->model_id);

        return $record instanceof Model ? $record : null;
    }

    /**
     * Which columns hold the featured image's address and the body.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function columns(Model $record, MappedModel $mapped): array
    {
        if ($record instanceof Article) {
            return ['featured_image_url', ['body_source', 'body_html']];
        }

        $fields = $mapped->fields();

        return [$fields['image_url'] ?? null, isset($fields['content']) ? [$fields['content']] : []];
    }

    /**
     * Copy every image the row shows, as many as the per-article limit.
     *
     * @param  list<string>  $bodyColumns
     * @return array<string, Media> by original address
     */
    private function copy(MediaMirror $mirror, Model $record, ?string $imageColumn, array $bodyColumns): array
    {
        $urls = [];
        $image = $imageColumn !== null ? $record->getAttribute($imageColumn) : null;

        if (is_string($image) && $mirror->copyable(trim($image))) {
            $urls[] = trim($image);
        }

        foreach ($bodyColumns as $column) {
            $body = $record->getAttribute($column);

            if (is_string($body)) {
                $urls = [...$urls, ...$mirror->sources($body)];
            }
        }

        $copies = [];
        $alt = $record instanceof Article ? $record->featured_image_alt : null;

        foreach (array_slice(array_values(array_unique($urls)), 0, MediaMirror::MAX_IMAGES_PER_ARTICLE) as $url) {
            try {
                $copies[$url] = $mirror->mirror($url, $url === $image ? $alt : null);
            } catch (Throwable $exception) {
                Log::warning('XerAds could not copy an image to this site; the page keeps showing the original address.', [
                    'xerads_id' => $this->xeradsId,
                    'url' => $url,
                    'error' => mb_substr($exception->getMessage(), 0, 300),
                ]);
            }
        }

        return $copies;
    }

    /**
     * The image recorded with the SEO data (what share previews will use)
     * points at the copy too, unless the site locked it.
     *
     * @param  array<string, string>  $replacements
     */
    private function pointSeoImageAtCopy(Model $record, SeoMetaWriter $seo, array $replacements): void
    {
        $meta = $seo->query($record)->first();
        $url = $meta?->extra('image.url');

        if ($meta === null || $meta->isLocked('extras') || ! is_string($url) || ! isset($replacements[$url])) {
            return;
        }

        $extras = $meta->extras ?? [];
        data_set($extras, 'image.url', $replacements[$url]);
        $meta->extras = $extras;
        $meta->save();
    }
}
